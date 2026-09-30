import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { title, showDescription, showPrice, showPaymentInfo, joinUrl } =
		attributes;

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
						placeholder={ __( 'Membership', 'chess-army-knife' ) }
					/>
					<ToggleControl
						label={ __( 'Show descriptions', 'chess-army-knife' ) }
						checked={ showDescription }
						onChange={ ( value ) =>
							setAttributes( { showDescription: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show prices', 'chess-army-knife' ) }
						checked={ showPrice }
						onChange={ ( value ) =>
							setAttributes( { showPrice: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show how to pay', 'chess-army-knife' ) }
						help={ __(
							'The payment instructions are set under ECF & LMS → Settings.',
							'chess-army-knife'
						) }
						checked={ showPaymentInfo }
						onChange={ ( value ) =>
							setAttributes( { showPaymentInfo: value } )
						}
					/>
					<TextControl
						label={ __(
							'Application form page (optional)',
							'chess-army-knife'
						) }
						help={ __(
							'The address of the page with the Membership Application Form block. Each membership then gets an apply link.',
							'chess-army-knife'
						) }
						type="url"
						value={ joinUrl }
						onChange={ ( value ) =>
							setAttributes( { joinUrl: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/memberships"
				attributes={ attributes }
			/>
		</div>
	);
}
