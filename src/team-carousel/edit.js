import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	Notice,
	ExternalLink,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const SOURCE_OPTIONS = [
	{
		label: __(
			"My club's teams (managed in Settings)",
			'chess-army-knife'
		),
		value: 'club-teams',
	},
	{
		label: __(
			"Automatic — every team in one event's table",
			'chess-army-knife'
		),
		value: 'auto',
	},
	{
		label: __( 'Manual list, one event', 'chess-army-knife' ),
		value: 'manual',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { orgId, eventName, teamSource, manualTeams, title, highlightTeam } =
		attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="team-carousel"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				{ teamSource !== 'club-teams' && (
					<PanelBody
						title={ __( 'League / event', 'chess-army-knife' ) }
						initialOpen={ true }
					>
						<TextControl
							label={ __(
								'LMS organisation ID',
								'chess-army-knife'
							) }
							help={ __(
								'Leave blank to use the site-wide default, if one is set.',
								'chess-army-knife'
							) }
							value={ orgId }
							onChange={ ( value ) =>
								setAttributes( {
									orgId: value.replace( /[^0-9]/g, '' ),
								} )
							}
							placeholder={ __( 'e.g. 613', 'chess-army-knife' ) }
						/>
						<TextControl
							label={ __(
								'Event / division name',
								'chess-army-knife'
							) }
							help={ __(
								'Leave blank to use the site-wide default, if one is set.',
								'chess-army-knife'
							) }
							value={ eventName }
							onChange={ ( value ) =>
								setAttributes( { eventName: value } )
							}
							placeholder={ __(
								'e.g. Division 1',
								'chess-army-knife'
							) }
						/>
					</PanelBody>
				) }
				<PanelBody
					title={ __( 'Teams', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Team source', 'chess-army-knife' ) }
						value={ teamSource }
						options={ SOURCE_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { teamSource: value } )
						}
					/>
					{ teamSource === 'club-teams' && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Reads the leagues of your teams (under Teams), so it can cover teams across several divisions or organisations at once — including more than one team in the same division.',
								'chess-army-knife'
							) }{ ' ' }
							<ExternalLink href="/wp-admin/edit.php?post_type=chess_army_team">
								{ __(
									'Manage your teams',
									'chess-army-knife'
								) }
							</ExternalLink>
						</Notice>
					) }
					{ teamSource === 'manual' && (
						<TextareaControl
							label={ __(
								'Teams (one per line)',
								'chess-army-knife'
							) }
							help={ __(
								'Names must match the LMS exactly.',
								'chess-army-knife'
							) }
							value={ manualTeams }
							onChange={ ( value ) =>
								setAttributes( { manualTeams: value } )
							}
							placeholder={
								'Hammersmith 1\nHammersmith 2\nLewisham 1'
							}
							rows={ 6 }
						/>
					) }
					{ teamSource === 'auto' && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'Every team currently in the league table for this event will get a slide.',
								'chess-army-knife'
							) }
						</Notice>
					) }
					<TextControl
						label={ __(
							'Highlight team (optional)',
							'chess-army-knife'
						) }
						help={ __(
							"Bolds this team's slide, e.g. your own club's team.",
							'chess-army-knife'
						) }
						value={ highlightTeam }
						onChange={ ( value ) =>
							setAttributes( { highlightTeam: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Layout', 'chess-army-knife' ) }
						value={ attributes.layout }
						options={ [
							{
								label: __(
									'List: every team at once',
									'chess-army-knife'
								),
								value: 'list',
							},
							{
								label: __(
									'Carousel: one team at a time',
									'chess-army-knife'
								),
								value: 'carousel',
							},
						] }
						help={ __(
							'The list is the easiest to read. The carousel has buttons to move on and a pause button, and shows the list when scripts are off.',
							'chess-army-knife'
						) }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					{ 'carousel' === attributes.layout && (
						<>
							<ToggleControl
								label={ __(
									'Move on by itself',
									'chess-army-knife'
								) }
								help={ __(
									'It stops for anyone whose device asks for less motion, and as soon as a visitor uses a button.',
									'chess-army-knife'
								) }
								checked={ attributes.autoAdvance }
								onChange={ ( value ) =>
									setAttributes( { autoAdvance: value } )
								}
							/>
							{ attributes.autoAdvance && (
								<RangeControl
									label={ __(
										'Seconds per team',
										'chess-army-knife'
									) }
									value={ attributes.intervalSeconds }
									onChange={ ( value ) =>
										setAttributes( {
											intervalSeconds: value,
										} )
									}
									min={ 5 }
									max={ 30 }
								/>
							) }
						</>
					) }
					<TextControl
						label={ __( 'Season (optional)', 'chess-army-knife' ) }
						help={ __(
							'Leave empty for the current season. To show an earlier one, type its name as the LMS has it, such as 2025-2026.',
							'chess-army-knife'
						) }
						value={ attributes.season }
						onChange={ ( value ) =>
							setAttributes( { season: value } )
						}
					/>
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
						placeholder={
							eventName ||
							__(
								'Defaults to the event name',
								'chess-army-knife'
							)
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-carousel"
				attributes={ attributes }
			/>
		</div>
	);
}
