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
