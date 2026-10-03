/**
 * Put a group's teams in order: drag a team by its handle, or use its Move up and Move down buttons
 * (which work from the keyboard). Either way the new place is announced for screen readers.
 */
( function ( $ ) {
	var $list = $( '.cak-group-teams' );
	var status = document.getElementById( 'cak-group-teams-status' );
	if ( ! $list.length ) {
		return;
	}

	function announce( item ) {
		var place;
		var message;
		if ( ! status ) {
			return;
		}
		place = $list.children().index( item ) + 1;
		message = $list.attr( 'data-moved' ) || '';
		status.textContent = message
			.replace( '%1$s', item.getAttribute( 'data-name' ) )
			.replace( '%2$s', place );
	}

	$list.sortable( {
		handle: '.cak-drag-handle',
		axis: 'y',
		forcePlaceholderSize: true,
		start: function ( event, ui ) {
			ui.placeholder.css( {
				border: '1px dashed #8c8f94',
				visibility: 'visible',
			} );
		},
		update: function ( event, ui ) {
			announce( ui.item[ 0 ] );
		},
	} );

	$list.on( 'click', '.cak-move-up, .cak-move-down', function () {
		var button = this;
		var item = $( button ).closest( '.cak-group-team' );
		var sibling = $( button ).hasClass( 'cak-move-up' )
			? item.prev()
			: item.next();
		if ( ! sibling.length ) {
			return;
		}
		if ( $( button ).hasClass( 'cak-move-up' ) ) {
			item.insertBefore( sibling );
		} else {
			item.insertAfter( sibling );
		}
		button.focus();
		announce( item[ 0 ] );
	} );
} )( jQuery );
