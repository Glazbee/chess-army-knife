/**
 * A list the editor can put in order by dragging, or with the move buttons
 * for anyone who cannot drag.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';

export default function SortableList( { items, onChange } ) {
	const [ dragged, setDragged ] = useState( null );

	const move = ( from, to ) => {
		if ( to < 0 || to >= items.length || from === to ) {
			return;
		}
		const next = [ ...items ];
		next.splice( to, 0, next.splice( from, 1 )[ 0 ] );
		onChange( next );
	};

	return (
		<ul className="cak-sortable">
			{ items.map( ( item, index ) => (
				<li
					key={ item.key }
					className="cak-sortable__item"
					draggable
					onDragStart={ () => setDragged( index ) }
					onDragOver={ ( event ) => event.preventDefault() }
					onDrop={ ( event ) => {
						event.preventDefault();
						if ( null !== dragged ) {
							move( dragged, index );
						}
						setDragged( null );
					} }
					onDragEnd={ () => setDragged( null ) }
				>
					<span className="cak-sortable__label">
						<span aria-hidden="true">⠿ </span>
						{ item.label }
					</span>
					<Button
						icon="arrow-up-alt2"
						size="small"
						disabled={ 0 === index }
						label={ sprintf(
							/* translators: %s: name of a position */
							__( 'Move %s up', 'chess-army-knife' ),
							item.label
						) }
						onClick={ () => move( index, index - 1 ) }
					/>
					<Button
						icon="arrow-down-alt2"
						size="small"
						disabled={ index === items.length - 1 }
						label={ sprintf(
							/* translators: %s: name of a position */
							__( 'Move %s down', 'chess-army-knife' ),
							item.label
						) }
						onClick={ () => move( index, index + 1 ) }
					/>
				</li>
			) ) }
		</ul>
	);
}
