/**
 * Sidebar control for filtering club events by tag. Stores tag slugs,
 * shows tag names, and suggests the tags already in use.
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, FormTokenField } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';

const TAXONOMY = 'chess_army_event_tag';

function toSlug( name ) {
	return name
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

export default function EventTagsControl( { value, onChange } ) {
	const terms = useSelect(
		( select ) =>
			select( coreStore ).getEntityRecords( 'taxonomy', TAXONOMY, {
				per_page: 100,
			} ) || [],
		[]
	);

	const nameBySlug = {};
	const slugByName = {};
	terms.forEach( ( term ) => {
		nameBySlug[ term.slug ] = term.name;
		slugByName[ term.name.toLowerCase() ] = term.slug;
	} );

	return (
		<PanelBody
			title={ __( 'Filter by tag', 'chess-army-knife' ) }
			initialOpen={ true }
		>
			<FormTokenField
				label={ __( 'Only show events tagged', 'chess-army-knife' ) }
				value={ ( value || [] ).map(
					( slug ) => nameBySlug[ slug ] || slug
				) }
				suggestions={ terms.map( ( term ) => term.name ) }
				onChange={ ( names ) =>
					onChange(
						names
							.map(
								( name ) =>
									slugByName[ name.toLowerCase() ] ||
									toSlug( name )
							)
							.filter( Boolean )
					)
				}
				__experimentalShowHowTo={ false }
			/>
			<p className="components-base-control__help">
				{ __(
					'Leave empty to show all events. An event with any of the tags is shown.',
					'chess-army-knife'
				) }
			</p>
		</PanelBody>
	);
}
