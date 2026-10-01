/**
 * Turns a fixtures list into a carousel for people who want one. The list is
 * the page without this script, so nothing is lost if it does not run.
 *
 * - One team shows at a time, with Previous and Next buttons and a button for
 *   each team.
 * - Moving on by itself is off unless the block asks for it. It never starts
 *   for anyone whose device asks for less motion, it stops for good when a
 *   visitor uses any button, and a Pause/Start button is always there.
 * - A screen reader is told about a change only when a visitor causes it.
 */
function initCarousel( root ) {
	const list = root.querySelector( '.ecf-carousel__list' );
	const slides = Array.from( list ? list.children : [] );
	if ( slides.length < 2 ) {
		return;
	}

	const text = root.dataset;
	const reduceMotion = window.matchMedia(
		'(prefers-reduced-motion: reduce)'
	).matches;
	const intervalMs = Math.max( 5, parseInt( text.interval, 10 ) || 8 ) * 1000;
	let index = 0;
	let timer = null;
	let paused = false;
	// Whether the visitor has taken over, or never asked for moving on.
	let playing = text.auto === '1' && ! reduceMotion;

	root.classList.add( 'is-carousel' );
	list.setAttribute( 'aria-live', 'off' );

	function name( slide ) {
		const heading = slide.firstElementChild;
		return heading ? heading.textContent.trim().replace( /\s+/g, ' ' ) : '';
	}

	slides.forEach( ( slide, i ) => {
		slide.setAttribute( 'aria-roledescription', text.msgSlideWord );
		slide.setAttribute(
			'aria-label',
			text.msgSlide
				.replace( '%1$s', i + 1 )
				.replace( '%2$s', slides.length )
				.replace( '%3$s', name( slide ) )
		);
	} );

	// Controls.
	const controls = document.createElement( 'div' );
	controls.className = 'ecf-carousel__controls';
	controls.setAttribute( 'role', 'group' );
	controls.setAttribute( 'aria-label', text.msgControls );

	function button( label, className, glyph ) {
		const el = document.createElement( 'button' );
		el.type = 'button';
		el.className = className;
		el.setAttribute( 'aria-label', label );
		if ( glyph ) {
			const span = document.createElement( 'span' );
			span.setAttribute( 'aria-hidden', 'true' );
			span.textContent = glyph;
			el.appendChild( span );
		}
		return el;
	}

	const previous = button(
		text.msgPrevious,
		'ecf-carousel__button ecf-carousel__previous',
		'‹'
	);
	const next = button(
		text.msgNext,
		'ecf-carousel__button ecf-carousel__next',
		'›'
	);
	const toggle = button( '', 'ecf-carousel__button ecf-carousel__toggle' );
	const dots = document.createElement( 'div' );
	dots.className = 'ecf-carousel__dots';
	const dotButtons = slides.map( ( slide, i ) => {
		const dot = button(
			text.msgShow.replace( '%s', name( slide ) ),
			'ecf-carousel__dot'
		);
		dot.addEventListener( 'click', () => {
			stop();
			show( i, true );
		} );
		dots.appendChild( dot );
		return dot;
	} );

	controls.append( previous, toggle, next );
	root.insertBefore( controls, list );
	root.appendChild( dots );

	function show( newIndex, announce ) {
		index = ( newIndex + slides.length ) % slides.length;
		slides.forEach( ( slide, i ) => {
			slide.hidden = i !== index;
		} );
		dotButtons.forEach( ( dot, i ) => {
			if ( i === index ) {
				dot.setAttribute( 'aria-current', 'true' );
			} else {
				dot.removeAttribute( 'aria-current' );
			}
		} );
		// Only changes the visitor asked for are read out.
		list.setAttribute( 'aria-live', announce ? 'polite' : 'off' );
	}

	function setToggle() {
		toggle.setAttribute(
			'aria-label',
			playing ? text.msgPause : text.msgPlay
		);
		toggle.textContent = '';
		const span = document.createElement( 'span' );
		span.setAttribute( 'aria-hidden', 'true' );
		span.textContent = playing ? '❚❚' : '▶';
		toggle.appendChild( span );
	}

	function start() {
		stop();
		paused = false;
		playing = true;
		setToggle();
		timer = window.setInterval(
			() => show( index + 1, false ),
			intervalMs
		);
	}

	function stop() {
		window.clearInterval( timer );
		timer = null;
		playing = false;
		setToggle();
	}

	previous.addEventListener( 'click', () => {
		stop();
		show( index - 1, true );
	} );
	next.addEventListener( 'click', () => {
		stop();
		show( index + 1, true );
	} );
	toggle.addEventListener( 'click', () => ( playing ? stop() : start() ) );

	// Do not move on while someone is reading or using the keyboard here.
	function hold() {
		if ( timer ) {
			window.clearInterval( timer );
			timer = null;
			paused = true;
		}
	}
	function release() {
		if ( paused && playing ) {
			timer = window.setInterval(
				() => show( index + 1, false ),
				intervalMs
			);
		}
		paused = false;
	}
	root.addEventListener( 'mouseenter', hold );
	root.addEventListener( 'mouseleave', release );
	root.addEventListener( 'focusin', hold );
	root.addEventListener( 'focusout', release );

	show( 0, false );
	if ( playing ) {
		start();
	} else {
		setToggle();
	}
}

function init() {
	document.querySelectorAll( '[data-cak-carousel]' ).forEach( initCarousel );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
