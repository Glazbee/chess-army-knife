import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	MediaUpload,
	MediaUploadCheck,
} from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
	Button,
	Placeholder,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import PlayerPicker from '../shared/player-picker';
import TemplatePicker from '../shared/template-picker';

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
		heading,
		blurb,
		imageId,
		imageUrl,
		chessComUser,
		lichessUser,
		domain,
		showRating,
		showClub,
		showLinks,
	} = attributes;

	const blockProps = useBlockProps();
	const pick = ( player ) =>
		setAttributes( { playerCode: player.code, playerName: player.name } );

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="featured-player"
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
						onSelect={ pick }
					/>
					<TextControl
						label={ __( 'ECF rating code', 'chess-army-knife' ) }
						help={ __(
							'Optional. Enables rating and club details from the ECF.',
							'chess-army-knife'
						) }
						value={ playerCode }
						onChange={ ( value ) =>
							setAttributes( { playerCode: value } )
						}
					/>
					<TextControl
						label={ __( 'Display name', 'chess-army-knife' ) }
						help={ __(
							'Overrides the name from the ECF. Required if there is no ECF code.',
							'chess-army-knife'
						) }
						value={ playerName }
						onChange={ ( value ) =>
							setAttributes( { playerName: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Spotlight', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Heading (optional)', 'chess-army-knife' ) }
						placeholder={ __(
							'e.g. Player of the month',
							'chess-army-knife'
						) }
						value={ heading }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
					/>
					<TextareaControl
						label={ __(
							'Why are they featured?',
							'chess-army-knife'
						) }
						value={ blurb }
						onChange={ ( value ) =>
							setAttributes( { blurb: value } )
						}
						rows={ 6 }
					/>
					<MediaUploadCheck>
						<MediaUpload
							onSelect={ ( media ) =>
								setAttributes( {
									imageId: media.id,
									imageUrl: media.url,
								} )
							}
							allowedTypes={ [ 'image' ] }
							value={ imageId }
							render={ ( { open } ) => (
								<div>
									<Button
										variant="secondary"
										onClick={ open }
									>
										{ imageUrl
											? __(
													'Change photo',
													'chess-army-knife'
											  )
											: __(
													'Choose photo',
													'chess-army-knife'
											  ) }
									</Button>{ ' ' }
									{ imageUrl && (
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												setAttributes( {
													imageId: 0,
													imageUrl: '',
												} )
											}
										>
											{ __(
												'Remove',
												'chess-army-knife'
											) }
										</Button>
									) }
								</div>
							) }
						/>
					</MediaUploadCheck>
				</PanelBody>
				<PanelBody
					title={ __( 'Online profiles', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'chess.com username', 'chess-army-knife' ) }
						help={ __(
							'A username or a full profile URL.',
							'chess-army-knife'
						) }
						value={ chessComUser }
						onChange={ ( value ) =>
							setAttributes( { chessComUser: value } )
						}
					/>
					<TextControl
						label={ __( 'Lichess username', 'chess-army-knife' ) }
						help={ __(
							'A username or a full profile URL.',
							'chess-army-knife'
						) }
						value={ lichessUser }
						onChange={ ( value ) =>
							setAttributes( { lichessUser: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show profile links', 'chess-army-knife' ) }
						checked={ showLinks }
						onChange={ ( value ) =>
							setAttributes( { showLinks: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display', 'chess-army-knife' ) }
					initialOpen={ false }
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
							'Show current rating',
							'chess-army-knife'
						) }
						checked={ showRating }
						onChange={ ( value ) =>
							setAttributes( { showRating: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show club', 'chess-army-knife' ) }
						checked={ showClub }
						onChange={ ( value ) =>
							setAttributes( { showClub: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			{ ! playerCode && ! playerName ? (
				<Placeholder
					icon="star-filled"
					label={ __( 'ECF Featured Player', 'chess-army-knife' ) }
					instructions={ __(
						'Search for a player, or type a display name in the sidebar.',
						'chess-army-knife'
					) }
				>
					<PlayerPicker
						value={ { code: playerCode, name: playerName } }
						onSelect={ pick }
					/>
				</Placeholder>
			) : (
				<ServerSideRender
					block="chess-army-knife/featured-player"
					attributes={ attributes }
				/>
			) }
		</div>
	);
}
