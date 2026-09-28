import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Placeholder,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import useTournaments from '../shared/use-tournaments';

export default function Edit( { attributes, setAttributes } ) {
	const { tournamentId, showCompleted } = attributes;
	const blockProps = useBlockProps();
	const tournaments = useTournaments();

	const options = [
		{
			label: __( '— Choose a tournament —', 'chess-army-knife' ),
			value: 0,
		},
		...tournaments.map( ( tournament ) => ( {
			label: tournament.name,
			value: tournament.id,
		} ) ),
	];

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Tournament', 'chess-army-knife' ) }>
					<SelectControl
						label={ __( 'Tournament', 'chess-army-knife' ) }
						value={ tournamentId }
						options={ options }
						onChange={ ( value ) =>
							setAttributes( { tournamentId: Number( value ) } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show games that already have a result',
							'chess-army-knife'
						) }
						checked={ showCompleted }
						onChange={ ( value ) =>
							setAttributes( { showCompleted: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			{ tournamentId ? (
				<ServerSideRender
					block="chess-army-knife/tournament-results"
					attributes={ attributes }
				/>
			) : (
				<Placeholder
					icon="edit"
					label={ __(
						'Tournament Results Entry',
						'chess-army-knife'
					) }
					instructions={ __(
						'Choose a tournament in the block settings. Only users who can manage tournaments will see this block on the site.',
						'chess-army-knife'
					) }
				/>
			) }
		</div>
	);
}
