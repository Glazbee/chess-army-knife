/**
 * A search box above "Members in this photo": typing narrows the checklist to the names that match, and
 * every tick stays as it was. It works in the media dialog, whose fields are drawn again as the photo changes,
 * so it listens on the page rather than on the box.
 */
( function () {
	'use strict';

	document.addEventListener( 'input', function ( event ) {
		var box = event.target;
		if (
			! box.classList ||
			! box.classList.contains( 'cak-photo-members-search' )
		) {
			return;
		}

		var list = box.parentNode.querySelector( '.cak-photo-members-list' );
		var status = box.parentNode.querySelector(
			'.cak-photo-members-status'
		);
		var term = box.value.trim().toLowerCase();
		var shown = 0;

		if ( ! list ) {
			return;
		}
		Array.prototype.forEach.call( list.children, function ( item ) {
			var match = item.textContent.toLowerCase().indexOf( term ) !== -1;
			item.hidden = ! match;
			shown += match ? 1 : 0;
		} );

		// Say how many are left, for people who cannot see the list change.
		if ( status ) {
			status.textContent = term
				? shown + ' ' + status.getAttribute( 'data-label' )
				: '';
		}
	} );

	// Enter in the box must not save the photo's details.
	document.addEventListener( 'keydown', function ( event ) {
		if (
			event.key === 'Enter' &&
			event.target.classList &&
			event.target.classList.contains( 'cak-photo-members-search' )
		) {
			event.preventDefault();
		}
	} );
} )();
