/**
 * Fetches the list of tournaments for a block's tournament picker.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function useTournaments() {
	const [ tournaments, setTournaments ] = useState( [] );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/chess-army-knife/v1/tournaments' } )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setTournaments( data );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setTournaments( [] );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return tournaments;
}
