/**
 * The member form: a parent or guardian who is not a member has their details typed in; for a member or for no
 * one, those rows are not needed, so they are hidden.
 */
( function () {
	var choice = document.getElementById( 'guardian_choice' );
	var rows = document.querySelectorAll( '.cak-guardian-typed' );
	if ( ! choice || ! rows.length ) {
		return;
	}

	function show() {
		Array.prototype.forEach.call( rows, function ( row ) {
			row.style.display = choice.value === 'nonmember' ? '' : 'none';
		} );
	}

	choice.addEventListener( 'change', show );
	show();
} )();

// The date of birth is only asked for when a junior membership type is chosen.
( function () {
	var type = document.getElementById( 'membership_type_id' );
	var row = document.getElementById( 'cak-dob-row' );
	var dob = document.getElementById( 'date_of_birth' );
	if ( ! type || ! row || ! dob ) {
		return;
	}

	function show() {
		var option = type.options[ type.selectedIndex ];
		row.style.display = dob.value || ( option && option.getAttribute( 'data-junior' ) === '1' ) ? '' : 'none';
	}

	type.addEventListener( 'change', show );
	show();
} )();

// The newsletter needs an email address and a WhatsApp group a phone number, so each is only offered once there is one.
// A parent or guardian who is a member has their own details used, so that choice leaves both on offer.
( function () {
	var row = document.getElementById( 'cak-extras-row' );
	var newsletter = document.getElementById( 'cak-newsletter-choice' );
	var whatsapp = document.getElementById( 'cak-whatsapp-choice' );
	var guardian = document.getElementById( 'guardian_choice' );
	if ( ! row || ! newsletter || ! whatsapp ) {
		return;
	}

	function filled( id ) {
		var field = document.getElementById( id );
		return !! field && field.value.trim() !== '';
	}

	function setVisible( choice, visible ) {
		choice.style.display = visible ? 'block' : 'none';
		// A hidden choice cannot be left ticked.
		if ( ! visible ) {
			choice.querySelector( 'input' ).checked = false;
		}
	}

	function update() {
		var memberGuardian = !! guardian && /^\d+$/.test( guardian.value );
		var typedGuardian = !! guardian && guardian.value === 'nonmember';
		setVisible( newsletter, memberGuardian || filled( 'email' ) || ( typedGuardian && filled( 'guardian_email' ) ) );
		setVisible( whatsapp, memberGuardian || filled( 'phone' ) || ( typedGuardian && filled( 'guardian_phone' ) ) );
		row.style.display = newsletter.style.display === 'none' && whatsapp.style.display === 'none' ? 'none' : '';
	}

	[ 'email', 'phone', 'guardian_email', 'guardian_phone', 'guardian_choice' ].forEach( function ( id ) {
		var field = document.getElementById( id );
		if ( field ) {
			field.addEventListener( 'input', update );
			field.addEventListener( 'change', update );
		}
	} );
	update();
} )();
