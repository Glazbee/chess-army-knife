import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { orgId, eventName, team, season, title, showBoards } = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Team and league', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Team name', 'chess-army-knife' ) }
						help={ __(
							'As the LMS spells it. Leave empty to use the first of your own teams.',
							'chess-army-knife'
						) }
						value={ team }
						onChange={ ( value ) =>
							setAttributes( { team: value } )
						}
					/>
					<TextControl
						label={ __(
							'LMS organisation ID',
							'chess-army-knife'
						) }
						help={ __(
							'Leave empty to use the default under Settings.',
							'chess-army-knife'
						) }
						value={ orgId }
						onChange={ ( value ) =>
							setAttributes( { orgId: value } )
						}
					/>
					<TextControl
						label={ __( 'Event / division', 'chess-army-knife' ) }
						help={ __(
							'The exact event name, such as Division 1.',
							'chess-army-knife'
						) }
						value={ eventName }
						onChange={ ( value ) =>
							setAttributes( { eventName: value } )
						}
					/>
					<TextControl
						label={ __( 'Season (optional)', 'chess-army-knife' ) }
						help={ __(
							'Leave empty for the current season, or type an earlier one as the LMS names it, such as 2025-2026.',
							'chess-army-knife'
						) }
						value={ season }
						onChange={ ( value ) =>
							setAttributes( { season: value } )
						}
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
					<ToggleControl
						label={ __(
							'Show board-by-board results',
							'chess-army-knife'
						) }
						checked={ showBoards }
						onChange={ ( value ) =>
							setAttributes( { showBoards: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-page"
				attributes={ attributes }
			/>
		</div>
	);
}
