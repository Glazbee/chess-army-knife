import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { eventId, title } = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Display', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __(
							'Custom title (optional)',
							'chess-army-knife'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __( 'Register', 'chess-army-knife' ) }
					/>
					<TextControl
						type="number"
						label={ __(
							'Event ID (optional)',
							'chess-army-knife'
						) }
						help={ __(
							"Leave at 0 on an event's own page. Elsewhere, enter the event's ID (shown in its edit address).",
							'chess-army-knife'
						) }
						value={ eventId }
						onChange={ ( value ) =>
							setAttributes( {
								eventId: parseInt( value, 10 ) || 0,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/event-registration"
				attributes={ attributes }
			/>
		</div>
	);
}
