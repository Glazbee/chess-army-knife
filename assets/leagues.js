/**
 * Add another team to an organisation's table on the Leagues tab: copy the last row, blank it and give
 * its fields a new number so they are saved as a new entry.
 */
( function () {
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.cak-league-add' );
		var table;
		var last;
		var next;
		var copy;
		if ( ! button ) {
			return;
		}

		table = button.parentNode.previousElementSibling;
		last = table.querySelector( 'tbody tr:last-child' );
		next = parseInt( button.getAttribute( 'data-next' ), 10 ) || 0;
		copy = last.cloneNode( true );

		Array.prototype.forEach.call(
			copy.querySelectorAll( 'select, input, label' ),
			function ( field ) {
				var name = field.getAttribute( 'name' );
				var id = field.getAttribute( 'id' );
				var label = field.getAttribute( 'for' );
				// leagues[270][3][team] is row 3 of organisation 270; ids and labels end in the row number.
				if ( name ) {
					field.setAttribute(
						'name',
						name.replace( /^(leagues\[\d+\]\[)\d+(\])/, '$1' + next + '$2' )
					);
				}
				if ( id ) {
					field.setAttribute( 'id', id.replace( /-\d+$/, '-' + next ) );
				}
				if ( label ) {
					field.setAttribute( 'for', label.replace( /-\d+$/, '-' + next ) );
				}
				if ( field.type === 'checkbox' ) {
					field.checked = false;
				} else if ( field.tagName === 'SELECT' ) {
					field.selectedIndex = 0;
				} else if ( field.tagName === 'INPUT' ) {
					field.value = '';
				}
			}
		);
		// A new row has not been checked against the LMS.
		copy.children[ 3 ].innerHTML = '&mdash;';

		last.parentNode.appendChild( copy );
		button.setAttribute( 'data-next', next + 1 );
		copy.querySelector( 'select' ).focus();
	} );
} )();
