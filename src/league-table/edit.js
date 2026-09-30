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

const MODE_OPTIONS = [
	{ label: __( 'Table + matchups', 'chess-army-knife' ), value: 'both' },
	{ label: __( 'Table only', 'chess-army-knife' ), value: 'table' },
	{ label: __( 'Matchups only', 'chess-army-knife' ), value: 'matches' },
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		orgId,
		eventName,
		displayMode,
		maxMatches,
		title,
		highlightTeam,
		debug,
	} = attributes;

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="league-table"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'League / event', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Find your organisation ID in your LMS homepage URL (…/lms/organisation/ID). The event name must match exactly, including case and spacing, e.g. "Division 1".',
							'chess-army-knife'
						) }
						<br />
						<ExternalLink href="https://lms.englishchess.org.uk/lms/node/34">
							{ __( 'LMS API reference', 'chess-army-knife' ) }
						</ExternalLink>
					</Notice>
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
					<TextareaControl
						label={ __(
							'Event / division name(s)',
							'chess-army-knife'
						) }
						help={ __(
							'One per line if your club has teams in more than one division of this organisation, e.g. Division 1 / Division 2 / Division 3 — each gets its own table and matchups.',
							'chess-army-knife'
						) }
						value={ eventName }
						onChange={ ( value ) =>
							setAttributes( { eventName: value } )
						}
						placeholder={ 'Division 1\nDivision 2' }
						rows={ 4 }
					/>
					<TextControl
						label={ __(
							'Highlight team (optional)',
							'chess-army-knife'
						) }
						help={ __(
							'Bolds matching teams in the table and matchups. Teams with a league entry under Teams are highlighted automatically, so this is only needed for extra teams.',
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
						label={ __( 'Show', 'chess-army-knife' ) }
						value={ displayMode }
						options={ MODE_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { displayMode: value } )
						}
					/>
					{ displayMode !== 'table' && (
						<RangeControl
							label={ __(
								'Matchups to show',
								'chess-army-knife'
							) }
							value={ maxMatches }
							onChange={ ( value ) =>
								setAttributes( { maxMatches: value } )
							}
							min={ 1 }
							max={ 20 }
						/>
					) }
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
				</PanelBody>
				<PanelBody
					title={ __( 'Troubleshooting', 'chess-army-knife' ) }
					initialOpen={ false }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'The LMS API is described by the ECF as experimental, and this block parses its data defensively. If a table or matchup looks wrong, turn on raw data below to see exactly what your LMS instance returned.',
							'chess-army-knife'
						) }
					</Notice>
					<ToggleControl
						label={ __(
							'Show raw API data (debug)',
							'chess-army-knife'
						) }
						checked={ debug }
						onChange={ ( value ) =>
							setAttributes( { debug: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/league-table"
				attributes={ attributes }
			/>
		</div>
	);
}
