import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	Placeholder,
	TextControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import useTournaments from '../shared/use-tournaments';
import TemplatePicker from '../shared/template-picker';

export default function Edit( { attributes, setAttributes } ) {
	const { tournamentId, roundsPerPage } = attributes;
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
					<TextControl
						type="number"
						min={ 0 }
						max={ 100 }
						label={ __( 'Rounds on a page', 'chess-army-knife' ) }
						help={ __(
							'Games are shown a round at a time, with Previous and Next links. Show more rounds on a page, or enter 0 to show every round on one page.',
							'chess-army-knife'
						) }
						value={ roundsPerPage }
						onChange={ ( value ) =>
							setAttributes( {
								roundsPerPage: Math.max(
									0,
									Math.min( 100, parseInt( value, 10 ) || 0 )
								),
							} )
						}
					/>
				</PanelBody>
				<TemplatePicker
					blockSlug="tournament-games"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
			</InspectorControls>
			{ tournamentId ? (
				<ServerSideRender
					block="chess-army-knife/tournament-games"
					attributes={ attributes }
				/>
			) : (
				<Placeholder
					icon="list-view"
					label={ __(
						'Tournament Games to Play',
						'chess-army-knife'
					) }
					instructions={ __(
						'Choose a tournament in the block settings.',
						'chess-army-knife'
					) }
				/>
			) }
		</div>
	);
}
