/**
 * Fetches the plugin's site-wide defaults (LMS org id, event name,
 * rating domain) so a block's editor UI can tell
 * whether leaving a field blank will still resolve to something useful
 * at render time, rather than always demanding the field be filled in.
 */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function useEcfLmsDefaults() {
	const [ defaults, setDefaults ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/chess-army-knife/v1/defaults' } )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setDefaults( data );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setDefaults( {} );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	return defaults || {};
}
