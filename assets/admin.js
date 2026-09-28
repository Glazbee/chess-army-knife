/**
 * Admin behaviour for the Tournaments and Players pages:
 * - the player selector (filter saved players, search the ECF list, add players by hand),
 * - filling a player's name and ECF code from an ECF search,
 * - showing only the create-form fields that apply to the chosen format.
 */
( function () {
	'use strict';

	var settings = window.chessArmyKnifeAdmin || { i18n: {}, minRating: 1300 };
	var i18n = settings.i18n;

	/**
	 * Search the ECF list through the plugin's REST proxy.
	 *
	 * @param {string} term Text typed so far.
	 * @return {Promise<Array>} Players: code, name, club.
	 */
	function searchEcf( term ) {
		return window.wp.apiFetch( {
			path: '/ecf-lms/v1/players?search=' + encodeURIComponent( term ),
		} );
	}

	/**
	 * Wire an ECF search box to a results list.
	 *
	 * @param {Element}  root     Element holding the search box, spinner and results list.
	 * @param {Function} render   Called with (player, listItem) to fill each result's row.
	 */
	function bindEcfSearch( root, render ) {
		var input = root.querySelector( '[data-cak-ecf-search]' );
		var results = root.querySelector( '[data-cak-results]' );
		var spinner = root.querySelector( '[data-cak-spinner]' );
		var timer = null;
		var latest = 0;

		if ( ! input || ! results ) {
			return;
		}

		function message( text ) {
			results.textContent = '';
			var li = document.createElement( 'li' );
			li.className = 'description';
			li.textContent = text;
			results.appendChild( li );
		}

		input.addEventListener( 'input', function () {
			clearTimeout( timer );
			var term = input.value.trim();
			if ( term.length < 3 ) {
				results.textContent = '';
				return;
			}
			timer = setTimeout( function () {
				var request = ++latest;
				if ( spinner ) {
					spinner.classList.add( 'is-active' );
				}
				searchEcf( term )
					.then( function ( players ) {
						if ( request !== latest ) {
							return;
						}
						results.textContent = '';
						if ( ! players || ! players.length ) {
							message( i18n.noResults );
							return;
						}
						players.slice( 0, 12 ).forEach( function ( player ) {
							var li = document.createElement( 'li' );
							render( player, li );
							results.appendChild( li );
						} );
					} )
					.catch( function () {
						if ( request === latest ) {
							message( i18n.searchError );
						}
					} )
					.then( function () {
						if ( spinner && request === latest ) {
							spinner.classList.remove( 'is-active' );
						}
					} );
			}, 400 );
		} );

		// Keep Enter from submitting the whole form while searching.
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
			}
		} );
	}

	function describe( player ) {
		return (
			player.name +
			( player.club ? ' — ' + player.club : '' ) +
			' (' +
			player.code +
			')'
		);
	}

	function makeButton( label, onClick ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button button-small';
		button.textContent = label;
		button.addEventListener( 'click', onClick );
		return button;
	}

	/**
	 * The multi-player selector.
	 *
	 * @param {Element} root The [data-cak-selector] element.
	 */
	function initSelector( root ) {
		var filter = root.querySelector( '[data-cak-filter]' );
		var items = Array.prototype.slice.call(
			root.querySelectorAll( '.cak-selector__item' )
		);
		var count = root.querySelector( '[data-cak-count]' );
		var newList = root.querySelector( '[data-cak-new]' );
		var newWrap = root.querySelector( '[data-cak-new-wrap]' );
		var nextIndex = 0;

		function updateCount() {
			var total = root.querySelectorAll(
				'input[name="player_ids[]"]:checked'
			).length;
			total += newList.children.length;
			if ( count ) {
				count.textContent = total
					? ' ' + i18n.selected.replace( '%d', total )
					: '';
			}
			newWrap.hidden = ! newList.children.length;
		}

		function findSaved( code ) {
			for ( var i = 0; i < items.length; i++ ) {
				if ( code && items[ i ].getAttribute( 'data-cak-code' ) === code ) {
					return items[ i ];
				}
			}
			return null;
		}

		function hasNew( code ) {
			return !! newList.querySelector(
				'[data-cak-new-code="' + code + '"]'
			);
		}

		// A player found or typed in becomes a chip with hidden fields the server reads.
		function addNew( name, code, rating ) {
			var index = nextIndex++;
			var li = document.createElement( 'li' );
			var detail = code || ( rating ? i18n.manual + ' ' + rating : '' );

			if ( code ) {
				li.setAttribute( 'data-cak-new-code', code );
			}
			li.appendChild(
				document.createTextNode( name + ( detail ? ' (' + detail + ') ' : ' ' ) )
			);

			[ [ 'name', name ], [ 'ecf_code', code ], [ 'manual_rating', rating ] ].forEach(
				function ( pair ) {
					var hidden = document.createElement( 'input' );
					hidden.type = 'hidden';
					hidden.name = 'new_players[' + index + '][' + pair[ 0 ] + ']';
					hidden.value = pair[ 1 ] || '';
					li.appendChild( hidden );
				}
			);
			li.appendChild(
				makeButton( i18n.remove, function () {
					li.remove();
					updateCount();
				} )
			);
			newList.appendChild( li );
			updateCount();
		}

		if ( filter ) {
			filter.addEventListener( 'input', function () {
				var term = filter.value.trim().toLowerCase();
				items.forEach( function ( item ) {
					item.hidden =
						term !== '' &&
						item.getAttribute( 'data-cak-search' ).indexOf( term ) === -1;
				} );
			} );
			filter.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
				}
			} );
		}

		root.addEventListener( 'change', function ( event ) {
			if ( event.target.name === 'player_ids[]' ) {
				updateCount();
			}
		} );

		var selectShown = root.querySelector( '[data-cak-select-shown]' );
		if ( selectShown ) {
			selectShown.addEventListener( 'click', function () {
				items.forEach( function ( item ) {
					if ( ! item.hidden ) {
						item.querySelector( 'input' ).checked = true;
					}
				} );
				updateCount();
			} );
		}

		var clear = root.querySelector( '[data-cak-clear]' );
		if ( clear ) {
			clear.addEventListener( 'click', function () {
				items.forEach( function ( item ) {
					item.querySelector( 'input' ).checked = false;
				} );
				updateCount();
			} );
		}

		bindEcfSearch( root, function ( player, li ) {
			var saved = findSaved( player.code );
			var button;

			if ( saved ) {
				button = makeButton( i18n.select, function () {
					saved.querySelector( 'input' ).checked = true;
					saved.hidden = false;
					saved.scrollIntoView( { block: 'nearest' } );
					updateCount();
				} );
			} else {
				button = makeButton( i18n.add, function () {
					if ( ! hasNew( player.code ) ) {
						addNew( player.name, player.code, '' );
					}
				} );
			}
			li.appendChild( button );
			li.appendChild(
				document.createTextNode(
					' ' + describe( player ) + ( saved ? ' — ' + i18n.saved : '' )
				)
			);
		} );

		var manualName = root.querySelector( '[data-cak-manual-name]' );
		var manualRating = root.querySelector( '[data-cak-manual-rating]' );
		var manualError = root.querySelector( '[data-cak-manual-error]' );
		var manualAdd = root.querySelector( '[data-cak-manual-add]' );

		if ( manualAdd ) {
			manualAdd.addEventListener( 'click', function () {
				var name = manualName.value.trim();
				var rating = manualRating.value.trim();
				manualError.textContent = '';

				if ( ! name ) {
					manualError.textContent = i18n.needName;
					return;
				}
				if ( rating && Number( rating ) < settings.minRating ) {
					manualError.textContent = i18n.needRating;
					return;
				}
				addNew( name, '', rating );
				manualName.value = '';
				manualRating.value = '';
			} );
		}

		updateCount();
	}

	/**
	 * On the Players page: choosing an ECF result fills in the name and code fields.
	 *
	 * @param {Element} root The [data-cak-fill] element.
	 */
	function initFill( root ) {
		bindEcfSearch( root, function ( player, li ) {
			li.appendChild(
				makeButton( i18n.use, function () {
					document.getElementById( 'name' ).value = player.name;
					document.getElementById( 'ecf_code' ).value = player.code;
					root.querySelector( '[data-cak-results]' ).textContent = '';
					root.querySelector( '[data-cak-ecf-search]' ).value = '';
				} )
			);
			li.appendChild( document.createTextNode( ' ' + describe( player ) ) );
		} );
	}

	/**
	 * On the create form: show only the fields that apply to the chosen format,
	 * and the knockout-stage settings only when the knockout stage is switched on.
	 *
	 * @param {Element} form The create form.
	 */
	function initCreateForm( form ) {
		var format = form.querySelector( '#format' );
		var knockout = form.querySelector( '#knockout_stage' );

		function toggle( row, visible ) {
			row.hidden = ! visible;
			row.classList.toggle( 'hidden', ! visible );
		}

		function update() {
			Array.prototype.forEach.call(
				form.querySelectorAll( '[data-cak-formats]' ),
				function ( row ) {
					var formats = row.getAttribute( 'data-cak-formats' ).split( ' ' );
					var visible = formats.indexOf( format.value ) !== -1;
					if ( visible && row.hasAttribute( 'data-cak-needs-knockout' ) ) {
						visible = knockout.checked;
					}
					toggle( row, visible );
				}
			);
		}

		format.addEventListener( 'change', update );
		knockout.addEventListener( 'change', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-cak-selector]' ),
			initSelector
		);
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-cak-fill]' ),
			initFill
		);
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-cak-create-form]' ),
			initCreateForm
		);
	} );
} )();
