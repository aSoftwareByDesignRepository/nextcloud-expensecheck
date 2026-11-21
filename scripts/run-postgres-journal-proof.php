<?php

declare(strict_types=1);

/**
 * Gate 23 — PostgreSQL golden-vector + concurrency proof.
 *
 * Runs against an ephemeral pg (docker-compose.expensecheck-pg.yml), NOT
 * the live instance. Proves three DB-portability contracts:
 *
 *  1. Journal fidelity — the real ExpChainService hash chain round-trips
 *     through pg byte-exact and lands on the same golden vectors as MySQL.
 *  2. CAS transitions — a conditional UPDATE (status/version compare-and-
 *     swap) lets exactly one of N contenders win on pg, same as InnoDB.
 *  3. Unique dedupe — pg 23505 is caught by SqlErrors (the replay path
 *     that a raw message-grep would miss).
 *
 * Usage (inside the nextcloud container):
 *   php custom_apps/expensecheck/scripts/run-postgres-journal-proof.php
 */

require __DIR__ . '/../lib/Service/ExpChainService.php';
require __DIR__ . '/../lib/Db/SqlErrors.php';

use OCA\ExpenseCheck\Db\SqlErrors;
use OCA\ExpenseCheck\Service\ExpChainService;

function check(bool $cond, string $what): void
{
	if (!$cond) {
		throw new RuntimeException('PROOF FAILED: ' . $what);
	}
}

$dsn = getenv('EXP_PG_DSN')
	?: 'pgsql:host=expensecheck-pg;port=5432;dbname=expensecheck_proof';
$user = getenv('EXP_PG_USER') ?: 'expensecheck';
$pass = getenv('EXP_PG_PASS') ?: 'expensecheck';

$pdo = null;
for ($i = 0; $i < 40; $i++) {
	try {
		$pdo = new PDO($dsn, $user, $pass, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		break;
	} catch (Throwable $e) {
		usleep(250000);
	}
}
if ($pdo === null) {
	throw new RuntimeException('postgres unreachable: ' . $dsn);
}
echo "connected: $dsn\n";

// ---- schema (mirrors Version1000 migration, pg-native types) -----------
$pdo->exec('DROP TABLE IF EXISTS exp_claim_events, exp_claims CASCADE');
$pdo->exec(<<<'SQL'
CREATE TABLE exp_claims (
	id BIGSERIAL PRIMARY KEY,
	claim_no VARCHAR(32) NOT NULL,
	user_id VARCHAR(64) NOT NULL,
	status VARCHAR(24) NOT NULL,
	version INT NOT NULL DEFAULT 1,
	request_key VARCHAR(128) NULL,
	created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
	UNIQUE (claim_no)
)
SQL);
$pdo->exec('CREATE UNIQUE INDEX exp_claims_rk_uidx ON exp_claims (request_key) WHERE request_key IS NOT NULL');
$pdo->exec(<<<'SQL'
CREATE TABLE exp_claim_events (
	id BIGSERIAL PRIMARY KEY,
	claim_id BIGINT NOT NULL,
	seq INT NOT NULL,
	actor VARCHAR(64) NULL,
	event VARCHAR(48) NOT NULL,
	from_status VARCHAR(24) NULL,
	to_status VARCHAR(24) NULL,
	payload TEXT NULL,
	payload_hash VARCHAR(64) NOT NULL,
	prev_hash VARCHAR(64) NOT NULL,
	entry_hash VARCHAR(64) NOT NULL,
	tombstoned SMALLINT NOT NULL DEFAULT 0,
	created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
)
SQL);
$pdo->exec('CREATE UNIQUE INDEX exp_event_seq_uidx ON exp_claim_events (claim_id, seq)');
echo "schema ready\n";

// ---- 1. golden-vector journal chain ------------------------------------
$payload = [
	'claim_id' => 7, 'actor' => 'alice', 'amount_minor' => 12345,
	'currency' => 'EUR', 'nested' => ['b' => 1, 'a' => 'x/y'],
	'unicode' => 'ü', 'float' => 1.5, 'null' => null, 'bool' => true,
];
$expectedPayloadHash = 'edee2b66a60ac5b3fd80db5714545d66e3c169cdfdb61fec0cd67ba64229d1d3';
$expectedEntryHash = '2806d514146d7bea82b3787e90d9bef509eb8f04b12c213656a5f01cefeb2490';
$prev = str_repeat('ab', 32);

$ph = ExpChainService::payloadHashFor($payload);
$eh = ExpChainService::entryHashFor(42, 'alice', 'claim.submitted', 'draft', 'submitted', $ph, $prev);
check($ph === $expectedPayloadHash, 'payload hash must match the frozen golden vector');
check($eh === $expectedEntryHash, 'entry hash must match the frozen golden vector');

$pdo->prepare(
	'INSERT INTO exp_claim_events (claim_id, seq, actor, event, from_status, to_status,
		payload, payload_hash, prev_hash, entry_hash, created_at)
	 VALUES (7, 42, :actor, :event, :fs, :ts, :payload, :ph, :prev, :eh, NOW())'
)->execute([
	'actor' => 'alice', 'event' => 'claim.submitted', 'fs' => 'draft', 'ts' => 'submitted',
	'payload' => ExpChainService::jcs($payload), 'ph' => $ph, 'prev' => $prev, 'eh' => $eh,
]);

