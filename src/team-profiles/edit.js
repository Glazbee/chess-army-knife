import { __, sprintf } from '@wordpress/i18n';
import useClubName from '../shared/use-club-name';
import TemplatePicker from '../shared/template-picker';
import {
	useBlockProps,
	InspectorControls,
	PanelColorSettings,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	RangeControl,
	ToggleControl,
	CheckboxControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';

const HEADING_LEVELS = [
	{ label: __( 'Automatic', 'chess-army-knife' ), value: 0 },
	...[ 1, 2, 3, 4, 5, 6 ].map( ( level ) => ( {
		label: 'H' + level,
		value: level,
	} ) ),
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		showTitle,
		teamId,
		heroTeams,
		showDescription,
		columns,
		nameFormat,
		groupBy,
		showSeparators,
		groupHeadingLevel,
		teamHeadingLevel,
		showPlayers,
		playerSort,
		showRatings,
		ratingColor,
		ratingBold,
		ratingItalic,
		ratingSize,
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
					<ToggleControl
						label={ __( 'Show block title', 'chess-army-knife' ) }
						help={ __(
							'Turn off to use the page title instead.',
							'chess-army-knife'
						) }
						checked={ showTitle }
						onChange={ ( value ) =>
							setAttributes( { showTitle: value } )
						}
					/>
					{ showTitle && (
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
					) }
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
						label={ __(
							'Show team descriptions',
							'chess-army-knife'
						) }
						help={ __(
							"A team's description is set on its page under Teams.",
							'chess-army-knife'
						) }
						checked={ showDescription }
						onChange={ ( value ) =>
							setAttributes( { showDescription: value } )
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
				<PanelBody
					title={ __( 'Groups and headings', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					<SelectControl
						label={ __( 'Group teams by', 'chess-army-knife' ) }
						help={ __(
							'Groups, their blurbs, their teams and the order of those teams are set under Teams → Groups.',
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
					<ToggleControl
						label={ __(
							'Separator between groups',
							'chess-army-knife'
						) }
						checked={ showSeparators }
						onChange={ ( value ) =>
							setAttributes( { showSeparators: value } )
						}
					/>
					<SelectControl
						label={ __( 'Group heading', 'chess-army-knife' ) }
						value={ groupHeadingLevel }
						options={ HEADING_LEVELS }
						onChange={ ( value ) =>
							setAttributes( {
								groupHeadingLevel: parseInt( value, 10 ),
							} )
						}
					/>
					<SelectControl
						label={ __( 'Team heading', 'chess-army-knife' ) }
						help={ __(
							'Automatic follows the heading level set for the whole site.',
							'chess-army-knife'
						) }
						value={ teamHeadingLevel }
						options={ HEADING_LEVELS }
						onChange={ ( value ) =>
							setAttributes( {
								teamHeadingLevel: parseInt( value, 10 ),
							} )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Hero teams', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					<p>
						{ __(
							'A hero team gets a row to itself, with no other columns.',
							'chess-army-knife'
						) }
					</p>
					{ teams.map( ( team ) => (
						<CheckboxControl
							key={ team.id }
							label={ team.name }
							checked={ heroTeams.includes( team.id ) }
							onChange={ ( checked ) =>
								setAttributes( {
									heroTeams: checked
										? [ ...heroTeams, team.id ]
										: heroTeams.filter(
												( id ) => id !== team.id
										  ),
								} )
							}
						/>
					) ) }
				</PanelBody>
				<PanelBody
					title={ __( 'Players', 'chess-army-knife' ) }
					initialOpen={ false }
				>
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
					{ showPlayers && (
						<>
							<SelectControl
								label={ __(
									'Sort players',
									'chess-army-knife'
								) }
								value={ playerSort }
								options={ [
									{
										label: __(
											'By surname',
											'chess-army-knife'
										),
										value: 'surname',
									},
									{
										label: __(
											'By rating, highest first',
											'chess-army-knife'
										),
										value: 'rating',
									},
								] }
								onChange={ ( value ) =>
									setAttributes( { playerSort: value } )
								}
							/>
							<ToggleControl
								label={ __(
									'Show ratings',
									'chess-army-knife'
								) }
								checked={ showRatings }
								onChange={ ( value ) =>
									setAttributes( { showRatings: value } )
								}
							/>
							{ showRatings && (
								<>
									<ToggleControl
										label={ __(
											'Bold',
											'chess-army-knife'
										) }
										checked={ ratingBold }
										onChange={ ( value ) =>
											setAttributes( {
												ratingBold: value,
											} )
										}
									/>
									<ToggleControl
										label={ __(
											'Italic',
											'chess-army-knife'
										) }
										checked={ ratingItalic }
										onChange={ ( value ) =>
											setAttributes( {
												ratingItalic: value,
											} )
										}
									/>
									<SelectControl
										label={ __(
											'Rating size',
											'chess-army-knife'
										) }
										value={ ratingSize }
										options={ [
											{
												label: __(
													'Same as the name',
													'chess-army-knife'
												),
												value: '',
											},
											{
												label: __(
													'Smaller',
													'chess-army-knife'
												),
												value: '0.85em',
											},
											{
												label: __(
													'Larger',
													'chess-army-knife'
												),
												value: '1.15em',
											},
										] }
										onChange={ ( value ) =>
											setAttributes( {
												ratingSize: value,
											} )
										}
									/>
								</>
							) }
						</>
					) }
				</PanelBody>
				{ showPlayers && showRatings && (
					<PanelColorSettings
						title={ __( 'Rating colour', 'chess-army-knife' ) }
						initialOpen={ false }
						colorSettings={ [
							{
								value: ratingColor,
								onChange: ( value ) =>
									setAttributes( {
										ratingColor: value || '',
									} ),
								label: __( 'Rating', 'chess-army-knife' ),
							},
						] }
					/>
				) }
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-profiles"
				attributes={ attributes }
			/>
		</div>
	);
}
