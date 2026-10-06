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
