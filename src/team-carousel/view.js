/**
 * Drives every team carousel on the page: shows one slide at a time,
 * wires up prev/next buttons and dot navigation, and optionally
 * auto-advances. No external dependency - the slide markup is already
 * fully rendered server-side, this just toggles visibility.
 */
function initCarousel( root ) {
	const track = root.querySelector( '.ecf-carousel__track' );
	const slides = Array.from( root.querySelectorAll( '.ecf-carousel__slide' ) );
	const dots = Array.from( root.querySelectorAll( '.ecf-carousel__dot' ) );
	const prevBtn = root.querySelector( '.ecf-carousel__prev' );
	const nextBtn = root.querySelector( '.ecf-carousel__next' );

	if ( ! track || slides.length < 2 ) {
		return; // Nothing to scroll through.
	}

	let index = slides.findIndex( ( s ) => s.classList.contains( 'is-active' ) );
	if ( index < 0 ) {
		index = 0;
	}

	const autoAdvance = root.dataset.autoAdvance === '1';
	const intervalMs = Math.max( 3, parseInt( root.dataset.interval, 10 ) || 6 ) * 1000;
	let timer = null;

	function show( newIndex ) {
		index = ( newIndex + slides.length ) % slides.length;
		slides.forEach( ( slide, i ) => slide.classList.toggle( 'is-active', i === index ) );
		dots.forEach( ( dot, i ) => dot.classList.toggle( 'is-active', i === index ) );
	}

	function next() {
		show( index + 1 );
	}
	function prev() {
		show( index - 1 );
	}

	function startTimer() {
		if ( ! autoAdvance ) {
			return;
		}
		stopTimer();
		timer = window.setInterval( next, intervalMs );
	}
	function stopTimer() {
		if ( timer ) {
			window.clearInterval( timer );
			timer = null;
		}
	}

	prevBtn && prevBtn.addEventListener( 'click', () => {
		prev();
		startTimer();
	} );
	nextBtn && nextBtn.addEventListener( 'click', () => {
		next();
		startTimer();
	} );
	dots.forEach( ( dot, i ) => {
		dot.addEventListener( 'click', () => {
			show( i );
			startTimer();
		} );
	} );

	root.addEventListener( 'mouseenter', stopTimer );
	root.addEventListener( 'mouseleave', startTimer );
	root.addEventListener( 'focusin', stopTimer );
	root.addEventListener( 'focusout', startTimer );

	show( index );
	startTimer();
}

function initAll() {
	document.querySelectorAll( '[data-ecf-carousel]' ).forEach( initCarousel );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', initAll );
} else {
	initAll();
}
