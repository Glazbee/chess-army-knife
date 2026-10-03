import { __ } from '@wordpress/i18n';
import TemplatePicker from '../shared/template-picker';
import SortableList from '../shared/sortable-list';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';
import './editor.scss';

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
	const { title, showTenure, includeCaptains, order } = attributes;
	const [ items, setItems ] = useState( [] );

	const blockProps = useBlockProps();

	useEffect( () => {
		apiFetch( { path: '/ecf-lms/v1/officer-items' } )
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
					<TextControl
						label={ __(
							'Custom title (optional)',
							'chess-army-knife'
						) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
						placeholder={ __(
							'Club officers',
							'chess-army-knife'
						) }
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
				</PanelBody>
				<PanelBody
					title={ __( 'Order', 'chess-army-knife' ) }
					initialOpen={ true }
				>
					<p>
						{ __(
							'Drag the positions into the order you want, or use the arrows. Positions are set up under Officers.',
							'chess-army-knife'
						) }
					</p>
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
