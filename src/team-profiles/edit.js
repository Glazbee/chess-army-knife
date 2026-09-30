import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';

export default function Edit( { attributes, setAttributes } ) {
	const { title, teamId } = attributes;
	const [ teams, setTeams ] = useState( [] );

	const blockProps = useBlockProps();

	useEffect( () => {
		apiFetch( { path: '/ecf-lms/v1/teams' } )
			.then( setTeams )
			.catch( () => setTeams( [] ) );
	}, [] );

	return (
		<div { ...blockProps }>
			<InspectorControls>
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
						placeholder={ __( 'Our teams', 'chess-army-knife' ) }
					/>
					<SelectControl
						label={ __( 'Team', 'chess-army-knife' ) }
						help={ __(
							'Teams are set up under Teams.',
							'chess-army-knife'
						) }
						value={ teamId }
						options={ [
							{
								label: __( 'All teams', 'chess-army-knife' ),
								value: 0,
							},
							...teams.map( ( team ) => ( {
								label: team.name,
								value: team.id,
							} ) ),
						] }
						onChange={ ( value ) =>
							setAttributes( { teamId: parseInt( value, 10 ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block="chess-army-knife/team-profiles"
				attributes={ attributes }
			/>
		</div>
	);
}
