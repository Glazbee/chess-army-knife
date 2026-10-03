/**
 * A group's teams: two lists, the teams not in the group (left) and those in it (right). Drag a team by
 * its handle between them or within the group, or use the arrow buttons, which work from the keyboard.
 * Every change is announced for screen readers.
 */
( function ( $ ) {
	var $columns = $( '.cak-group-columns' );
	var $available = $( '.cak-group-available' );
	var $group = $( '.cak-group-teams' );
	var status = document.getElementById( 'cak-group-teams-status' );
	if ( ! $columns.length ) {
		return;
	}

	function say( key, item, place ) {
		var message;
		if ( ! status ) {
			return;
		}
		message = $columns.attr( 'data-' + key ) || '';
		status.textContent = message
			.replace( '%1$s', item.attr( 'data-name' ) )
			.replace( '%2$s', place )
			.replace( '%s', item.attr( 'data-name' ) );
	}

	// Show the buttons that fit the list a team is in, and save it only when it is in the group.
	function sync( item ) {
		var inGroup = item.parent().is( $group );
		item.find( '.cak-move-up, .cak-move-down, .cak-remove' ).toggle(
			inGroup
		);
		item.find( '.cak-add' ).toggle( ! inGroup );
		item.find( 'input[name="chess_army_group_teams[]"]' ).prop(
			'disabled',
			! inGroup
		);
	}

	function moved( item, wasInGroup ) {
		var inGroup = item.parent().is( $group );
		sync( item );
		if ( inGroup && ! wasInGroup ) {
			say( 'added', item );
		} else if ( ! inGroup && wasInGroup ) {
			say( 'removed', item );
		} else if ( inGroup ) {
			say( 'moved', item, $group.children().index( item ) + 1 );
		}
	}

	$( '.cak-group-available, .cak-group-teams' ).sortable( {
		connectWith: '.cak-group-available, .cak-group-teams',
		handle: '.cak-drag-handle',
		forcePlaceholderSize: true,
		start: function ( event, ui ) {
			ui.item.data( 'wasInGroup', ui.item.parent().is( $group ) );
			ui.placeholder.css( {
				border: '1px dashed #8c8f94',
				visibility: 'visible',
			} );
		},
		stop: function ( event, ui ) {
			moved( ui.item, ui.item.data( 'wasInGroup' ) );
		},
	} );

	$columns.on( 'click', '.cak-move-up, .cak-move-down', function () {
		var button = $( this );
		var item = button.closest( '.cak-group-team' );
		var sibling = button.hasClass( 'cak-move-up' ) ? item.prev() : item.next();
		if ( ! sibling.length ) {
			return;
		}
		if ( button.hasClass( 'cak-move-up' ) ) {
			item.insertBefore( sibling );
		} else {
			item.insertAfter( sibling );
		}
		button.trigger( 'focus' );
		moved( item, true );
	} );

	$columns.on( 'click', '.cak-add', function () {
		var item = $( this ).closest( '.cak-group-team' );
		item.appendTo( $group );
		moved( item, false );
		item.find( '.cak-remove' ).trigger( 'focus' );
	} );

	$columns.on( 'click', '.cak-remove', function () {
		var item = $( this ).closest( '.cak-group-team' );
		item.appendTo( $available );
		moved( item, true );
		item.find( '.cak-add' ).trigger( 'focus' );
	} );
} )( jQuery );
