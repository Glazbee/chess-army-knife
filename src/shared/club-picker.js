/**
 * A small search-as-you-type control for finding an ECF club and
 * picking up its club code.
 */
import { __ } from '@wordpress/i18n';
import { TextControl, Spinner, Button } from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function ClubPicker( { value, label, onSelect } ) {
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ isOpen, setIsOpen ] = useState( false );
	const debounceRef = useRef( null );

	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		if ( query.trim().length < 3 ) {
			setResults( [] );
			return;
		}

		debounceRef.current = setTimeout( () => {
			setIsSearching( true );
			apiFetch( {
				path: `/ecf-lms/v1/clubs?search=${ encodeURIComponent(
					query.trim()
				) }`,
			} )
				.then( ( items ) => {
					setResults( items || [] );
					setIsOpen( true );
				} )
				.catch( () => setResults( [] ) )
				.finally( () => setIsSearching( false ) );
		}, 400 );

		return () => clearTimeout( debounceRef.current );
	}, [ query ] );

	return (
		<div className="chess-army-knife-picker">
			<TextControl
				label={ label || __( 'Find club by name', 'chess-army-knife' ) }
				value={ query }
				placeholder={ __(
					'Start typing a club name…',
					'chess-army-knife'
				) }
				onChange={ setQuery }
				onFocus={ () => results.length && setIsOpen( true ) }
			/>
			{ isSearching && <Spinner /> }
			{ isOpen && results.length > 0 && (
				<ul className="chess-army-knife-picker__results">
					{ results.slice( 0, 8 ).map( ( club ) => (
						<li key={ club.code }>
							<Button
								variant="tertiary"
								onClick={ () => {
									onSelect( club );
									setQuery( '' );
									setIsOpen( false );
									setResults( [] );
								} }
							>
								{ club.name } <code>{ club.code }</code>
							</Button>
						</li>
					) ) }
				</ul>
			) }
			{ value?.code && (
				<p className="chess-army-knife-picker__current">
					{ __( 'Selected:', 'chess-army-knife' ) }{ ' ' }
					<strong>{ value.name || value.code }</strong>{ ' ' }
					<code>{ value.code }</code>
				</p>
			) }
		</div>
	);
}
