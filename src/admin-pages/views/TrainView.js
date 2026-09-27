import { __ } from '@wordpress/i18n';
import TabBar from './TabBar';

function TrainView( { data } ) {
	const concepts = data.concepts || [];
	return (
		<>
			<TabBar tabs={ data.tabs } active={ data.tab } />
			<p className="agentic-react-lead">{ data.description }</p>
			<p>
				<Button variant="primary" href={ data.manage_url }>
					{ __( 'Open full Knowledge editor', 'agent-builder' ) }
				</Button>
			</p>
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table">
					<thead>
						<tr>
							<th>{ __( 'Concept', 'agent-builder' ) }</th>
							<th>{ __( 'Type', 'agent-builder' ) }</th>
							<th>{ __( 'Status', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ concepts.map( ( c ) => (
							<tr key={ c.id }>
								<td>
									<strong>{ c.title }</strong>
									{ c.example && (
										<span className="agentic-react-muted">
											{ ' ' }
											(example)
										</span>
									) }
								</td>
								<td>
									<code>{ c.type || '—' }</code>
								</td>
								<td>{ c.status || '—' }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}


export default TrainView;
