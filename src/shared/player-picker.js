/**
 * A small search-as-you-type control for finding a club member and
 * picking up their ECF rating code, backed by the plugin's own REST
 * route, which searches the club's member list (not the ECF's).
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { TextControl, Spinner, Button } from '@wordpress/components';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export default function PlayerPicker( { value, label, onSelect } ) {
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
				path: `/chess-army-knife/v1/players?search=${ encodeURIComponent(
					query.trim()
				) }`,
			} )
				.then( ( items ) => {
					const found = items || [];
					setResults( found );
					setIsOpen( true );
					// Say what the search found, for people who cannot see the list change.
					speak(
						found.length
							? sprintf(
									/* translators: %d: number of members found */
									_n(
										'%d member found.',
										'%d members found.',
										Math.min( found.length, 8 ),
										'chess-army-knife'
									),
									Math.min( found.length, 8 )
							  )
							: __(
									'No matching members found.',
									'chess-army-knife'
							  ),
						'polite'
					);
				} )
				.catch( () => {
					setResults( [] );
					speak(
						__(
							'The search failed. Please try again.',
							'chess-army-knife'
						),
						'assertive'
					);
				} )
				.finally( () => setIsSearching( false ) );
		}, 400 );

		return () => clearTimeout( debounceRef.current );
	}, [ query ] );

	return (
		<div className="chess-army-knife-picker">
			<TextControl
				label={
					label || __( 'Find member by name', 'chess-army-knife' )
				}
				value={ query }
				placeholder={ __( 'Start typing a name…', 'chess-army-knife' ) }
				help={ __(
					'Current club members with an ECF code.',
					'chess-army-knife'
				) }
				onChange={ setQuery }
				onFocus={ () => results.length && setIsOpen( true ) }
			/>
			{ isSearching && <Spinner /> }
			{ isOpen && results.length > 0 && (
				<ul className="chess-army-knife-picker__results">
					{ results.slice( 0, 8 ).map( ( player ) => (
						<li key={ player.code }>
							<Button
								variant="tertiary"
								onClick={ () => {
									onSelect( player );
									speak(
										sprintf(
											/* translators: %s: member's name */
											__(
												'Selected %s.',
												'chess-army-knife'
											),
											player.name
										),
										'polite'
									);
									setQuery( '' );
									setIsOpen( false );
									setResults( [] );
								} }
							>
								{ player.name }
								{ player.club ? ` — ${ player.club }` : '' }
								{ ` (${ player.code })` }
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
