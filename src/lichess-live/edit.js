import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const {
		usernames,
		title,
		showBoard,
		autoAdvance,
		intervalSeconds,
		emptyMessage,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="lichess-live"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'Lichess players', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<TextareaControl
						label={ __(
							'Usernames (one per line)',
							'chess-army-knife'
						) }
						help={ __(
							'Leave blank to use the list saved in Settings → Chess Army Knife.',
							'chess-army-knife'
						) }
						value={ usernames }
						onChange={ ( value ) =>
							setAttributes( { usernames: value } )
						}
						rows={ 6 }
					/>
				</PanelBody>
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
					/>
					<TextControl
						label={ __(
							'Message when nobody is playing',
							'chess-army-knife'
						) }
						value={ emptyMessage }
						onChange={ ( value ) =>
							setAttributes( { emptyMessage: value } )
						}
						placeholder={ __(
							'Nobody is playing on Lichess right now.',
							'chess-army-knife'
						) }
					/>
					<ToggleControl
						label={ __( 'Show live boards', 'chess-army-knife' ) }
						help={ __(
							'Embeds the game from lichess.org. Turn off for a lighter text-only card.',
							'chess-army-knife'
						) }
						checked={ showBoard }
						onChange={ ( value ) =>
							setAttributes( { showBoard: value } )
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
								'Seconds per game',
								'chess-army-knife'
							) }
							value={ intervalSeconds }
							onChange={ ( value ) =>
								setAttributes( { intervalSeconds: value } )
							}
							min={ 3 }
							max={ 30 }
						/>
					) }
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/lichess-live"
				attributes={ attributes }
			/>
		</div>
	);
}
