import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Disabled, PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { title, introText, consentText } = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Form', 'chess-army-knife' ) }
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
						placeholder={ __(
							'Apply for membership',
							'chess-army-knife'
						) }
					/>
					<TextControl
						label={ __(
							'Introduction (optional)',
							'chess-army-knife'
						) }
						value={ introText }
						onChange={ ( value ) =>
							setAttributes( { introText: value } )
						}
					/>
					<TextControl
						label={ __(
							'Consent wording (optional)',
							'chess-army-knife'
						) }
						help={ __(
							'What people agree to when they apply, for example a link to your privacy policy.',
							'chess-army-knife'
						) }
						value={ consentText }
						onChange={ ( value ) =>
							setAttributes( { consentText: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<Disabled>
				<ServerSideRender
					block="chess-army-knife/membership-form"
					attributes={ attributes }
				/>
			</Disabled>
		</div>
	);
}
