import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import EventTagsControl from '../shared/event-tags-control';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		tags,
		count,
		showLocation,
		showTags,
		showLinks,
		emptyMessage,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="club-event-calendar"
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
							'Upcoming club events',
							'chess-army-knife'
						) }
					/>
					<RangeControl
						label={ __( 'Events to show', 'chess-army-knife' ) }
						value={ count }
						onChange={ ( value ) =>
							setAttributes( { count: value } )
						}
						min={ 1 }
						max={ 50 }
					/>
					<TextControl
						label={ __(
							'Message when there are no events',
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
				block="chess-army-knife/club-event-calendar"
				attributes={ attributes }
			/>
		</div>
	);
}
