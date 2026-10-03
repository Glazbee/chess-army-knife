import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * How a block writes people's names: the site's setting (Settings → Names) or its own choice.
 */
export default function NameFormatControl( { value, onChange } ) {
	return (
		<SelectControl
			label={ __( 'How names are written', 'chess-army-knife' ) }
			value={ value || '' }
			options={ [
				{
					label: __( 'Same as the site setting', 'chess-army-knife' ),
					value: '',
				},
				{
					label: __( 'Firstname Surname', 'chess-army-knife' ),
					value: 'first_surname',
				},
				{
					label: __(
						'Surname, Firstname Initials',
						'chess-army-knife'
					),
					value: 'surname_first',
				},
			] }
			onChange={ onChange }
		/>
	);
}
