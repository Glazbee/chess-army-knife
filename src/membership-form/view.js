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

/**
 * Offer the newsletter only once there is an email address to send it to, and the
 * WhatsApp group only once there is a phone number to add. For a junior these are
 * the parent or guardian's, since the junior's own are not kept. Without a script
 * both choices always show.
 */
function toggleOptionalChoices() {
	const section = document.getElementById( 'cak-optional-section' );
	const newsletter = document.getElementById( 'cak-newsletter-choice' );
	const whatsapp = document.getElementById( 'cak-whatsapp-choice' );
	const juniorSection = document.getElementById( 'cak-junior-section' );
	if ( ! section || ! newsletter || ! whatsapp ) {
		return;
	}

	const filled = ( id ) => {
		const field = document.getElementById( id );
		return !! field && field.value.trim() !== '';
	};

	const setVisible = ( choice, visible ) => {
		choice.hidden = ! visible;
		// A hidden choice cannot be left ticked.
		if ( ! visible ) {
			choice.querySelector( 'input' ).checked = false;
		}
	};

	const update = () => {
		const isJunior = !! juniorSection && ! juniorSection.hidden;
		setVisible(
			newsletter,
			filled(
				isJunior ? 'cak-member-guardian-email' : 'cak-member-email'
			)
		);
		setVisible(
			whatsapp,
			filled(
				isJunior ? 'cak-member-guardian-phone' : 'cak-member-phone'
			)
		);
		section.hidden = newsletter.hidden && whatsapp.hidden;
	};

	[
		'cak-member-type',
		'cak-member-email',
		'cak-member-phone',
		'cak-member-guardian-email',
		'cak-member-guardian-phone',
	].forEach( ( id ) => {
		const field = document.getElementById( id );
		if ( field ) {
			field.addEventListener( 'input', update );
			field.addEventListener( 'change', update );
		}
	} );
	update();
}

onReady( focusNotice );
onReady( toggleJuniorSection );
onReady( toggleOptionalChoices );
