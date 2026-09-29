/**
 * A read-only board that follows a Lichess game by polling this site.
 *
 * The browser never contacts Lichess: it asks the WordPress server for
 * the game's current position, and the server answers from a short cache
 * so that any number of viewers cost Lichess one request per game every
 * few seconds. Polling only happens while the caller says someone is
 * actively watching. If the position can't be loaded at first, the board
 * falls back to the Lichess embed, which at least shows the game.
 */
const SVG_NS = 'http://www.w3.org/2000/svg';

// Solid glyphs for both colours (filled/stroked in CSS); the variation
// selector stops phones drawing the pawn as an emoji.
const GLYPHS = {
	k: '♚',
	q: '♛',
	r: '♜',
	b: '♝',
	n: '♞',
	p: '♟︎',
};

/**
 * Turn the piece-placement part of a FEN into { square index: piece }.
 * Index 0 is a8 and 63 is h1.
 *
 * @param {string} fen FEN (only the first field is used).
 * @return {Object} Map of square index to piece letter (case = colour).
 */
export function parsePlacement( fen ) {
	const pieces = {};
	const rows = String( fen ).split( ' ' )[ 0 ].split( '/' );
	if ( rows.length !== 8 ) {
		return pieces;
	}
	rows.forEach( ( row, rank ) => {
		let file = 0;
		for ( const char of row ) {
			if ( /[1-8]/.test( char ) ) {
				file += parseInt( char, 10 );
			} else if ( /[pnbrqkPNBRQK]/.test( char ) && file < 8 ) {
				pieces[ rank * 8 + file ] = char;
				file++;
			}
		}
	} );
	return pieces;
}

function svgEl( name, attrs ) {
	const el = document.createElementNS( SVG_NS, name );
	Object.keys( attrs ).forEach( ( key ) =>
		el.setAttribute( key, attrs[ key ] )
	);
	return el;
}

/**
 * Draw a position, highlighting squares that changed since `previous`.
 *
 * @param {HTMLElement} container Board element.
 * @param {Object}      pieces    Current position (see parsePlacement).
 * @param {Object|null} previous  Previously drawn position, if any.
 */
function drawBoard( container, pieces, previous ) {
	const svg = svgEl( 'svg', {
		viewBox: '0 0 8 8',
		class: 'ecf-lichess__svg',
		'aria-hidden': 'true',
	} );

	for ( let index = 0; index < 64; index++ ) {
		const x = index % 8;
		const y = Math.floor( index / 8 );
		svg.appendChild(
			svgEl( 'rect', {
				x,
				y,
				width: 1,
				height: 1,
				class:
					( x + y ) % 2 === 0
						? 'ecf-lichess__sq is-light'
						: 'ecf-lichess__sq is-dark',
			} )
		);
		if ( previous && previous[ index ] !== pieces[ index ] ) {
			svg.appendChild(
				svgEl( 'rect', {
					x,
					y,
					width: 1,
					height: 1,
					class: 'ecf-lichess__last',
				} )
			);
		}
		const piece = pieces[ index ];
		if ( piece ) {
			const text = svgEl( 'text', {
				x: x + 0.5,
				y: y + 0.5,
				class:
					piece === piece.toUpperCase()
						? 'ecf-lichess__piece is-white'
						: 'ecf-lichess__piece is-black',
			} );
			text.textContent = GLYPHS[ piece.toLowerCase() ];
			svg.appendChild( text );
		}
	}

	container.replaceChildren( svg );
}

function showEmbedFallback( container ) {
	const frame = document.createElement( 'iframe' );
	frame.className = 'ecf-lichess__embed';
	frame.title = container.dataset.title || 'Live Lichess game';
	frame.setAttribute(
		'sandbox',
		'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox'
	);
	frame.referrerPolicy = 'no-referrer';
	frame.src = container.dataset.embed;
	container.replaceChildren( frame );
}

/**
 * Start following a game in a board container.
 *
 * @param {HTMLElement} container Element with data-game-id and data-embed.
 * @param {Object}      options
 * @param {Function}    options.buildUrl Returns the position URL for a game id.
 * @param {number}      options.intervalMs Milliseconds between polls.
 * @param {Function}    options.isActive Returns true while someone is watching.
 * @return {Function} Call to stop following.
 */
export function startLiveBoard( container, options ) {
	const gameId = container.dataset.gameId;
	let timer = null;
	let stopped = false;
	let inFlight = false;
	let drawn = null;
	let lastFen = '';

	function schedule() {
		if ( ! stopped ) {
			timer = window.setTimeout( poll, options.intervalMs );
		}
	}

	function poll() {
		// Nobody watching: don't ask the server; check again later.
		if ( stopped || inFlight || ! options.isActive() ) {
			schedule();
			return;
		}
		inFlight = true;

		window
			.fetch( options.buildUrl( gameId ), { credentials: 'omit' } )
			.then( ( response ) => {
				if ( response.status === 404 ) {
					// The game is over: keep the last position, stop asking.
					stopped = true;
					return null;
				}
				return response.ok ? response.json() : null;
			} )
			.then( ( data ) => {
				if ( data && typeof data.fen === 'string' ) {
					if ( data.fen !== lastFen ) {
						const pieces = parsePlacement( data.fen );
						drawBoard( container, pieces, drawn );
						drawn = pieces;
						lastFen = data.fen;
					}
					if ( data.finished ) {
						stopped = true;
					}
				} else if ( ! drawn && ! stopped ) {
					showEmbedFallback( container );
					stopped = true;
				}
			} )
			.catch( () => {
				// Network trouble: keep what is shown and try again.
			} )
			.then( () => {
				inFlight = false;
				schedule();
			} );
	}

	poll();

	return function stop() {
		stopped = true;
		window.clearTimeout( timer );
	};
}
