import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	RangeControl,
	TextControl,
	ToggleControl,
	Notice,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const DOMAIN_OPTIONS = [
	{ label: __( 'Site default', 'chess-army-knife' ), value: '' },
	{ label: __( 'Standard (OTB)', 'chess-army-knife' ), value: 'S' },
	{ label: __( 'Rapid (OTB)', 'chess-army-knife' ), value: 'R' },
	{ label: __( 'Blitz (OTB)', 'chess-army-knife' ), value: 'B' },
];

export default function Edit( { attributes, setAttributes } ) {
	const { domain, daysBack, maxPlayers, topCount, minGames, title } =
		attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="biggest-gainers"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'Settings', 'chess-army-knife' ) }
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
					<ToggleControl
						label={ __(
							'Show "from → to" rating detail',
							'chess-army-knife'
						) }
						checked={ attributes.showDetail }
						onChange={ ( value ) =>
							setAttributes( { showDetail: value } )
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
						placeholder={ __(
							'Defaults to "Biggest improvers — [club]"',
							'chess-army-knife'
						) }
					/>
					<RangeControl
						label={ __( 'Days to look back', 'chess-army-knife' ) }
						help={ __(
							'0 uses the site-wide default set on the settings page.',
							'chess-army-knife'
						) }
						value={ daysBack }
						onChange={ ( value ) =>
							setAttributes( { daysBack: value } )
						}
						min={ 0 }
						max={ 365 }
					/>
					<RangeControl
						label={ __( 'Players to show', 'chess-army-knife' ) }
						value={ topCount }
						onChange={ ( value ) =>
							setAttributes( { topCount: value } )
						}
						min={ 1 }
						max={ 20 }
					/>
					<RangeControl
						label={ __(
							'Minimum games in period to qualify',
							'chess-army-knife'
						) }
						help={ __(
							'Avoids one lucky/unlucky game looking like a big swing.',
							'chess-army-knife'
						) }
						value={ minGames }
						onChange={ ( value ) =>
							setAttributes( { minGames: value } )
						}
						min={ 1 }
						max={ 10 }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Advanced (API load)', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This block checks recent games for each club member individually, ranked by current rating, to find the biggest movers. Keep this modest on large clubs — results are cached after the first load.',
							'chess-army-knife'
						) }
					</Notice>
					<RangeControl
						label={ __(
							'Players checked from roster',
							'chess-army-knife'
						) }
						help={ __(
							'0 uses the site-wide default set on the settings page.',
							'chess-army-knife'
						) }
						value={ maxPlayers }
						onChange={ ( value ) =>
							setAttributes( { maxPlayers: value } )
						}
						min={ 0 }
						max={ 60 }
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/biggest-gainers"
				attributes={ attributes }
			/>
		</div>
	);
}
