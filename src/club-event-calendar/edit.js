import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import EventTagsControl from '../shared/event-tags-control';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		tags,
		layout,
		count,
		showLocation,
		showTags,
		showLinks,
		showTeams,
		teamIds,
		venue,
		showSubscribe,
		emptyMessage,
	} = attributes;
	const [ teams, setTeams ] = useState( [] );

	const blockProps = useBlockProps();

	useEffect( () => {
		apiFetch( { path: '/ecf-lms/v1/teams' } )
			.then( setTeams )
			.catch( () => setTeams( [] ) );
	}, [] );

	const toggleTeam = ( id, checked ) =>
		setAttributes( {
			teamIds: checked
				? [ ...teamIds, id ]
				: teamIds.filter( ( teamId ) => teamId !== id ),
		} );

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
					<SelectControl
						label={ __( 'Layout', 'chess-army-knife' ) }
						value={ layout }
						options={ [
							{
								label: __(
									'Agenda (list by date)',
									'chess-army-knife'
								),
								value: 'agenda',
							},
							{
								label: __( 'Month grid', 'chess-army-knife' ),
								value: 'month',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					{ layout !== 'month' && (
						<RangeControl
							label={ __( 'Events to show', 'chess-army-knife' ) }
							value={ count }
							onChange={ ( value ) =>
								setAttributes( { count: value } )
							}
							min={ 1 }
							max={ 50 }
						/>
					) }
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
				<PanelBody
					title={ __( 'Teams and fixtures', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					{ teams.length > 0 && (
						<fieldset>
							<legend>
								{ __(
									'Only these teams (none ticked shows everything)',
									'chess-army-knife'
								) }
							</legend>
							{ teams.map( ( team ) => (
								<CheckboxControl
									key={ team.id }
									label={ team.name }
									checked={ teamIds.includes( team.id ) }
									onChange={ ( checked ) =>
										toggleTeam( team.id, checked )
									}
								/>
							) ) }
						</fieldset>
					) }
					<SelectControl
						label={ __( 'Home or away', 'chess-army-knife' ) }
						help={ __(
							'Fixtures come from the Import Events page and are linked to teams set up under Memberships → Teams.',
							'chess-army-knife'
						) }
						value={ venue }
						options={ [
							{
								label: __( 'All fixtures', 'chess-army-knife' ),
								value: 'all',
							},
							{
								label: __( 'Home only', 'chess-army-knife' ),
								value: 'home',
							},
							{
								label: __( 'Away only', 'chess-army-knife' ),
								value: 'away',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { venue: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show team and home/away',
							'chess-army-knife'
						) }
						checked={ showTeams }
						onChange={ ( value ) =>
							setAttributes( { showTeams: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show calendar subscription link',
							'chess-army-knife'
						) }
						help={ __(
							'Adds an .ics link for this selection, for calendar apps.',
							'chess-army-knife'
						) }
						checked={ showSubscribe }
						onChange={ ( value ) =>
							setAttributes( { showSubscribe: value } )
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
