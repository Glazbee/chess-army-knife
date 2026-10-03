/**
 * The Officers screen's order: drag a row by its handle to put it in a new place, or use its arrow buttons,
 * which work from the keyboard. The order of the rows as they are on the screen is what is saved. Each
 * move is announced for screen readers.
 */
( function ( $ ) {
	'use strict';

	var $list = $( '[data-cak-officer-order]' );
	var $status = $( '[data-cak-officer-status]' );
	if ( ! $list.length ) {
		return;
	}

	function say( row ) {
		var label =
			row.find( 'input[type="text"]' ).val() || row.attr( 'data-label' );
		if ( ! label ) {
			return;
		}
		$status.text(
			( $list.attr( 'data-moved' ) || '' )
				.replace( '%1$s', label )
				.replace( '%2$d', row.index() + 1 )
				.replace( '%3$d', $list.children().length )
		);
	}

	// The first row cannot go up and the last cannot go down.
	function refresh() {
		var last = $list.children().length - 1;
		$list.children().each( function ( index ) {
			$( this )
				.find( '.cak-move-up' )
				.prop( 'disabled', index === 0 );
			$( this )
				.find( '.cak-move-down' )
				.prop( 'disabled', index === last );
		} );
	}

	$list.sortable( {
		handle: '.cak-drag-handle',
		forcePlaceholderSize: true,
		stop: function ( event, ui ) {
			refresh();
			say( ui.item );
		},
	} );

	$list.on( 'click', '.cak-move-up, .cak-move-down', function () {
		var button = $( this );
		var row = button.closest( '.cak-officer-row' );
		if ( button.hasClass( 'cak-move-up' ) ) {
			row.prev().before( row );
		} else {
			row.next().after( row );
		}
		refresh();
		say( row );
		// Keep the focus on the same kind of button, unless the row has reached the end.
		var next = row.find(
			button.hasClass( 'cak-move-up' ) ? '.cak-move-up' : '.cak-move-down'
		);
		( next.prop( 'disabled' )
			? row
					.find( '.cak-move-up, .cak-move-down' )
					.not( ':disabled' )
					.first()
			: next
		).trigger( 'focus' );
	} );

	refresh();
} )( jQuery );
