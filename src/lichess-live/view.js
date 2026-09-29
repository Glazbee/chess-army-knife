/**
 * Drives every Lichess live-games block: a scroll-snap carousel whose
 * cards are refreshed from the signed REST route, with boards loaded
 * only once their card scrolls into view.
 */
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
	let advanceTimer = null;

	if ( ! track ) {
		return;
	}

	// Load a card's board the first time it is (nearly) visible.
	const observer =
		'IntersectionObserver' in window
			? new window.IntersectionObserver(
					( entries ) => {
						entries.forEach( ( entry ) => {
							if ( ! entry.isIntersecting ) {
								return;
							}
							const frame =
								entry.target.querySelector(
									'iframe[data-src]'
								);
							if ( frame ) {
								frame.src = frame.dataset.src;
								frame.removeAttribute( 'data-src' );
							}
							observer.unobserve( entry.target );
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
				const frame = slide.querySelector( 'iframe[data-src]' );
				if ( frame ) {
					frame.src = frame.dataset.src;
					frame.removeAttribute( 'data-src' );
				}
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

		// Removing a card that still has a pending board is harmless.
		track.replaceChildren( fragment );

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
		if ( document.hidden ) {
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
	document.addEventListener( 'visibilitychange', () => {
		if ( ! document.hidden ) {
			refresh();
		}
	} );
}

function initAll() {
	document.querySelectorAll( '[data-ecf-lichess]' ).forEach( initBlock );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
