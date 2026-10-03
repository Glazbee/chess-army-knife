import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	TextControl,
	ToggleControl,
	Placeholder,
	ColorPalette,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import PlayerPicker from '../shared/player-picker';
import RotationControl from '../shared/rotation-control';

const DOMAIN_OPTIONS = [
	{ label: __( 'Site default', 'chess-army-knife' ), value: '' },
	{ label: __( 'Standard (OTB)', 'chess-army-knife' ), value: 'S' },
	{ label: __( 'Rapid (OTB)', 'chess-army-knife' ), value: 'R' },
	{ label: __( 'Blitz (OTB)', 'chess-army-knife' ), value: 'B' },
	{ label: __( 'Standard (Online)', 'chess-army-knife' ), value: 'SW' },
	{ label: __( 'Rapid (Online)', 'chess-army-knife' ), value: 'RW' },
	{ label: __( 'Blitz (Online)', 'chess-army-knife' ), value: 'BW' },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		playerCode,
		playerName,
		domain,
		gamesLimit,
		title,
		height,
		showStats,
		lineColor,
		rotation,
	} = attributes;
	const rotating = rotation && 'none' !== rotation;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="rating-chart"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'Player', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<RotationControl
						attributes={ attributes }
						setAttributes={ setAttributes }
					/>
					{ ! rotating && (
						<PlayerPicker
							value={ { code: playerCode, name: playerName } }
							onSelect={ ( player ) =>
								setAttributes( {
									playerCode: player.code,
									playerName: player.name,
								} )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Chart settings', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Rating list', 'chess-army-knife' ) }
						value={ domain }
						options={ DOMAIN_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { domain: value } )
						}
					/>
					<RangeControl
						label={ __( 'Games to include', 'chess-army-knife' ) }
						help={ __(
							'How many recent rated games to pull from the ECF API.',
							'chess-army-knife'
						) }
						value={ gamesLimit }
						onChange={ ( value ) =>
							setAttributes( { gamesLimit: value } )
						}
						min={ 10 }
						max={ 300 }
						step={ 10 }
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
							playerName ||
							__(
								'Defaults to the player name',
								'chess-army-knife'
							)
						}
					/>
					<RangeControl
						label={ __( 'Chart height (px)', 'chess-army-knife' ) }
						value={ height }
						onChange={ ( value ) =>
							setAttributes( { height: value } )
						}
						min={ 180 }
						max={ 600 }
						step={ 10 }
					/>
					<ToggleControl
						label={ __(
							'Show summary stats (current, peak, change)',
							'chess-army-knife'
						) }
						checked={ showStats }
						onChange={ ( value ) =>
							setAttributes( { showStats: value } )
						}
					/>
					<div style={ { marginTop: '12px' } }>
						<p style={ { marginBottom: '4px' } }>
							{ __( 'Line colour', 'chess-army-knife' ) }
						</p>
						<p className="description">
							{ __(
								"Leave this empty to use the theme's accent colour, or the text colour if the theme has none.",
								'chess-army-knife'
							) }
						</p>
						<ColorPalette
							value={ lineColor }
							onChange={ ( value ) =>
								setAttributes( {
									lineColor: value || '',
								} )
							}
						/>
					</div>
				</PanelBody>
			</InspectorControls>

			{ ! rotating && ! playerCode ? (
				<Placeholder
					icon="chart-line"
					label={ __( 'ECF Rating Chart', 'chess-army-knife' ) }
					instructions={ __(
						'Choose a current club member in the sidebar, or turn on rotation, to preview the chart.',
						'chess-army-knife'
					) }
				>
					<PlayerPicker
						value={ { code: playerCode, name: playerName } }
						onSelect={ ( player ) =>
							setAttributes( {
								playerCode: player.code,
								playerName: player.name,
							} )
						}
					/>
				</Placeholder>
			) : (
				<div>
					<ServerSideRender
						block="chess-army-knife/rating-chart"
						attributes={ attributes }
					/>
				</div>
			) }
		</div>
	);
}
