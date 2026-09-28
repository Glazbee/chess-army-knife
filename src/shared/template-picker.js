/**
 * Sidebar control for choosing a saved template (managed under
 * ECF & LMS → Templates) for the current block type.
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, SelectControl, ExternalLink } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function TemplatePicker( { blockSlug, value, onChange } ) {
	const [ templates, setTemplates ] = useState( [] );

	useEffect( () => {
		let cancelled = false;
		apiFetch( {
			path: `/ecf-lms/v1/templates?block=${ encodeURIComponent(
				blockSlug
			) }`,
		} )
			.then( ( items ) => ! cancelled && setTemplates( items || [] ) )
			.catch( () => ! cancelled && setTemplates( [] ) );
		return () => {
			cancelled = true;
		};
	}, [ blockSlug ] );

	const options = [
		{
			label: __(
				"— None (use this block's own settings) —",
				'chess-army-knife'
			),
			value: '',
		},
		...templates.map( ( t ) => ( { label: t.name, value: t.id } ) ),
	];

	return (
		<PanelBody
			title={ __( 'Template', 'chess-army-knife' ) }
			initialOpen={ !! value }
		>
			<SelectControl
				label={ __( 'Template', 'chess-army-knife' ) }
				value={ value || '' }
				options={ options }
				onChange={ onChange }
				help={ __(
					'Anything the template sets (settings and appearance) overrides this block; blanks fall back to the block.',
					'chess-army-knife'
				) }
			/>
			<ExternalLink href="/wp-admin/admin.php?page=chess-army-knife-templates">
				{ __( 'Manage templates', 'chess-army-knife' ) }
			</ExternalLink>
		</PanelBody>
	);
}
