/**
 * A team's squad: two lists, the members not in the squad (left, with a search box) and those in it
 * (right). Drag a member by their handle between the lists, or use the arrow buttons, which work from the
 * keyboard. Someone can be in any number of squads, so adding them here never takes them from another.
 * Every change is announced for screen readers.
 */
( function ( $ ) {
	var $columns = $( '.cak-squad-columns' );
	var $available = $( '.cak-squad-available' );
	var $members = $( '.cak-squad-members' );
	var status = document.getElementById( 'cak-squad-status' );
	if ( ! $columns.length ) {
		return;
	}

	function say( key, item ) {
		if ( status ) {
			status.textContent = ( $columns.attr( 'data-' + key ) || '' ).replace(
				'%s',
				item.attr( 'data-name' )
			);
		}
	}

	function sortByName( $list ) {
		$list
			.children()
			.sort( function ( a, b ) {
				return $( a )
					.attr( 'data-name' )
					.localeCompare( $( b ).attr( 'data-name' ) );
			} )
			.appendTo( $list );
	}

	// Show the button that fits the list a member is in, and save them only when they are in the squad.
	function sync( item ) {
		var inSquad = item.parent().is( $members );
		item.find( '.cak-remove' ).toggle( inSquad );
		item.find( '.cak-add' ).toggle( ! inSquad );
		item.find( 'input[name="chess_army_team_squad[]"]' ).prop(
			'disabled',
			! inSquad
		);
	}

	function moved( item, wasInSquad ) {
		var inSquad = item.parent().is( $members );
		sync( item );
		sortByName( item.parent() );
		if ( inSquad !== wasInSquad ) {
			say( inSquad ? 'added' : 'removed', item );
		}
	}

	$( '.cak-squad-available, .cak-squad-members' ).sortable( {
		connectWith: '.cak-squad-available, .cak-squad-members',
		handle: '.cak-drag-handle',
		forcePlaceholderSize: true,
		start: function ( event, ui ) {
			ui.item.data( 'wasInSquad', ui.item.parent().is( $members ) );
			ui.placeholder.css( {
				border: '1px dashed #8c8f94',
				visibility: 'visible',
			} );
		},
		stop: function ( event, ui ) {
			moved( ui.item, ui.item.data( 'wasInSquad' ) );
		},
	} );

	$columns.on( 'click', '.cak-add', function () {
		var item = $( this ).closest( '.cak-squad-person' );
		item.appendTo( $members );
		moved( item, false );
		item.find( '.cak-remove' ).trigger( 'focus' );
	} );

	$columns.on( 'click', '.cak-remove', function () {
		var item = $( this ).closest( '.cak-squad-person' );
		item.appendTo( $available );
		moved( item, true );
		item.find( '.cak-add' ).trigger( 'focus' );
	} );

	// Narrow the left list as someone types; Enter must not submit the team.
	$( '#cak-squad-filter' )
		.on( 'input', function () {
			var term = $( this ).val().toLowerCase();
			$available.children().each( function () {
				var match =
					$( this ).attr( 'data-name' ).toLowerCase().indexOf( term ) !== -1;
				$( this ).css( 'display', match ? 'flex' : 'none' );
			} );
		} )
		.on( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
			}
		} );
} )( jQuery );
