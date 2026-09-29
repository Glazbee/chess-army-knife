/**
 * Front-end behaviour for the Tournament Games to Play block (administrators only):
 * the two score selectors of a game stay in step (1 with 0, ½ with ½), and the Save
 * button at the top sends every game that has a score in one request.
 */

// The score that goes with the other player's score.
const PARTNER = { '': '', 1: '0', 0: '1', 0.5: '0.5' };

// The result of a game from the white player's score.
const RESULT = { 1: '1-0', 0: '0-1', 0.5: '1/2-1/2' };

function pending( block ) {
	return Array.from(
		block.querySelectorAll( '.cak-games__game[data-game-id]' )
	).filter(
		( row ) =>
			! row.dataset.saved &&
			row.querySelector( '.cak-games__score[data-side="white"]' )
				.value !== ''
	);
}

function setStatus( block, text, isError ) {
	const status = block.querySelector( '.cak-games__status' );
	status.textContent = text;
	status.classList.toggle( 'cak-games__status--error', !! isError );
}

function showRowError( row, message ) {
	let note = row.querySelector( '.cak-games__row-error' );
	if ( ! note ) {
		note = document.createElement( 'span' );
		note.className = 'cak-games__row-error';
		row.appendChild( note );
	}
	note.textContent = message;
}

function save( block, button ) {
	const rows = pending( block );
	const results = {};
	rows.forEach( ( row ) => {
		const white = row.querySelector( '[data-side="white"]' ).value;
		results[ row.dataset.gameId ] = RESULT[ Number( white ) ];
	} );

	button.disabled = true;
	setStatus( block, '…' );

	return window
		.fetch( block.dataset.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': block.dataset.nonce,
			},
			body: JSON.stringify( { results } ),
		} )
		.then( ( response ) =>
			response.json().then( ( body ) => ( { ok: response.ok, body } ) )
		)
		.then( ( { ok, body } ) => {
			if ( ! ok ) {
				throw new Error( body.message || 'Could not save' );
			}

			( body.saved || [] ).forEach( ( id ) => {
				const row = block.querySelector(
					'.cak-games__game[data-game-id="' + id + '"]'
				);
				row.dataset.saved = '1';
				row.classList.add( 'cak-games__game--saved' );
				row.querySelectorAll( 'select' ).forEach( ( select ) => {
					select.disabled = true;
				} );
			} );

			const failed = Object.keys( body.errors || {} );
			if ( failed.length === 0 ) {
				// Reload so the list, standings and any new round or bracket are up to date.
				setStatus( block, '✓' );
				window.location.reload();
				return;
			}

			failed.forEach( ( id ) => {
				const row = block.querySelector(
					'.cak-games__game[data-game-id="' + id + '"]'
				);
				showRowError( row, body.errors[ id ] );
			} );
			setStatus( block, 'Some results could not be saved.', true );
			button.disabled = pending( block ).length === 0;
		} )
		.catch( ( error ) => {
			setStatus( block, error.message, true );
			button.disabled = pending( block ).length === 0;
		} );
}

function init() {
	document
		.querySelectorAll(
			'.wp-block-chess-army-knife-tournament-games[data-rest-url]'
		)
		.forEach( ( block ) => {
			const button = block.querySelector( '.cak-games__save' );

			block.addEventListener( 'change', ( event ) => {
				const select = event.target;
				if ( ! select.classList.contains( 'cak-games__score' ) ) {
					return;
				}
				const other = select
					.closest( '.cak-games__game' )
					.querySelector(
						'.cak-games__score:not([data-side="' +
							select.dataset.side +
							'"])'
					);
				other.value = PARTNER[ select.value ];

				const row = select.closest( '.cak-games__game' );
				const note = row.querySelector( '.cak-games__row-error' );
				if ( note ) {
					note.remove();
				}
				setStatus( block, '' );
				button.disabled = pending( block ).length === 0;
			} );

			button.addEventListener( 'click', () => save( block, button ) );
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
