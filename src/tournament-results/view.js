/**
 * Front-end behaviour for the Tournament Results Entry block: saves a game
 * result through the REST API whenever a result selector changes.
 */
function setStatus( element, text, modifier ) {
	element.textContent = text;
	element.className = 'cak-results__status';
	if ( modifier ) {
		element.classList.add( 'cak-results__status--' + modifier );
	}
}

function saveResult( block, select ) {
	const status = select.parentElement.querySelector( '.cak-results__status' );
	const previous = select.dataset.saved || '';
	setStatus( status, '…' );
	select.disabled = true;

	return window
		.fetch( block.dataset.restUrl + select.dataset.gameId + '/result', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': block.dataset.nonce,
			},
			body: JSON.stringify( { result: select.value } ),
		} )
		.then( ( response ) =>
			response.json().then( ( body ) => ( { ok: response.ok, body } ) )
		)
		.then( ( { ok, body } ) => {
			if ( ! ok ) {
				throw new Error( body.message || 'Could not save' );
			}
			select.dataset.saved = select.value;
			setStatus( status, '✓', 'saved' );
		} )
		.catch( ( error ) => {
			select.value = previous;
			setStatus( status, error.message, 'error' );
		} )
		.then( () => {
			select.disabled = false;
		} );
}

function init() {
	document
		.querySelectorAll( '.wp-block-chess-army-knife-tournament-results' )
		.forEach( ( block ) => {
			block
				.querySelectorAll( '.cak-results__select' )
				.forEach( ( select ) => {
					select.dataset.saved = select.value;
					select.addEventListener( 'change', () =>
						saveResult( block, select )
					);
				} );
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
