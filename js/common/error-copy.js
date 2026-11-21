/**
 * §9.2 error-copy contract: server error_code → client sentence + recovery
 * action. Sentences are localized; DE is the binding register, EN mirrors.
 * Unknown codes fall back to the server message (handled by the caller).
 */
window.EXPErrorCopy = (function () {
	function t(s) {
		return window.OC && window.OC.L10N ? window.OC.L10N.translate('expensecheck', s) : s;
	}
	var MAP = {
		RATE_MISSING: [
			'No rate is defined for this date.',
			'Choose another date or ask an admin to add a rate.'
		],
		CHAIN_UNROUTABLE: [
			'No approval chain covers this claim.',
			'Check the amount or ask an admin to set up a chain.'
		],
		RECEIPT_REQUIRED: [
			'This category requires a receipt.',
			'Attach the receipt, then submit again.'
		],
		JUSTIFICATION_REQUIRED: [
			'This amount needs a written justification.',
			'The field is marked — enter the reason and retry.'
		],
		ALREADY_CLAIMED: [
			'This was already claimed in another expense report.',
			'Open the existing claim or pick a different item.'
		],
		PERIOD_CLOSED: [
			'This period is closed.',
			'Pick a date in an open period or ask finance to reopen it.'
		],
		VERSION_CONFLICT: [
			'This changed in the meantime.',
			'Reload the page and try again.'
		],
		JOURNAL_BUSY: [
			'Temporarily locked — please resend.',
			'Wait a moment and try again.'
		],
		ATTACHMENT_TYPE_DENIED: [
			'This file type is not allowed.',
			'Use one of the allowed types shown in the picker.'
		],
		ALLOCATION_SUM_MISMATCH: [
			'The allocation does not add up to the line total.',
			'Adjust the splits so they equal the line amount.'
		],
		LICENSE_REQUIRED: [
			'This feature needs a mobile (EXP2) license seat.',
			'Contact your administrator about seat status.'
		],
		NO_MOBILE_SEAT: [
			'No free license seat is available.',
			'Ask your administrator to assign or free a seat.'
		],
		SELF_APPROVAL_DENIED: [
			'Self-approval is disabled.',
			'Choose a different approver.'
		],
		SEAT_LIMIT_EXCEEDED: [
			'All license seats are in use.',
			'Free a seat or extend the license before assigning more.'
		],
		MILEAGE_DISABLED: [
			'Mileage claims are disabled for this org.',
			'Pick a different line type or ask an admin to enable it.'
		],
		VALIDATION_FAILED: [
			'Some entries need a correction.',
			'Check the highlighted fields and send again.'
		],
		CLAIM_EMPTY: [
			'This claim has no lines yet.',
			'Add at least one line, then submit.'
		],
		CLAIM_NOT_CLONEABLE: [
			'This claim cannot be duplicated.',
			'Create a new claim instead.'
		],
		CLAIM_NOT_PENDING: [
			'This claim is no longer waiting for a decision.',
			'Reload the page to see its current state.'
		],
		CLAIM_NOT_SETTLEABLE: [
			'This claim cannot be paid out.',
			'Only approved claims can be settled.'
		],
		CLAIM_READ_ONLY: [
			'This claim is locked for editing.',
			'Its current state does not allow changes.'
		],
		STEP_ALREADY_DECIDED: [
			'This step already has a decision.',
			'Reload the page to see the current state.'
		],
		CAP_EXCEEDED: [
			'The amount exceeds this category\'s limit.',
			'Lower the amount or pick another category.'
		],
		DUPLICATE_CODE: [
			'This code is already in use.',
			'Choose a different code.'
		],
		EXPORT_NOT_READY: [
			'The export is not ready yet.',
			'Wait a moment and try again.'
		],
		FILE_UNREADABLE: [
			'This file could not be read.',
			'Check the file and upload it again.'
		],
		FX_NOT_FOUND: [
			'No exchange rate is known for this currency.',
			'Ask an admin to add the rate.'
		],
		LICENSE_INVALID: [
			'The license could not be verified.',
			'Check the license or contact your administrator.'
		],
		LICENSE_EXPIRED: [
			'The mobile license has expired.',
			'Renew the license to keep using the app.'
		],
		CONTRACT_VERSION_MISMATCH: [
			'The app is out of date.',
			'Update to the latest version and sign in again.'
		],
		PERIOD_BLOCKED: [
			'This period is blocked.',
			'Ask finance about the period state.'
		],
		PERIOD_NOT_CLOSED: [
			'This period is not closed yet.',
			'Close the period first, then try again.'
		],
		RATE_IMMUTABLE: [
			'This rate is locked because it is in use.',
			'Add a new rate instead of changing this one.'
		],
		STORAGE_DENIED: [
			'The storage location is not writable.',
			'Ask an administrator to check app storage.'
		],
		TOO_MANY_FILES: [
			'Too many files at once.',
			'Upload fewer files and try again.'
		],
		TRANSFER_CONFIRM_REQUIRED: [
			'The transfer needs a confirmation.',
			'Tick the confirmation and send again.'
		]
	};
	// Not-found family shares one honest sentence — no existence oracle:
	// "not found or no access" never reveals which it was.
	var NF = [
		'This entry was not found or you have no access.',
		'Reload the page — the entry may have been removed.'
	];
	[
		'NOT_FOUND', 'ATTACHMENT_NOT_FOUND', 'BATCH_NOT_FOUND',
		'CATEGORY_NOT_FOUND', 'CHAIN_NOT_FOUND', 'CHARGEBACK_NOT_FOUND',
		'CLAIM_NOT_FOUND', 'COST_CENTER_NOT_FOUND', 'DELEGATE_NOT_FOUND',
		'MANAGER_MAP_NOT_FOUND', 'PERIOD_NOT_FOUND', 'SEGMENT_NOT_FOUND'
	].forEach(function (code) { MAP[code] = NF; });
	return {
		/** @returns {{sentence:string, recovery:string}|null} */
		describe: function (code) {
			var pair = code && MAP[code];
			return pair ? { sentence: t(pair[0]), recovery: t(pair[1]) } : null;
		}
	};
})();
