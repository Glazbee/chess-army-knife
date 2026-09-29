import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import EventTagsControl from '../shared/event-tags-control';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { title, tags, showLocation, showTags, showLinks, emptyMessage } =
		attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="next-club-event"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<EventTagsControl
					value={ tags }
					onChange={ ( value ) => setAttributes( { tags: value } ) }
				/>
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
						placeholder={ __(
							'Next club event',
							'chess-army-knife'
						) }
					/>
					<TextControl
						label={ __(
							'Message when there is no event',
							'chess-army-knife'
						) }
						value={ emptyMessage }
						onChange={ ( value ) =>
							setAttributes( { emptyMessage: value } )
						}
						placeholder={ __(
							'No upcoming events.',
							'chess-army-knife'
						) }
					/>
					<ToggleControl
						label={ __( 'Show location', 'chess-army-knife' ) }
						checked={ showLocation }
						onChange={ ( value ) =>
							setAttributes( { showLocation: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show tournaments and leagues',
							'chess-army-knife'
						) }
						checked={ showLinks }
						onChange={ ( value ) =>
							setAttributes( { showLinks: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show tags', 'chess-army-knife' ) }
						checked={ showTags }
						onChange={ ( value ) =>
							setAttributes( { showTags: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/next-club-event"
				attributes={ attributes }
			/>
		</div>
	);
}
