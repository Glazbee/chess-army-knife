import { focusNotice, onReady } from '../shared/focus-notice';

/**
 * Warn before the session ends, and say when it has. The page already shows when the
 * session ends and has a button to extend it; this adds the warning for someone who is
 * in the middle of something. Times are counted from when the page loaded, so they do
 * not depend on the visitor's clock being right.
 */
function watchSession() {
	const box = document.querySelector( '.cak-portal__session' );
	if ( ! box ) {
		return;
	}

	const remaining = parseInt( box.dataset.cakRemaining, 10 ) * 1000;
	const warnBefore = parseInt( box.dataset.cakWarn, 10 ) * 1000;
	const warning = box.querySelector( '.cak-portal__session-warning' );

	function showWarning() {
		const minutes = Math.max( 1, Math.ceil( remaining / 60000 ) );
		warning.textContent = box.dataset.msgWarning.replace( '%d', minutes );
	}

	if ( remaining <= warnBefore ) {
		showWarning();
	} else {
		window.setTimeout( showWarning, remaining - warnBefore );
	}

	window.setTimeout( () => {
		warning.textContent = box.dataset.msgEnded;
	}, remaining );
}

onReady( () => {
	focusNotice();
	watchSession();
} );
