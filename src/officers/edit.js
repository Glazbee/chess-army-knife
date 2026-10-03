import { __, sprintf } from '@wordpress/i18n';
import useClubName from '../shared/use-club-name';
import TemplatePicker from '../shared/template-picker';
import SortableList from '../shared/sortable-list';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';
import './editor.scss';
import NameFormatControl from '../shared/name-format-control';

const HEADING_LEVELS = [
	{ label: __( 'Plain text', 'chess-army-knife' ), value: 0 },
	{ label: __( 'Automatic', 'chess-army-knife' ), value: -1 },
	...[ 1, 2, 3, 4, 5, 6 ].map( ( level ) => ( {
		label: 'H' + level,
		value: level,
	} ) ),
];

// The club's items in the order this block asks for, then any it does not mention.
function inBlockOrder( items, order ) {
	const byKey = new Map( items.map( ( item ) => [ item.key, item ] ) );
	const ordered = [];
	order.forEach( ( key ) => {
		if ( byKey.has( key ) ) {
			ordered.push( byKey.get( key ) );
			byKey.delete( key );
		}
	} );
	return [ ...ordered, ...byKey.values() ];
}

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		showTitle,
		showTenure,
		includeCaptains,
		order,
		columns,
		layout,
		positionHeadingLevel,
		showSeparators,
	} = attributes;
	const [ items, setItems ] = useState( [] );

	const blockProps = useBlockProps();
	const clubName = useClubName();

	useEffect( () => {
		apiFetch( { path: '/chess-army-knife/v1/officer-items' } )
			.then( setItems )
			.catch( () => setItems( [] ) );
	}, [] );

	const listed = inBlockOrder( items, order ).filter(
		( item ) => includeCaptains || 'captain' !== item.kind
	);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<TemplatePicker
					blockSlug="officers"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
				<PanelBody
					title={ __( 'Display', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<ToggleControl
						label={ __( 'Show block title', 'chess-army-knife' ) }
						help={ __(
							'Turn off to use the page title instead.',
							'chess-army-knife'
						) }
						checked={ showTitle !== false }
						onChange={ ( value ) =>
							setAttributes( { showTitle: value } )
						}
					/>
					{ showTitle !== false && (
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
								__( '%s officers', 'chess-army-knife' ),
								clubName
							) }
						/>
					) }
					<RangeControl
						label={ __( 'Columns', 'chess-army-knife' ) }
						value={ columns }
						min={ 1 }
						max={ 4 }
						onChange={ ( value ) =>
							setAttributes( { columns: value } )
						}
					/>
					<SelectControl
						label={ __( 'Layout', 'chess-army-knife' ) }
						value={ layout }
						options={ [
							{
								label: __(
									'Position beside the name',
									'chess-army-knife'
								),
								value: 'inline',
							},
							{
								label: __(
									'Position above the name',
									'chess-army-knife'
								),
								value: 'stacked',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					<SelectControl
						label={ __( 'Position heading', 'chess-army-knife' ) }
						help={ __(
							'Plain text keeps a position as bold text. Choose a heading level to make each position a heading; Automatic follows the level set for the whole site.',
							'chess-army-knife'
						) }
						value={ positionHeadingLevel }
						options={ HEADING_LEVELS }
						onChange={ ( value ) =>
							setAttributes( {
								positionHeadingLevel: parseInt( value, 10 ),
							} )
						}
					/>
					<ToggleControl
						label={ __(
							'Line between officers',
							'chess-army-knife'
						) }
						checked={ showSeparators !== false }
						onChange={ ( value ) =>
							setAttributes( { showSeparators: value } )
						}
					/>
					<ToggleControl
						label={ __( 'Show since when', 'chess-army-knife' ) }
						checked={ showTenure }
						onChange={ ( value ) =>
							setAttributes( { showTenure: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Include team captains',
							'chess-army-knife'
						) }
						help={ __(
							'Team captains are chosen on each team.',
							'chess-army-knife'
						) }
						checked={ includeCaptains }
						onChange={ ( value ) =>
							setAttributes( { includeCaptains: value } )
						}
					/>
					<NameFormatControl
						value={ attributes.nameFormat }
						onChange={ ( value ) =>
							setAttributes( { nameFormat: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Order', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<p>
						{ order.length
							? __(
									"This block has an order of its own. Drag the positions to change it, or go back to the club's order.",
									'chess-army-knife'
							  )
							: __(
									"This block follows the club's order, which is set under Officers. Drag the positions to give this block an order of its own.",
									'chess-army-knife'
							  ) }
					</p>
					{ order.length > 0 && (
						<p>
							<Button
								variant="secondary"
								onClick={ () => setAttributes( { order: [] } ) }
							>
								{ __(
									"Use the club's order",
									'chess-army-knife'
								) }
							</Button>
						</p>
					) }
					<SortableList
						items={ listed }
						onChange={ ( next ) =>
							setAttributes( {
								order: next.map( ( item ) => item.key ),
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/officers"
				attributes={ attributes }
			/>
		</div>
	);
}
