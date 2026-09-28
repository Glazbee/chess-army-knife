import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Placeholder } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import useTournaments from '../shared/use-tournaments';
import TemplatePicker from '../shared/template-picker';

export default function Edit( { attributes, setAttributes } ) {
	const { tournamentId } = attributes;
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
				</PanelBody>
				<TemplatePicker
					blockSlug="tournament-players"
					value={ attributes.templateId }
					onChange={ ( value ) =>
						setAttributes( { templateId: value } )
					}
				/>
			</InspectorControls>
			{ tournamentId ? (
				<ServerSideRender
					block="chess-army-knife/tournament-players"
					attributes={ attributes }
				/>
			) : (
				<Placeholder
					icon="groups"
					label={ __( 'Tournament Players', 'chess-army-knife' ) }
					instructions={ __(
						'Choose a tournament in the block settings.',
						'chess-army-knife'
					) }
				/>
			) }
		</div>
	);
}
