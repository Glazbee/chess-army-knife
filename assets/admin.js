/**
 * Admin behaviour for the Tournaments page: showing only the create-form fields that apply to the chosen
 * format. (Choosing players is in tournament-picker.js.)
 */
( function () {
	'use strict';

	/**
	 * On the create form: show only the fields that apply to the chosen format,
	 * and the knockout-stage settings only when the knockout stage is switched on.
	 *
	 * @param {Element} form The create form.
	 */
	function initCreateForm( form ) {
		var format = form.querySelector( '#format' );
		var knockout = form.querySelector( '#knockout_stage' );

		function toggle( row, visible ) {
			row.hidden = ! visible;
			row.classList.toggle( 'hidden', ! visible );
		}

		function update() {
			Array.prototype.forEach.call(
				form.querySelectorAll( '[data-cak-formats]' ),
				function ( row ) {
					var formats = row
						.getAttribute( 'data-cak-formats' )
						.split( ' ' );
					var visible = formats.indexOf( format.value ) !== -1;
					if (
						visible &&
						row.hasAttribute( 'data-cak-needs-knockout' )
					) {
						visible = knockout.checked;
					}
					toggle( row, visible );
				}
			);
		}

		format.addEventListener( 'change', update );
		knockout.addEventListener( 'change', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-cak-create-form]' ),
			initCreateForm
		);
	} );
} )();
