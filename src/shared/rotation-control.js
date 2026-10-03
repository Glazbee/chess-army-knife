/**
 * Sidebar controls for a block that can rotate through the club's members: how often it moves on to
 * another member whose rating has risen, and over how many days the rise is measured.
 */
import { __ } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';

const ROTATION_OPTIONS = [
	{
		label: __( 'Never: show the member I choose', 'chess-army-knife' ),
		value: 'none',
	},
	{ label: __( 'Every hour', 'chess-army-knife' ), value: 'hour' },
	{ label: __( 'Every day', 'chess-army-knife' ), value: 'day' },
	{
		label: __( 'Every week (from Monday)', 'chess-army-knife' ),
		value: 'week',
	},
	{ label: __( 'Every month', 'chess-army-knife' ), value: 'month' },
];

export default function RotationControl( { attributes, setAttributes } ) {
	const rotating = attributes.rotation && 'none' !== attributes.rotation;

	return (
		<>
			<SelectControl
				label={ __( 'Rotate to another member', 'chess-army-knife' ) }
				help={ __(
					'Rotating picks, in a random order, a current member whose rating has risen over the period below.',
					'chess-army-knife'
				) }
				value={ attributes.rotation || 'none' }
				options={ ROTATION_OPTIONS }
				onChange={ ( value ) => setAttributes( { rotation: value } ) }
			/>
			{ rotating && (
				<TextControl
					type="number"
					min={ 1 }
					max={ 365 }
					label={ __( 'Days to look back', 'chess-army-knife' ) }
					help={ __(
						'Leave empty for the site default. Only members whose rating went up in this many days are included.',
						'chess-army-knife'
					) }
					value={ attributes.daysBack || '' }
					onChange={ ( value ) =>
						setAttributes( {
							daysBack: Math.max(
								0,
								Math.min( 365, parseInt( value, 10 ) || 0 )
							),
						} )
					}
				/>
			) }
		</>
	);
}
