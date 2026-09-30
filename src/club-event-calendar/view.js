/**
 * Moves each month-grid calendar between months. The first month is
 * rendered on the server; the arrows fetch other months from this site.
 */
function initMonth( root ) {
	const prev = root.querySelector( '.cak-month__prev' );
	const next = root.querySelector( '.cak-month__next' );
	const label = root.querySelector( '.cak-month__label' );
	const grid = root.querySelector( '.cak-month__grid' );
	let busy = false;

	if ( ! prev || ! next || ! label || ! grid ) {
		return;
	}

	function load( month ) {
		if ( busy ) {
			return;
		}
		busy = true;

		const url = new URL( root.dataset.endpoint, window.location.href );
		url.searchParams.set( 'month', month );
		url.searchParams.set( 'tags', root.dataset.tags || '' );
		url.searchParams.set( 'location', root.dataset.location || '1' );
		url.searchParams.set( 'teams', root.dataset.teams || '' );
		url.searchParams.set( 'venue', root.dataset.venue || 'all' );
		url.searchParams.set( 'showteams', root.dataset.showTeams || '1' );

		window
			.fetch( url.toString(), { credentials: 'omit' } )
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( data ) => {
				// On any failure keep showing the current month.
				if ( data && typeof data.html === 'string' ) {
					grid.innerHTML = data.html;
					label.textContent = data.label;
					root.dataset.month = data.month;
					prev.dataset.month = data.prev;
					next.dataset.month = data.next;
				}
			} )
			.catch( () => {} )
			.then( () => {
				busy = false;
			} );
	}

	// Work out the neighbouring months for the one shown first.
	const [ year, monthNumber ] = root.dataset.month.split( '-' ).map( Number );
	const neighbour = ( offset ) => {
		const index = year * 12 + ( monthNumber - 1 ) + offset;
		return (
			String( Math.floor( index / 12 ) ).padStart( 4, '0' ) +
			'-' +
			String( ( index % 12 ) + 1 ).padStart( 2, '0' )
		);
	};
	prev.dataset.month = neighbour( -1 );
	next.dataset.month = neighbour( 1 );

	prev.addEventListener( 'click', () => load( prev.dataset.month ) );
	next.addEventListener( 'click', () => load( next.dataset.month ) );

	// The arrows only work with JavaScript, so only show them then.
	prev.hidden = false;
	next.hidden = false;
}

function initAll() {
	document.querySelectorAll( '[data-cak-month]' ).forEach( initMonth );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
