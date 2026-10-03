/**
 * The Leagues tab: put the club's teams in divisions. Drag a team by its handle into a division (or back out),
 * or use its arrow buttons to move it to the list above or below, which works from the keyboard. Every move is
 * announced for screen readers. A team that has moved has not been matched against its new division's teams
 * until the page is saved, so its match prompt is cleared.
 */
( function ( $ ) {
	var $status = $(
		'<p class="screen-reader-text" role="status" aria-live="polite"></p>'
	);

	if ( ! $( '.cak-org' ).length ) {
		return;
	}
	$status.appendTo( 'body' );

	function settle( item, division ) {
		var org = item.closest( '.cak-org' );
		var message = org.attr( 'data-moved' ) || '';
		item.find( '.cak-division-value' ).val( division );
		item.find( '.cak-match' ).empty();
		$status.text(
			message
				.replace( '%1$s', item.find( 'strong' ).first().text() )
				.replace( '%2$s', division || org.attr( 'data-none' ) || '' )
		);
	}

	$( '.cak-org' ).each( function () {
		var $lists = $( this ).find( '.cak-division-list' );
		$lists.sortable( {
			connectWith: $lists,
			handle: '.cak-drag-handle',
			forcePlaceholderSize: true,
			start: function ( event, ui ) {
				ui.placeholder.css( {
					border: '1px dashed #8c8f94',
					visibility: 'visible',
				} );
			},
			stop: function ( event, ui ) {
				settle( ui.item, ui.item.parent().attr( 'data-division' ) );
			},
		} );
	} );

	// Move to the list above or below: the teams not in a division, then the divisions in order.
	$( document ).on( 'click', '.cak-move-up, .cak-move-down', function () {
		var button = $( this );
		var item = button.closest( '.cak-league-team' );
		var lists = item.closest( '.cak-org' ).find( '.cak-division-list' );
		var at = lists.index( item.parent() );
		var to = lists.eq( at + ( button.hasClass( 'cak-move-up' ) ? -1 : 1 ) );
		if ( ! to.length ) {
			return;
		}
		item.appendTo( to );
		settle( item, to.attr( 'data-division' ) );
		button.trigger( 'focus' );
	} );

	// Change an organisation's name only when asked to.
	$( document ).on( 'click', '.cak-edit-org-name', function () {
		var button = $( this );
		var row = $( '#' + button.attr( 'aria-controls' ) );
		row.show().find( 'input' ).trigger( 'focus' );
		button.attr( 'aria-expanded', 'true' );
	} );
} )( jQuery );
