<?php

declare(strict_types=1);

namespace OCA\ExpenseCheck\Service;

use OCA\ExpenseCheck\AppInfo\Application;
use OCA\ExpenseCheck\ConfigTransfer\TransferCrypto;
use OCA\ExpenseCheck\ConfigTransfer\TransferManifest;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * FS-2 pre-replace safety snapshot (spec §12.6): before a replace-mode
 * config import wipes transferable catalog tables, dump every transferable
 * table row plus every wire-class appconfig value into a server-wrapped
 * JSON file under appdata — enough state for a manual restore, never
 * plaintext on disk.
 */
class TransferSnapshotService
{
	private const SUBDIR = 'transfer-snapshots';
	private const MAX_ROWS_PER_TABLE = 100000;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly IRootFolder $rootFolder,
		private readonly IConfig $config,
		private readonly TransferManifest $manifest,
	) {
	}

	/**
	 * @return string snapshot id (file name inside transfer-snapshots/)
	 */
	public function createSnapshot(string $reason): string
	{
		$tables = [];
		foreach ($this->manifest->transferableTablesSorted() as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from($table)->setMaxResults(self::MAX_ROWS_PER_TABLE + 1);
			$res = $qb->executeQuery();
			$rows = [];
			while (($r = $res->fetch()) !== false && count($rows) < self::MAX_ROWS_PER_TABLE) {
				$rows[] = $r;
			}
			if ($res->fetch() !== false) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_TOO_LARGE');
			}
			$tables[$table] = $rows;
		}
		$keys = [];
		foreach ($this->manifest->keys() as $key => $spec) {
			if (in_array($spec['class'], ['plain', 'mirrored', 'secret'], true)) {
				$keys[$key] = $this->config->getAppValue(Application::APP_ID, $key, '');
			}
		}
		$id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
		$payload = [
			'format' => 'expensecheck-snapshot',
			'version' => 1,
			'reason' => mb_substr($reason, 0, 191),
			'createdAt' => time(),
			'tables' => $tables,
			'config' => $keys,
		];
		$wrapped = TransferCrypto::serverWrap(
			json_encode($payload, JSON_THROW_ON_ERROR),
			TransferCrypto::instanceWrapKey($this->instanceSecret(), Application::APP_ID),
		);
		$this->snapshotRoot()->newFile($id . '.json', $wrapped);
		return $id;
	}

	private function snapshotRoot(): Folder
	{
		$appData = $this->appDataRoot();
		try {
			$node = $appData->get(self::SUBDIR);
		} catch (NotFoundException) {
			return $appData->newFolder(self::SUBDIR);
		}
		if (!$node instanceof Folder) {
			throw new \RuntimeException('TRANSFER_SNAPSHOT_NO_DIR');
		}
		return $node;
	}

	private function appDataRoot(): Folder
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			throw new \RuntimeException('TRANSFER_SNAPSHOT_NO_APPDATA');
		}
		try {
			$node = $this->rootFolder->get('appdata_' . $instanceId . '/' . Application::APP_ID);
		} catch (NotFoundException) {
			$instance = $this->rootFolder->get('appdata_' . $instanceId);
			if (!$instance instanceof Folder) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_NO_APPDATA');
			}
			$node = $instance->newFolder(Application::APP_ID);
		}
		if (!$node instanceof Folder) {
			throw new \RuntimeException('TRANSFER_SNAPSHOT_NO_APPDATA');
		}
		return $node;
	}

	private function instanceSecret(): string
	{
		$secret = (string)$this->config->getSystemValue('secret', '');
		if ($secret === '') {
			throw new \RuntimeException('TRANSFER_SNAPSHOT_NO_SECRET');
		}
		return $secret;
	}
}
