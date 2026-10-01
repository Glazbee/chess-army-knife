/**
 * Moves each month-grid calendar between months. The first month is
 * rendered on the server; the arrows fetch other months from this site.
 */
function initMonth( root ) {
	const prev = root.querySelector( '.cak-month__prev' );
	const next = root.querySelector( '.cak-month__next' );
	const label = root.querySelector( '.cak-month__label' );
	const grid = root.querySelector( '.cak-month__grid' );
	const message = root.querySelector( '.cak-month__message' );
	let busy = false;

	if ( ! prev || ! next || ! label || ! grid || ! message ) {
		return;
	}

	function load( month ) {
		if ( busy ) {
			return;
		}
		busy = true;
		message.textContent = root.dataset.msgLoading;

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
				// On any failure keep showing the current month, and say so.
				message.textContent = root.dataset.msgError;
				if ( data && typeof data.html === 'string' ) {
					message.textContent = '';
					grid.innerHTML = data.html;
					label.textContent = data.label;
					root.dataset.month = data.month;
					prev.dataset.month = data.prev;
					next.dataset.month = data.next;
				}
			} )
			.catch( () => {
				message.textContent = root.dataset.msgError;
			} )
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

/**
 * Event bubbles are details elements, so they open and close without scripts.
 * This only keeps one open at a time, and closes it on a click elsewhere or Escape.
 */
function initBubbles() {
	const closeOthers = ( keep ) => {
		document
			.querySelectorAll( '.cak-bubble[open]' )
			.forEach( ( bubble ) => {
				if ( bubble !== keep ) {
					bubble.open = false;
				}
			} );
	};

	// The toggle event does not bubble, so listen while it travels down.
	document.addEventListener(
		'toggle',
		( event ) => {
			if (
				event.target.classList &&
				event.target.classList.contains( 'cak-bubble' ) &&
				event.target.open
			) {
				closeOthers( event.target );
			}
		},
		true
	);

	document.addEventListener( 'click', ( event ) => {
		if ( ! event.target.closest( '.cak-bubble' ) ) {
			closeOthers( null );
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Escape' ) {
			return;
		}
		const open = document.querySelector( '.cak-bubble[open]' );
		if ( open ) {
			open.open = false;
			open.querySelector( 'summary' ).focus();
		}
	} );
}

function initAll() {
	document.querySelectorAll( '[data-cak-month]' ).forEach( initMonth );
	initBubbles();
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
