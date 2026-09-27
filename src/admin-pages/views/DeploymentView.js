import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

function DeploymentView( { data } ) {
	return (
		<>
			<p className="agentic-react-lead">{ data.description }</p>
			<div
				style={ {
					display: 'grid',
					gap: 12,
					gridTemplateColumns:
						'repeat(auto-fill, minmax(220px, 1fr))',
				} }
			>
				{ ( data.links || [] ).map( ( link ) => (
					<a
						key={ link.url }
						href={ link.url }
						className="agentic-react-panel components-card"
						style={ {
							display: 'block',
							padding: 16,
							textDecoration: 'none',
							color: 'inherit',
							border: '1px solid #e0e0e0',
							borderRadius: 8,
						} }
					>
						<strong style={ { color: '#2271b1' } }>
							{ link.label }
						</strong>
						<div className="agentic-react-muted">{ link.hint }</div>
					</a>
				) ) }
			</div>
			{ data.legacy_note && (
				<p className="agentic-react-muted" style={ { marginTop: 16 } }>
					{ data.legacy_note }
				</p>
			) }
		</>
	);
}


export default DeploymentView;
