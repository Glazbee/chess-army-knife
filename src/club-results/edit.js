import { __, sprintf } from '@wordpress/i18n';
import useClubName from '../shared/use-club-name';
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
	const { domain, maxPlayers, gamesPerPlayer, daysBack, maxResults, title } =
		attributes;

	const blockProps = useBlockProps();
	const clubName = useClubName();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="club-results"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'Results settings', 'chess-army-knife' ) }
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
							__( 'Recent results for %s', 'chess-army-knife' ),
							clubName
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
						step={ 1 }
					/>
					<RangeControl
						label={ __( 'Max results shown', 'chess-army-knife' ) }
						value={ maxResults }
						onChange={ ( value ) =>
							setAttributes( { maxResults: value } )
						}
						min={ 5 }
						max={ 60 }
						step={ 5 }
					/>
					<ToggleControl
						label={ __( 'Show event column', 'chess-army-knife' ) }
						checked={ attributes.showEvent }
						onChange={ ( value ) =>
							setAttributes( { showEvent: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Advanced (API load)', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'This block fetches recent games for each club member individually. Keep "players checked" modest on large clubs to keep page loads fast — results are cached after the first load.',
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
						max={ 40 }
						step={ 1 }
					/>
					<RangeControl
						label={ __(
							'Recent games checked per player',
							'chess-army-knife'
						) }
						value={ gamesPerPlayer }
						onChange={ ( value ) =>
							setAttributes( { gamesPerPlayer: value } )
						}
						min={ 1 }
						max={ 20 }
						step={ 1 }
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/club-results"
				attributes={ attributes }
			/>
		</div>
	);
}
