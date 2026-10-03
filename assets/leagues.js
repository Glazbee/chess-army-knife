/**
 * The Leagues tab: put the club's teams in divisions. Drag a team by its handle into a division (or back out),
 * or choose the division from the team's own drop-down, which works from the keyboard. The drop-down is what
 * is saved, so both ways agree. Every move is announced for screen readers.
 */
( function ( $ ) {
	var status = document.getElementById( 'cak-leagues-status' );

	function say( org, item, division ) {
		var message = $( org ).attr( 'data-moved' ) || '';
		if ( ! status ) {
			return;
		}
		status.textContent = message
			.replace( '%1$s', item.find( 'strong' ).text() )
			.replace(
				'%2$s',
				division || $( org ).attr( 'data-none' ) || ''
			);
	}

	// A team that has moved has not been matched against its new division's teams until the page is saved.
	function settle( item, division ) {
		var org = item.closest( '.cak-org' );
		item.find( '.cak-division-pick' ).val( division );
		item.find( '.cak-match' ).empty();
		say( org, item, division );
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

	$( document ).on( 'change', '.cak-division-pick', function () {
		var item = $( this ).closest( '.cak-league-team' );
		var division = $( this ).val();
		var target = item
			.closest( '.cak-org' )
			.find( '.cak-division-list' )
			.filter( function () {
				return $( this ).attr( 'data-division' ) === division;
			} )
			.first();
		if ( target.length ) {
			item.appendTo( target );
			settle( item, division );
			item.find( '.cak-division-pick' ).trigger( 'focus' );
		}
	} );

	if ( $( '.cak-org' ).length && ! status ) {
		status = $(
			'<p class="screen-reader-text" role="status" aria-live="polite" id="cak-leagues-status"></p>'
		)
			.appendTo( 'body' )
			.get( 0 );
	}
} )( jQuery );