$row = $pdo->query('SELECT * FROM exp_claim_events WHERE claim_id = 7 AND seq = 42')->fetch();
check($row['entry_hash'] === $expectedEntryHash, 'pg round-trip must return the identical hash');
check($row['payload'] === ExpChainService::jcs($payload), 'pg TEXT round-trip must be byte-exact (unicode + escaping)');
// Recompute FROM the stored row — the chain verifies on pg exactly as on MySQL.
$recomputed = ExpChainService::entryHashFor(
	(int)$row['seq'], $row['actor'], $row['event'], $row['from_status'], $row['to_status'],
	$row['payload_hash'], $row['prev_hash']);
check($recomputed === $row['entry_hash'], 'stored row must recompute to its own entry_hash');
assert(ExpChainService::payloadHashFor(json_decode($row['payload'], true)) === $row['payload_hash'],
	'stored JCS payload must re-hash identically');
echo "1. journal golden vectors: pg round-trip byte-exact + recompute OK\n";

// ---- 2. CAS: exactly one winner -----------------------------------------
$pdo->exec("INSERT INTO exp_claims (claim_no, user_id, status, version, created_at)
	VALUES ('EXP-PG-1', 'alice', 'submitted', 1, NOW())");

$winners = 0;
$losers = 0;
$tmp = sys_get_temp_dir() . '/exp_pg_cas_' . getmypid();
foreach (glob($tmp . '_*') ?: [] as $f) {
	unlink($f);
}
for ($i = 0; $i < 8; $i++) {
	$pid = pcntl_fork();
	if ($pid === -1) {
		$pid = 0;
	}
	if ($pid === 0) {
		// Child: record the rowCount then SIGKILL — running PDO destructors
		// on the inherited parent socket would kill the parent's connection.
		try {
			$c = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			$st = $c->prepare("UPDATE exp_claims SET status='approved', version=version+1
				WHERE claim_no='EXP-PG-1' AND status='submitted' AND version=1");
			$st->execute();
			file_put_contents("{$tmp}_{$i}", $st->rowCount() === 1 ? 'win' : 'lose');
		} catch (Throwable) {
			file_put_contents("{$tmp}_{$i}", 'lose');
		}
		posix_kill(posix_getpid(), SIGKILL);
	}
}
for ($i = 0; $i < 8; $i++) {
	pcntl_wait($status);
}
for ($i = 0; $i < 8; $i++) {
	$r = @file_get_contents("{$tmp}_{$i}");
	if ($r === 'win') {
		$winners++;
	} else {
		$losers++;
	}
}
check($winners === 1 && $losers === 7, "CAS must yield exactly 1 winner (got $winners)");
$final = $pdo->query("SELECT status, version FROM exp_claims WHERE claim_no='EXP-PG-1'")->fetch();
check($final['status'] === 'approved' && (int)$final['version'] === 2, 'winner must apply exactly one transition');
echo "2. CAS contention: exactly 1 of 8 contenders won, version bumped once\n";

// ---- 3. unique dedupe via SqlErrors -------------------------------------
$pdo->exec("INSERT INTO exp_claims (claim_no, user_id, status, request_key, created_at)
	VALUES ('EXP-PG-2', 'alice', 'draft', 'alice:rk-1', NOW())");
try {
	$pdo->exec("INSERT INTO exp_claims (claim_no, user_id, status, request_key, created_at)
		VALUES ('EXP-PG-3', 'alice', 'draft', 'alice:rk-1', NOW())");
	check(false, 'duplicate request_key must throw');
} catch (Throwable $e) {
	check(SqlErrors::isConcurrencyViolation($e), 'pg 23505 must be recognized for dedupe replay');
	check(SqlErrors::isUniqueViolation($e), 'pg 23505 must be recognized as unique violation');
}
echo "3. unique dedupe: pg 23505 caught by SqlErrors (class+SQLSTATE path)\n";

echo "\nPOSTGRES PROOF PASSED — gate 23 (pg) evidence recorded.\n";
