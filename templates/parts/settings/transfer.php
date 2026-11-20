<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="exp-card exp-section" aria-labelledby="exp-xfer-export-h" id="exp-transfer-export-card">
	<header class="exp-section__header">
		<div>
			<h2 id="exp-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="exp-section__sub"><?php p(!empty($_['transferExportBlurb']) ? (string)$_['transferExportBlurb'] : $l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="exp-callout exp-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="exp-transfer-export-form" class="exp-form" novalidate>
		<div class="exp-form-row">
			<label for="exp-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="exp-input-group">
				<input type="password" id="exp-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="exp-transfer-passphrase-hint exp-transfer-passphrase-strength">
				<button type="button" class="exp-btn exp-btn--ghost" id="exp-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="exp-btn exp-btn--secondary" id="exp-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="exp-form-row__hint" id="exp-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="exp-form-row__hint exp-transfer-strength" id="exp-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="exp-actions">
			<button type="submit" class="exp-btn exp-btn--primary" id="exp-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="exp-card exp-section" aria-labelledby="exp-xfer-import-h" id="exp-transfer-import-card">
	<header class="exp-section__header">
		<div>
			<h2 id="exp-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="exp-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="exp-transfer-last" id="exp-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="exp-transfer-import-form" class="exp-form" novalidate>
		<fieldset class="exp-form-row exp-fieldset" aria-describedby="exp-transfer-replace-hint">
			<legend><?php p($l->t('Mode')); ?></legend>
			<div class="exp-choice-cards">
				<label class="exp-choice-card">
					<input type="radio" name="mode" value="merge" checked>
					<span class="exp-choice-card__copy">
						<span class="exp-choice-card__title"><?php p($l->t('Merge')); ?></span>
						<span class="exp-choice-card__hint"><?php p($l->t('add and update; nothing is deleted')); ?></span>
					</span>
				</label>
				<label class="exp-choice-card exp-choice-card--danger">
					<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
					<span class="exp-choice-card__copy">
						<span class="exp-choice-card__title"><?php p($l->t('Replace')); ?></span>
						<span class="exp-choice-card__hint"><?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
					</span>
				</label>
			</div>
			<p class="exp-form-row__hint" id="exp-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="exp-form-row">
			<label for="exp-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="exp-transfer-file" name="file" accept=".nctransfer,application/json" class="exp-file-input">
		</div>
		<div class="exp-form-row">
			<label for="exp-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="exp-input-group">
				<input type="password" id="exp-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="exp-btn exp-btn--ghost" id="exp-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="exp-transfer-import-status" class="exp-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="exp-transfer-report" class="exp-dialog exp-transfer-report" aria-labelledby="exp-transfer-report-h">
	<div class="exp-dialog__body">
		<h3 id="exp-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="exp-transfer-report-verdict" class="exp-transfer-report__verdict"></p>
		<p id="exp-transfer-report-file" class="exp-form-row__hint"></p>
		<div id="exp-transfer-report-groups"></div>
	</div>
	<footer class="exp-dialog__footer">
		<div id="exp-transfer-replace-confirm" class="exp-form-row" hidden>
			<label for="exp-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="exp-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="exp-actions">
			<button type="button" class="exp-btn exp-btn--secondary" id="exp-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="exp-btn exp-btn--primary" id="exp-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="exp-transfer-progress" class="exp-dialog" aria-labelledby="exp-transfer-progress-h" aria-live="polite">
	<div class="exp-dialog__body">
		<h3 id="exp-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="exp-transfer-progress-bar" max="100" value="0"></progress>
		<p id="exp-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="exp-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="exp-transfer-result-live" class="exp-visually-hidden" role="status" aria-live="polite"></div>
