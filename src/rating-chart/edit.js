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
import { useRef, useEffect } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import PlayerPicker from '../shared/player-picker';
import { drawRatingCharts } from '../shared/rating-chart-canvas';

/**
 * ServerSideRender injects PHP-rendered HTML via innerHTML, so the
 * <canvas> it produces is never automatically drawn on - the block's
 * viewScript (view.js) that does that only runs on the published
 * front end, not inside this editor preview. This watches the preview
 * container and (re)draws the chart into it directly whenever
 * ServerSideRender swaps in new markup (e.g. after a settings change).
 */
function useLivePreviewChart( attributes ) {
	const containerRef = useRef( null );

	useEffect( () => {
		const node = containerRef.current;
		if ( ! node ) {
			return;
		}

		// Initial draw, then keep re-drawing whenever the preview's
		// markup changes underneath us.
		drawRatingCharts( node );

		const observer = new window.MutationObserver( () => {
			drawRatingCharts( node );
		} );
		observer.observe( node, { childList: true, subtree: true } );

		return () => observer.disconnect();
		// Re-attach whenever the attributes that affect the preview change,
		// so a fresh MutationObserver watches the freshly-mounted preview.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ attributes.playerCode, attributes.domain, attributes.gamesLimit ] );

	return containerRef;
}

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
	} = attributes;

	const blockProps = useBlockProps();
	const previewRef = useLivePreviewChart( attributes );

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
					<PlayerPicker
						value={ { code: playerCode, name: playerName } }
						onSelect={ ( player ) =>
							setAttributes( {
								playerCode: player.code,
								playerName: player.name,
							} )
						}
					/>
					<TextControl
						label={ __(
							'ECF rating code (manual override)',
							'chess-army-knife'
						) }
						help={ __(
							'e.g. 120787. You can type this directly if you already know it.',
							'chess-army-knife'
						) }
						value={ playerCode }
						onChange={ ( value ) =>
							setAttributes( { playerCode: value } )
						}
					/>
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
						<ColorPalette
							value={ lineColor }
							onChange={ ( value ) =>
								setAttributes( {
									lineColor: value || '#1e3a5f',
								} )
							}
						/>
					</div>
				</PanelBody>
			</InspectorControls>

			{ ! playerCode ? (
				<Placeholder
					icon="chart-line"
					label={ __( 'ECF Rating Chart', 'chess-army-knife' ) }
					instructions={ __(
						'Search for a player in the sidebar, or type their ECF rating code, to preview the chart.',
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
				<div ref={ previewRef }>
					<ServerSideRender
						block="ecf-lms/rating-chart"
						attributes={ attributes }
					/>
				</div>
			) }
		</div>
	);
}
