/**
 * Drives every Lichess live-games block: a scroll-snap carousel whose
 * cards are refreshed from the signed REST route, with each board
 * following its game live while its card is on screen.
 */
import { startLiveBoard } from './live-board';

// Stop refreshing after this long without any interaction.
const IDLE_LIMIT_MS = 5 * 60 * 1000;

function initBlock( root ) {
	const carousel = root.querySelector( '.ecf-lichess__carousel' );
	const track = root.querySelector( '.ecf-lichess__track' );
	const empty = root.querySelector( '.ecf-lichess__empty' );
	const prevBtn = root.querySelector( '.ecf-lichess__prev' );
	const nextBtn = root.querySelector( '.ecf-lichess__next' );
	const pollMs =
		Math.max( 30, parseInt( root.dataset.poll, 10 ) || 45 ) * 1000;
	const autoAdvance = root.dataset.autoAdvance === '1';
	const advanceMs =
		Math.max( 3, parseInt( root.dataset.interval, 10 ) || 8 ) * 1000;
	const positionPollMs =
		Math.max( 4, parseInt( root.dataset.positionPoll, 10 ) || 6 ) * 1000;
	let advanceTimer = null;

	// Only talk to the server while someone is actively watching: tab
	// visible, block on screen, and some activity in the last few minutes.
	let lastActivity = Date.now();
	let blockOnScreen = true;
	function isActive() {
		return (
			! document.hidden &&
			blockOnScreen &&
			Date.now() - lastActivity < IDLE_LIMIT_MS
		);
	}

	if ( ! track ) {
		return;
	}

	// Follow a game live only while its card is (nearly) on screen.
	const streams = new Map();

	function startBoard( slide ) {
		const board = slide.querySelector( '.ecf-lichess__board' );
		if ( board && ! streams.has( slide ) ) {
			streams.set(
				slide,
				startLiveBoard( board, {
					intervalMs: positionPollMs,
					isActive,
					buildUrl: ( gameId ) => {
						const url = new URL(
							root.dataset.positionEndpoint,
							window.location.href
						);
						url.searchParams.set( 'users', root.dataset.users );
						url.searchParams.set( 'board', root.dataset.board );
						url.searchParams.set( 'sig', root.dataset.sig );
						url.searchParams.set( 'game', gameId );
						return url.toString();
					},
				} )
			);
		}
	}

	function stopBoard( slide ) {
		const stop = streams.get( slide );
		if ( stop ) {
			stop();
			streams.delete( slide );
		}
	}

	const observer =
		'IntersectionObserver' in window
			? new window.IntersectionObserver(
					( entries ) => {
						entries.forEach( ( entry ) => {
							if ( entry.isIntersecting ) {
								startBoard( entry.target );
							} else {
								stopBoard( entry.target );
							}
						} );
					},
					{ root: track, rootMargin: '0px 100px' }
			  )
			: null;

	function watchSlides() {
		track.querySelectorAll( '.ecf-lichess__slide' ).forEach( ( slide ) => {
			if ( observer ) {
				observer.observe( slide );
			} else {
				startBoard( slide );
			}
		} );
	}

	function scrollByCard( direction ) {
		const slide = track.querySelector( '.ecf-lichess__slide' );
		if ( ! slide ) {
			return;
		}
		const atEnd =
			track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
		if ( direction > 0 && atEnd ) {
			track.scrollTo( { left: 0, behavior: 'smooth' } );
			return;
		}
		track.scrollBy( {
			left: direction * slide.offsetWidth,
			behavior: 'smooth',
		} );
	}

	function stopAdvance() {
		if ( advanceTimer ) {
			window.clearInterval( advanceTimer );
			advanceTimer = null;
		}
	}
	function startAdvance() {
		if ( ! autoAdvance ) {
			return;
		}
		stopAdvance();
		advanceTimer = window.setInterval( () => scrollByCard( 1 ), advanceMs );
	}

	prevBtn &&
		prevBtn.addEventListener( 'click', () => {
			scrollByCard( -1 );
			startAdvance();
		} );
	nextBtn &&
		nextBtn.addEventListener( 'click', () => {
			scrollByCard( 1 );
			startAdvance();
		} );
	root.addEventListener( 'mouseenter', stopAdvance );
	root.addEventListener( 'mouseleave', startAdvance );
	root.addEventListener( 'focusin', stopAdvance );
	root.addEventListener( 'focusout', startAdvance );

	// Swap in the latest games, keeping cards (and their loaded boards)
	// for games still in progress.
	function applySlides( slides ) {
		const existing = {};
		track.querySelectorAll( '.ecf-lichess__slide' ).forEach( ( el ) => {
			existing[ el.dataset.gameId ] = el;
		} );

		const fragment = document.createDocumentFragment();
		slides.forEach( ( slide ) => {
			if ( existing[ slide.id ] ) {
				fragment.appendChild( existing[ slide.id ] );
				return;
			}
			const holder = document.createElement( 'div' );
			holder.innerHTML = slide.html;
			if ( holder.firstElementChild ) {
				fragment.appendChild( holder.firstElementChild );
			}
		} );

		track.replaceChildren( fragment );

		// Games that have ended no longer need polling.
		streams.forEach( ( stop, slide ) => {
			if ( ! track.contains( slide ) ) {
				stopBoard( slide );
			}
		} );

		const hasGames = slides.length > 0;
		if ( carousel ) {
			carousel.hidden = ! hasGames;
		}
		if ( empty ) {
			empty.hidden = hasGames;
		}
		watchSlides();
	}

	function refresh() {
		if ( ! isActive() ) {
			return;
		}
		const url = new URL( root.dataset.endpoint, window.location.href );
		url.searchParams.set( 'users', root.dataset.users );
		url.searchParams.set( 'board', root.dataset.board );
		url.searchParams.set( 'sig', root.dataset.sig );

		window
			.fetch( url.toString(), { credentials: 'omit' } )
			.then( ( response ) => ( response.ok ? response.json() : null ) )
			.then( ( data ) => {
				// On any failure keep showing what we have.
				if ( data && Array.isArray( data.slides ) ) {
					applySlides( data.slides );
				}
			} )
			.catch( () => {} );
	}

	watchSlides();
	startAdvance();
	window.setInterval( refresh, pollMs );

	// Note activity; coming back after being idle refreshes straight away.
	function noteActivity() {
		const wasIdle = ! isActive();
		lastActivity = Date.now();
		if ( wasIdle && isActive() ) {
			refresh();
		}
	}
	[ 'pointermove', 'pointerdown', 'keydown', 'scroll', 'touchstart' ].forEach(
		( type ) =>
			window.addEventListener( type, noteActivity, {
				passive: true,
			} )
	);
	document.addEventListener( 'visibilitychange', noteActivity );

	if ( 'IntersectionObserver' in window ) {
		new window.IntersectionObserver( ( entries ) => {
			const visible = entries.some( ( entry ) => entry.isIntersecting );
			const wasHidden = ! blockOnScreen;
			blockOnScreen = visible;
			if ( visible && wasHidden ) {
				refresh();
			}
		} ).observe( root );
	}
}

function initAll() {
	document.querySelectorAll( '[data-ecf-lichess]' ).forEach( initBlock );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
