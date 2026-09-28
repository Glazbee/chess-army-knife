import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, RangeControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import TemplatePicker from '../shared/template-picker';

export default function Edit( { attributes, setAttributes } ) {
	const { title, limit } = attributes;
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Past winners', 'chess-army-knife' ) }>
					<TextControl
						label={ __( 'Title', 'chess-army-knife' ) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					<RangeControl
						label={ __(
							'Tournaments to show',
							'chess-army-knife'
						) }
						value={ limit }
						min={ 1 }
						max={ 50 }
						onChange={ ( value ) =>
							setAttributes( { limit: value || 10 } )
						}
					/>
				</PanelBody>
				<TemplatePicker
					blockSlug="tournament-winners"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
			</InspectorControls>
			<ServerSideRender
				block="chess-army-knife/tournament-winners"
				attributes={ attributes }
			/>
		</div>
	);
}
