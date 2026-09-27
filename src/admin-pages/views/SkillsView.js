import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button, ExternalLink, Notice, SearchControl, Spinner } from '@wordpress/components';

function SkillsView( { data, reload } ) {
	const [ q, setQ ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const isAdvanced = !! data.is_advanced;
	const rows = useMemo( () => {
		const all = data.rows || [];
		const n = q.trim().toLowerCase();
		if ( ! n ) {
			return all;
		}
		return all.filter( ( r ) =>
			[ r.title, r.subtitle, r.agent ]
				.join( ' ' )
				.toLowerCase()
				.includes( n )
		);
	}, [ data.rows, q ] );

	const remove = ( id ) => {
		if (
			! window.confirm(
				__( 'Delete this skill? This cannot be undone.', 'agent-builder' )
			)
		) {
			return;
		}
		setErr( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'delete_skill', id },
		} )
			.then( () => reload() )
			.catch( ( e ) =>
				setErr( e.message || __( 'Delete failed.', 'agent-builder' ) )
			);
	};

	return (
		<>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			<div
				style={ {
					display: 'flex',
					gap: 8,
					flexWrap: 'wrap',
					marginBottom: 16,
				} }
			>
				{ ( data.actions || [] ).map( ( a ) => (
					<Button
						key={ a.url }
						variant={ a.primary ? 'primary' : 'secondary' }
						href={ a.url }
					>
						{ a.label }
					</Button>
				) ) }
			</div>
			<div style={ { marginBottom: 16 } }>
				<SearchControl
					value={ q }
					onChange={ setQ }
					__nextHasNoMarginBottom
				/>
			</div>
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table">
					<thead>
						<tr>
							<th>{ __( 'Skill', 'agent-builder' ) }</th>
							<th>{ __( 'Agent', 'agent-builder' ) }</th>
							{ isAdvanced && (
								<>
									<th>{ __( 'Source', 'agent-builder' ) }</th>
									<th>
										{ __( 'Version', 'agent-builder' ) }
									</th>
								</>
							) }
							<th>{ __( 'Actions', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.length === 0 ? (
							<tr>
								<td
									colSpan={ isAdvanced ? 5 : 3 }
									className="agentic-react-muted"
								>
									{ q.trim()
										? __(
												'No skills match your search. Try a different keyword or clear the search.',
												'agent-builder'
										  )
										: __(
												'No skills installed yet. Import a recommended skill, create your own, or browse the community above.',
												'agent-builder'
										  ) }
								</td>
							</tr>
						) : (
						rows.map( ( r ) => (
							<tr key={ r.id }>
								<td>
									<span
										className={
											'agentic-react-led' +
											( r.enabled ? ' is-on' : '' )
										}
										title={
											r.enabled
												? __( 'Active', 'agent-builder' )
												: __( 'Disabled', 'agent-builder' )
										}
									/>{ ' ' }
									<strong>{ r.title }</strong>
									{ r.subtitle && (
										<div className="agentic-react-muted">
											{ r.subtitle }
										</div>
									) }
								</td>
								<td>
									{ r.agent ? (
										<code>{ r.agent }</code>
									) : (
										<span className="agentic-react-muted">
											{ __( 'All agents', 'agent-builder' ) }
										</span>
									) }
								</td>
								{ isAdvanced && (
									<>
										<td>
											<span
												className={
													'agentic-react-badge agentic-react-badge--' +
													( r.source || 'local' )
												}
											>
												{ r.source_label ||
													__(
														'Local',
														'agent-builder'
													) }
											</span>
										</td>
										<td>{ r.version || '—' }</td>
									</>
								) }
								<td>
									<a href={ r.edit_url }>
										{ __( 'Edit', 'agent-builder' ) }
									</a>
									{ isAdvanced && r.export_url && (
										<>
											{ ' · ' }
											<a href={ r.export_url }>
												{ __(
													'Export',
													'agent-builder'
												) }
											</a>
										</>
									) }
									{ ' · ' }
									<button
										type="button"
										className="button-link"
										onClick={ () =>
											remove( r.delete_id )
										}
									>
										{ __( 'Delete', 'agent-builder' ) }
									</button>
								</td>
							</tr>
						) )
						) }
					</tbody>
				</table>
			</div>
		</>
	);
}


export default SkillsView;
