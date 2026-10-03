import { __, sprintf } from '@wordpress/i18n';
import useClubName from '../shared/use-club-name';
import TemplatePicker from '../shared/template-picker';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	RangeControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		teamId,
		columns,
		nameFormat,
		groupBy,
		showPlayers,
		showLeagues,
	} = attributes;
	const [ teams, setTeams ] = useState( [] );

	const blockProps = useBlockProps();
	const clubName = useClubName();

	useEffect( () => {
		apiFetch( { path: '/chess-army-knife/v1/teams' } )
			.then( setTeams )
			.catch( () => setTeams( [] ) );
	}, [] );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="team-profiles"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
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
						help={ __(
							"Use {club} for the club's name.",
							'chess-army-knife'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ sprintf(
							__( '%s teams', 'chess-army-knife' ),
							clubName
						) }
					/>
					<SelectControl
						label={ __( 'Team', 'chess-army-knife' ) }
						help={ __(
							'Teams are set up under Teams.',
							'chess-army-knife'
						) }
						value={ teamId }
						options={ [
							{
								label: __( 'All teams', 'chess-army-knife' ),
								value: 0,
							},
							...teams.map( ( team ) => ( {
								label: team.name,
								value: team.id,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { teamId: parseInt( value, 10 ) } )
						}
					/>
					<RangeControl
						label={ __( 'Columns', 'chess-army-knife' ) }
						value={ columns }
						min={ 1 }
						max={ 4 }
						onChange={ ( value ) =>
							setAttributes( { columns: value } )
						}
					/>
					<SelectControl
						label={ __( 'Group teams by', 'chess-army-knife' ) }
						help={ __(
							"A team's group is set under Teams.",
							'chess-army-knife'
						) }
						value={ groupBy }
						options={ [
							{
								label: __( 'No grouping', 'chess-army-knife' ),
								value: 'none',
							},
							{
								label: __( 'Group', 'chess-army-knife' ),
								value: 'group',
							},
							{
								label: __( 'Division', 'chess-army-knife' ),
								value: 'division',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { groupBy: value } )
						}
					/>
					<TextControl
						label={ __(
							'Team heading format',
							'chess-army-knife'
						) }
						help={ __(
							'Use {league} for the first league and {team} for the name, e.g. "{league} - {team}".',
							'chess-army-knife'
						) }
						value={ nameFormat }
						placeholder="{team}"
						onChange={ ( value ) =>
							setAttributes( { nameFormat: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show players', 'chess-army-knife' ) }
						help={ __(
							'Lists the squad and captain of each team shown.',
							'chess-army-knife'
						) }
						checked={ showPlayers }
						onChange={ ( value ) =>
							setAttributes( { showPlayers: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show leagues list', 'chess-army-knife' ) }
						checked={ showLeagues }
						onChange={ ( value ) =>
							setAttributes( { showLeagues: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-profiles"
				attributes={ attributes }
			/>
		</div>
	);
}
