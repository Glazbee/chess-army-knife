/**
 * Move a team up or down in a group's list, and say where it went for screen readers.
 */
( function () {
	var list = document.querySelector( '.cak-group-teams' );
	var status = document.getElementById( 'cak-group-teams-status' );
	if ( ! list ) {
		return;
	}

	list.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.cak-move-up, .cak-move-down' );
		var item = button ? button.closest( '.cak-group-team' ) : null;
		var sibling;
		var place;
		var message;
		if ( ! item ) {
			return;
		}

		if ( button.classList.contains( 'cak-move-up' ) ) {
			sibling = item.previousElementSibling;
			if ( sibling ) {
				list.insertBefore( item, sibling );
			}
		} else {
			sibling = item.nextElementSibling;
			if ( sibling ) {
				list.insertBefore( sibling, item );
			}
		}

		button.focus();
		if ( status && sibling ) {
			place = Array.prototype.indexOf.call( list.children, item ) + 1;
			message = list.getAttribute( 'data-moved' ) || '';
			status.textContent = message
				.replace( '%1$s', item.getAttribute( 'data-name' ) )
				.replace( '%2$s', place );
		}
	} );
} )();
