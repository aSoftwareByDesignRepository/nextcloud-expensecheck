<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

/**
 * "AI access (MCP)" settings section — pack partial.
 *
 * Expects $agentAccess from AgentSettingsPayload::payload(). Renders
 * nothing when the user may neither mint nor see tokens (nav-gate, not a
 * disabled state). All IDs/classes use the app prefix (exp).
 *
 * @var \OCP\IL10N $l
 * @var array $agentAccess
 */

$agentAccess = is_array($agentAccess ?? null) ? $agentAccess : [];
if (($agentAccess['visible'] ?? false) !== true) {
	return;
}

$exp_state = [
	'appId' => (string)($agentAccess['appId'] ?? ''),
	'agentHeader' => (string)($agentAccess['agentHeader'] ?? ''),
	'ncUrl' => (string)($agentAccess['ncUrl'] ?? ''),
	'isAdmin' => (bool)($agentAccess['isAdmin'] ?? false),
	'canMint' => (bool)($agentAccess['canMint'] ?? false),
	'selfMintEnabled' => (bool)($agentAccess['selfMintEnabled'] ?? false),
	'tokens' => $agentAccess['tokens'] ?? [],
	'presets' => $agentAccess['presets'] ?? ['read' => [], 'act' => []],
	'allScopes' => $agentAccess['allScopes'] ?? [],
	'scopeLabels' => $agentAccess['scopeLabels'] ?? [],
];
$exp_tokens = is_array($exp_state['tokens']) ? $exp_state['tokens'] : [];
?>
<section class="exp-section exp-agent" id="exp-agent-access" aria-labelledby="exp-agent-h">
	<h2 class="exp-section-title" id="exp-agent-h"><?php p($l->t('AI access (MCP)')); ?></h2>
	<p class="exp-section-intro">
		<?php p($l->t('Connect an AI assistant to this app with a scoped token. The token works only for this app — never for your files, mail, or other apps.')); ?>
	</p>
	<div id="exp-agent-live" class="exp-visually-hidden" aria-live="polite"></div>

	<?php if ($exp_tokens === []): ?>
	<div class="exp-agent-empty" id="exp-agent-empty">
		<p class="exp-agent-empty__text"><?php p($l->t('No AI access yet.')); ?></p>
		<?php if ($exp_state['canMint']): ?>
		<button type="button" class="exp-btn exp-btn--primary" id="exp-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		<?php endif; ?>
	</div>
	<?php else: ?>
	<div class="exp-table-wrap exp-agent-tablewrap" tabindex="0" role="region" aria-labelledby="exp-agent-h">
		<table class="exp-table exp-table--cards exp-agent-table" id="exp-agent-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Label')); ?></th>
					<th scope="col"><?php p($l->t('Scopes')); ?></th>
					<th scope="col"><?php p($l->t('Created')); ?></th>
					<th scope="col"><?php p($l->t('Expires')); ?></th>
					<th scope="col"><?php p($l->t('Last used')); ?></th>
					<th scope="col"><span class="exp-visually-hidden"><?php p($l->t('Actions')); ?></span></th>
				</tr>
			</thead>
			<tbody id="exp-agent-tbody">
				<?php foreach ($exp_tokens as $exp_tok): ?>
				<tr data-token-id="<?php p((string)($exp_tok['id'] ?? '')); ?>" data-token-label="<?php p((string)($exp_tok['label'] ?? '')); ?>"<?php if (($exp_tok['state'] ?? '') !== 'active'): ?> class="exp-agent-row--inactive"<?php endif; ?>>
					<td data-cell="<?php p($l->t('Label')); ?>">
						<span class="exp-agent-label"><?php p((string)($exp_tok['label'] ?? '')); ?></span>
						<?php if (($exp_tok['state'] ?? '') === 'revoked'): ?>
							<span class="exp-badge exp-badge--neutral"><?php p($l->t('Revoked')); ?></span>
						<?php elseif (($exp_tok['state'] ?? '') === 'expired'): ?>
							<span class="exp-badge exp-badge--neutral"><?php p($l->t('Expired')); ?></span>
						<?php else: ?>
							<span class="exp-badge exp-badge--info"><?php p($l->t('Active')); ?></span>
						<?php endif; ?>
						<?php if ($exp_state['isAdmin'] && ($exp_tok['userId'] ?? '') !== ''): ?>
							<span class="exp-agent-owner"><?php p((string)$exp_tok['userId']); ?></span>
						<?php endif; ?>
					</td>
					<td data-cell="<?php p($l->t('Scopes')); ?>">
						<span class="exp-agent-scopes">
						<?php foreach (($exp_tok['scopes'] ?? []) as $exp_scope): ?>
							<span class="exp-badge exp-badge--neutral exp-agent-scope" title="<?php p((string)$exp_scope); ?>"><?php p((string)($exp_state['scopeLabels'][$exp_scope] ?? $exp_scope)); ?></span>
						<?php endforeach; ?>
						</span>
					</td>
					<td data-cell="<?php p($l->t('Created')); ?>" data-ts="<?php p((string)($exp_tok['createdAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Expires')); ?>" data-ts="<?php p((string)($exp_tok['expiresAt'] ?? '')); ?>"></td>
					<td data-cell="<?php p($l->t('Last used')); ?>"<?php if (($exp_tok['lastUsedAt'] ?? null) === null): ?> class="exp-agent-never"<?php endif; ?> data-ts="<?php p((string)($exp_tok['lastUsedAt'] ?? '')); ?>"><?php if (($exp_tok['lastUsedAt'] ?? null) === null): ?><?php p($l->t('never')); ?><?php endif; ?></td>
					<td class="exp-row-actions">
						<?php if (($exp_tok['state'] ?? '') === 'active'): ?>
						<button type="button" class="exp-btn exp-btn--ghost exp-agent-rotate" data-token-id="<?php p((string)$exp_tok['id']); ?>"><?php p($l->t('Rotate')); ?></button>
						<button type="button" class="exp-btn exp-btn--danger exp-agent-revoke" data-token-id="<?php p((string)$exp_tok['id']); ?>" data-token-label="<?php p((string)($exp_tok['label'] ?? '')); ?>"><?php p($l->t('Revoke')); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ($exp_state['canMint']): ?>
		<div class="exp-agent-actions">
			<button type="button" class="exp-btn exp-btn--primary" id="exp-agent-connect"><?php p($l->t('Connect your AI')); ?></button>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($exp_state['isAdmin']): ?>
	<div class="exp-form-row exp-agent-selfmint">
		<input type="hidden" name="agent_self_mint" value="0">
		<label class="exp-checkbox-label" for="exp-agent-selfmint-cb">
			<input type="checkbox" id="exp-agent-selfmint-cb" <?php if ($exp_state['selfMintEnabled']): ?>checked<?php endif; ?>>
			<?php p($l->t('Let app users create their own AI tokens')); ?>
		</label>
		<p class="exp-form-row__hint" id="exp-agent-selfmint-hint"><?php p($l->t('When off, only app administrators can connect AI assistants.')); ?></p>
	</div>
	<?php endif; ?>

	<dialog id="exp-agent-mint-dialog" class="exp-dialog exp-agent-mint-dialog" aria-labelledby="exp-agent-mint-h">
		<div class="exp-dialog__body">
			<h3 id="exp-agent-mint-h"><?php p($l->t('Connect your AI')); ?></h3>
			<form id="exp-agent-mint-form" novalidate>
				<div class="exp-form-row">
					<label for="exp-agent-label"><?php p($l->t('Label')); ?></label>
					<input type="text" id="exp-agent-label" name="label" maxlength="190" required autocomplete="off" placeholder="<?php p($l->t('Claude on my laptop')); ?>">
					<p class="exp-field-error" id="exp-agent-label-error" hidden></p>
				</div>
				<fieldset class="exp-form-row exp-fieldset">
					<legend><?php p($l->t('What may the AI do?')); ?></legend>
					<div class="exp-choice-cards">
						<label class="exp-choice-card">
							<input type="radio" name="preset" value="read" checked>
							<span class="exp-choice-card__copy">
								<span class="exp-choice-card__title"><?php p($l->t('Read only')); ?></span>
								<span class="exp-choice-card__hint"><?php p($l->t('see your data, never change it')); ?></span>
							</span>
						</label>
						<label class="exp-choice-card">
							<input type="radio" name="preset" value="act">
							<span class="exp-choice-card__copy">
								<span class="exp-choice-card__title"><?php p($l->t('Read + act on my behalf')); ?></span>
								<span class="exp-choice-card__hint"><?php p($l->t('read and make changes — payments and legal stays with you')); ?></span>
							</span>
						</label>
						<label class="exp-choice-card">
							<input type="radio" name="preset" value="custom">
							<span class="exp-choice-card__copy">
								<span class="exp-choice-card__title"><?php p($l->t('Choose exactly')); ?></span>
								<span class="exp-choice-card__hint"><?php p($l->t('pick each permission yourself')); ?></span>
							</span>
						</label>
					</div>
				</fieldset>
				<fieldset class="exp-form-row exp-fieldset" id="exp-agent-custom-wrap" hidden>
					<legend><?php p($l->t('Permissions')); ?></legend>
					<div class="exp-agent-chips" id="exp-agent-chips"></div>
					<p class="exp-field-error" id="exp-agent-scopes-error" hidden></p>
				</fieldset>
				<p class="exp-form-row__hint"><?php p($l->t('Valid for 1 year. The token only reaches this app.')); ?></p>
			</form>
		</div>
		<footer class="exp-dialog__footer">
			<div class="exp-actions">
				<button type="button" class="exp-btn exp-btn--secondary" id="exp-agent-mint-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="submit" form="exp-agent-mint-form" class="exp-btn exp-btn--primary" id="exp-agent-mint-submit"><?php p($l->t('Create token')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="exp-agent-secret-dialog" class="exp-dialog exp-agent-secret-dialog" aria-labelledby="exp-agent-secret-h">
		<div class="exp-dialog__body">
			<h3 id="exp-agent-secret-h"><?php p($l->t('Your AI is connected')); ?></h3>
			<p class="exp-form-row__hint"><?php p($l->t('Copy this config into your AI client now — the token is shown only once.')); ?></p>
			<div class="exp-form-row">
				<label for="exp-agent-secret"><?php p($l->t('Token')); ?></label>
				<div class="exp-input-group">
					<input type="password" id="exp-agent-secret" readonly autocomplete="off" spellcheck="false">
					<button type="button" class="exp-btn exp-btn--ghost" id="exp-agent-secret-reveal" aria-pressed="false"><?php p($l->t('Show')); ?></button>
					<button type="button" class="exp-btn exp-btn--ghost" id="exp-agent-secret-copy"><?php p($l->t('Copy')); ?></button>
				</div>
			</div>
			<div class="exp-form-row">
				<label for="exp-agent-config"><?php p($l->t('Client config')); ?></label>
				<textarea id="exp-agent-config" class="exp-agent-config" readonly rows="10" spellcheck="false"></textarea>
			</div>
		</div>
		<footer class="exp-dialog__footer">
			<div class="exp-actions">
				<button type="button" class="exp-btn exp-btn--secondary" id="exp-agent-secret-close"><?php p($l->t('Done')); ?></button>
				<button type="button" class="exp-btn exp-btn--primary" id="exp-agent-config-copy"><?php p($l->t('Copy config')); ?></button>
			</div>
		</footer>
	</dialog>

	<dialog id="exp-agent-revoke-dialog" class="exp-dialog" aria-labelledby="exp-agent-revoke-h">
		<div class="exp-dialog__body">
			<h3 id="exp-agent-revoke-h"><?php p($l->t('Revoke token?')); ?></h3>
			<p id="exp-agent-revoke-text"></p>
		</div>
		<footer class="exp-dialog__footer">
			<div class="exp-actions">
				<button type="button" class="exp-btn exp-btn--secondary" id="exp-agent-revoke-cancel"><?php p($l->t('Cancel')); ?></button>
				<button type="button" class="exp-btn exp-btn--danger" id="exp-agent-revoke-confirm"><?php p($l->t('Revoke')); ?></button>
			</div>
		</footer>
	</dialog>

	<script type="application/json" id="exp-agent-state"><?php
		print_unescaped(json_encode($exp_state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
	?></script>
</section>
