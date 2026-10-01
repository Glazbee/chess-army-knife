/**
 * After a form is sent the page reloads with a message at the top of the block.
 * Move focus to it, so a screen reader reads it and a keyboard user starts from it.
 */
export function focusNotice() {
	const notice = document.querySelector( '.cak-form-notice' );
	if ( notice ) {
		notice.focus();
	}
}

export function onReady( callback ) {
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', callback );
	} else {
		callback();
	}
}
