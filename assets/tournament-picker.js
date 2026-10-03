/**
 * Choosing a tournament's players: two lists, the members and guests who can be entered (left, with a
 * search box) and the entrants (right). Drag a person by their handle between the lists, or use the arrow
 * buttons, which work from the keyboard. Only the right list is saved. Someone who is not a member is added
 * in the box below: with an ECF code (looked up when the form is saved) or with a name and an optional rating.
 * Every change is announced for screen readers.
 */
( function ( $ ) {
	'use strict';

	var settings = window.chessArmyKnifePicker || { i18n: {}, minRating: 1300 };
	var i18n = settings.i18n;

	$( '[data-cak-picker]' ).each( function () {
		var $root = $( this );
		var $available = $root.find( '.cak-picker__available' );
		var $entered = $root.find( '.cak-picker__entered' );
		var $count = $root.find( '[data-cak-count]' );
		var $status = $root.find( '[data-cak-status]' );
		var $newList = $root.find( '[data-cak-new]' );
		var nextIndex = 0;

		function updateCount() {
			var total = $entered.children().length + $newList.children().length;
			$count.text(
				total ? '(' + i18n.count.replace( '%d', total ) + ')' : ''
			);
		}

		function say( key, item ) {
			$status.text(
				( $root.attr( 'data-' + key ) || '' ).replace(
					'%s',
					item.attr( 'data-label' )
				)
			);
		}

		function sortByName( $list ) {
			$list
				.children()
				.sort( function ( a, b ) {
					return $( a )
						.attr( 'data-label' )
						.localeCompare( $( b ).attr( 'data-label' ) );
				} )
				.appendTo( $list );
		}

		// Show the button that fits the list a person is in, and save them only when they are entered.
		function sync( item ) {
			var inTournament = item.parent().is( $entered );
			item.find( '.cak-remove' ).toggle( inTournament );
			item.find( '.cak-add' ).toggle( ! inTournament );
			item.find( 'input[name="player_ids[]"]' ).prop(
				'disabled',
				! inTournament
			);
		}

		function moved( item, wasEntered ) {
			var inTournament = item.parent().is( $entered );
			sync( item );
			if ( ! inTournament ) {
				sortByName( item.parent() );
			}
			if ( inTournament !== wasEntered ) {
				say( inTournament ? 'added' : 'removed', item );
			}
			updateCount();
		}

		$root.find( '.cak-picker__list' ).sortable( {
			connectWith: $root.find( '.cak-picker__list' ),
			handle: '.cak-drag-handle',
			forcePlaceholderSize: true,
			start: function ( event, ui ) {
				ui.item.data( 'wasEntered', ui.item.parent().is( $entered ) );
			},
			stop: function ( event, ui ) {
				// Someone entered by name only has no record to go back to, so they leave the list.
				if (
					ui.item.attr( 'data-unlinked' ) === '1' &&
					ui.item.parent().is( $available )
				) {
					say( 'removed', ui.item );
					ui.item.remove();
					updateCount();
					return;
				}
				moved( ui.item, ui.item.data( 'wasEntered' ) );
			},
		} );

		$root.on( 'click', '.cak-add', function () {
			var item = $( this ).closest( '.cak-picker-person' );
			item.appendTo( $entered );
			moved( item, false );
			item.find( '.cak-remove' ).trigger( 'focus' );
		} );

		$root.on( 'click', '.cak-remove', function () {
			var item = $( this ).closest( '.cak-picker-person' );
			if ( item.attr( 'data-unlinked' ) === '1' ) {
				say( 'removed', item );
				item.remove();
				updateCount();
				return;
			}
			item.appendTo( $available );
			moved( item, true );
			item.find( '.cak-add' ).trigger( 'focus' );
		} );

		// Narrow the left list as someone types; Enter must not submit the form.
		$root
			.find( '[data-cak-filter]' )
			.on( 'input', function () {
				var term = $( this ).val().toLowerCase().trim();
				$available.children().each( function () {
					var match =
						$( this ).attr( 'data-name' ).indexOf( term ) !== -1;
					$( this ).css( 'display', match ? '' : 'none' );
				} );
			} )
			.on( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
				}
			} );

		// Someone who is not a member.
		var $codeRow = $root.find( '[data-cak-guest-code]' );
		var $noneRow = $root.find( '[data-cak-guest-none]' );
		var $error = $root.find( '[data-cak-guest-error]' );
		var $code = $root.find( '[data-cak-guest-code-input]' );
		var $name = $root.find( '[data-cak-guest-name]' );
		var $rating = $root.find( '[data-cak-guest-rating]' );

		function kind() {
			return $root.find( '[data-cak-guest-kind]:checked' ).val();
		}

		$root.find( '[data-cak-guest-kind]' ).on( 'change', function () {
			var withCode = kind() === 'code';
			$codeRow.prop( 'hidden', ! withCode );
			$noneRow.prop( 'hidden', withCode );
			$error.text( '' );
		} );

		function addNew( name, code, rating ) {
			var index = nextIndex++;
			var note = code
				? i18n.codeNote.replace( '%s', code )
				: rating
				? i18n.manualNote.replace( '%s', rating )
				: i18n.noCodeNote;
			var $li = $( '<li>' ).text(
				( name ? name + ' ' : '' ) + '(' + note + ') '
			);

			[
				[ 'name', name ],
				[ 'ecf_code', code ],
				[ 'manual_rating', rating ],
			].forEach( function ( pair ) {
				$( '<input type="hidden">' )
					.attr(
						'name',
						'new_players[' + index + '][' + pair[ 0 ] + ']'
					)
					.val( pair[ 1 ] || '' )
					.appendTo( $li );
			} );
			$( '<button type="button" class="button button-small">' )
				.text( i18n.remove )
				.on( 'click', function () {
					$li.remove();
					updateCount();
				} )
				.appendTo( $li );
			$newList.append( $li );
			updateCount();
		}

		$root.find( '[data-cak-guest-add]' ).on( 'click', function () {
			$error.text( '' );
			if ( kind() === 'code' ) {
				var code = $code
					.val()
					.replace( /[^0-9A-Za-z]/g, '' )
					.toUpperCase();
				if ( ! code ) {
					$error.text( i18n.needCode );
					return;
				}
				addNew( '', code, '' );
				$code.val( '' ).trigger( 'focus' );
				return;
			}
			var name = $name.val().trim();
			var rating = $rating.val().trim();
			if ( ! name ) {
				$error.text( i18n.needName );
				return;
			}
			if ( rating && Number( rating ) < settings.minRating ) {
				$error.text( i18n.needRating );
				return;
			}
			addNew( name, '', rating );
			$name.val( '' ).trigger( 'focus' );
			$rating.val( '' );
		} );

		// Enter in these boxes adds the person, not the whole form.
		$root
			.find(
				'[data-cak-guest-code-input], [data-cak-guest-name], [data-cak-guest-rating]'
			)
			.on( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					$( this )
						.closest( 'p' )
						.find( '[data-cak-guest-add]' )
						.trigger( 'click' );
				}
			} );

		updateCount();
	} );
} )( jQuery );
