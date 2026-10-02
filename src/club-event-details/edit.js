import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { eventId, showLocation, showTags, showLinks, showTeams } =
		attributes;

	const blockProps = useBlockProps();

	const events = useSelect(
		( select ) =>
			select( 'core' ).getEntityRecords( 'postType', 'chess_army_event', {
				per_page: 100,
				status: 'publish',
				orderby: 'title',
				order: 'asc',
			} ),
		[]
	);

	const options = [
		{
			label: __(
				'The event this page is attached to',
				'chess-army-knife'
			),
			value: 0,
		},
		...( events || [] ).map( ( event ) => ( {
			label: event.title.rendered || `#${ event.id }`,
			value: event.id,
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Event', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Which event', 'chess-army-knife' ) }
						help={ __(
							'On a page attached to an event, leave this on the first choice and it finds the event by itself.',
							'chess-army-knife'
						) }
						value={ eventId }
						options={ options }
						onChange={ ( value ) =>
							setAttributes( { eventId: parseInt( value, 10 ) } )
						}
					/>
					<ToggleControl
						label={ __( 'Show location', 'chess-army-knife' ) }
						checked={ showLocation }
						onChange={ ( value ) =>
							setAttributes( { showLocation: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show team', 'chess-army-knife' ) }
						checked={ showTeams }
						onChange={ ( value ) =>
							setAttributes( { showTeams: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show tournaments, leagues and the calendar link',
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
				block="chess-army-knife/club-event-details"
				attributes={ attributes }
			/>
		</div>
	);
}
