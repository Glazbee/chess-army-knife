import { focusNotice, onReady } from '../shared/focus-notice';

/**
 * Show the juniors section only when a junior membership is chosen. Without
 * a script the section always shows, so nobody is left without the fields.
 */
function toggleJuniorSection() {
	const select = document.getElementById( 'cak-member-type' );
	const section = document.getElementById( 'cak-junior-section' );
	if ( ! select || ! section ) {
		return;
	}

	const update = () => {
		const option = select.options[ select.selectedIndex ];
		const isJunior = !! option && option.dataset.junior === '1';
		section.hidden = ! isJunior;
		// The junior type already says who the form is for, so the tick box is not needed.
		const tick = section.querySelector( '[data-cak-junior-tick]' );
		if ( tick ) {
			tick.hidden = isJunior;
		}
	};

	select.addEventListener( 'change', update );
	update();
}

onReady( focusNotice );
onReady( toggleJuniorSection );
