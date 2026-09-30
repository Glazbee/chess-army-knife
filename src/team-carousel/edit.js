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
	const {
		orgId,
		eventName,
		teamSource,
		manualTeams,
		title,
		autoAdvance,
		intervalSeconds,
		highlightTeam,
	} = attributes;

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
					title={ __( 'Carousel', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<ToggleControl
						label={ __(
							'Show match location / venue',
							'chess-army-knife'
						) }
						checked={ attributes.showLocation }
						onChange={ ( value ) =>
							setAttributes( { showLocation: value } )
						}
					/>
					<TextControl
						label={ __(
							'Custom title (optional)',
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
					<ToggleControl
						label={ __( 'Auto-advance', 'chess-army-knife' ) }
						checked={ autoAdvance }
						onChange={ ( value ) =>
							setAttributes( { autoAdvance: value } )
						}
					/>
					{ autoAdvance && (
						<RangeControl
							label={ __(
								'Seconds per slide',
								'chess-army-knife'
							) }
							value={ intervalSeconds }
							onChange={ ( value ) =>
								setAttributes( { intervalSeconds: value } )
							}
							min={ 3 }
							max={ 20 }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-carousel"
				attributes={ attributes }
			/>
		</div>
	);
}
