import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SearchControl,
	TextareaControl,
} from '@wordpress/components';

function SkillsView( { data, reload } ) {
	const [ q, setQ ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ draftLink, setDraftLink ] = useState( null );
	const [ createOpen, setCreateOpen ] = useState( false );
	const [ createText, setCreateText ] = useState( '' );
	const [ createBusy, setCreateBusy ] = useState( false );
	const [ createErr, setCreateErr ] = useState( '' );
	const isAdvanced = !! data.is_advanced;

	// Drafts are split out of the main table into their own section so a
	// skill-in-progress (source === 'draft') is clearly distinguishable from
	// installed skills.
	const drafts = useMemo(
		() => ( data.rows || [] ).filter( ( r ) => r.source === 'draft' ),
		[ data.rows ]
	);
	const rows = useMemo( () => {
		const all = ( data.rows || [] ).filter(
			( r ) => r.source !== 'draft'
		);
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

	const createFromText = () => {
		setCreateBusy( true );
		setCreateErr( '' );
		apiFetch( {
			path: 'agentic/v1/skills/draft-from-description',
			method: 'POST',
			data: { description: createText },
		} )
			.then( ( result ) => {
				setCreateOpen( false );
				setCreateText( '' );
				setDraftLink( result.edit_url || null );
				reload();
			} )
			.catch( ( e ) =>
				setCreateErr(
					e.message || __( 'Could not create the draft.', 'agent-builder' )
				)
			)
			.finally( () => setCreateBusy( false ) );
	};

	return (
		<>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			{ draftLink && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setDraftLink( null ) }
					actions={ [
						{
							label: __( 'Review draft', 'agent-builder' ),
							url: draftLink,
						},
					] }
				>
					{ __(
						'Draft skill created. Review and publish it to make it available to agents.',
						'agent-builder'
					) }
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
				<Button
					variant="secondary"
					onClick={ () => {
						setCreateErr( '' );
						setCreateOpen( true );
					} }
				>
					{ __( 'Create from text', 'agent-builder' ) }
				</Button>
			</div>
			<div style={ { marginBottom: 16 } }>
				<SearchControl
					value={ q }
					onChange={ setQ }
					__nextHasNoMarginBottom
				/>
			</div>

			{ drafts.length > 0 && (
				<section style={ { marginBottom: 24 } }>
					<h2 style={ { fontSize: 15, margin: '0 0 8px' } }>
						{ __( 'Drafts', 'agent-builder' ) }
					</h2>
					<div className="agentic-react-table-wrap">
						<table className="agentic-react-table">
							<thead>
								<tr>
									<th>{ __( 'Draft', 'agent-builder' ) }</th>
									<th>{ __( 'Actions', 'agent-builder' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ drafts.map( ( r ) => (
									<tr key={ r.id }>
										<td>
											<span
												className="agentic-react-badge agentic-react-badge--draft"
											>
												{ __(
													'Draft',
													'agent-builder'
												) }
											</span>{ ' ' }
											<strong>{ r.title }</strong>
											{ r.subtitle && (
												<div className="agentic-react-muted">
													{ r.subtitle }
												</div>
											) }
										</td>
										<td>
											<a href={ r.edit_url }>
												{ __(
													'Review',
													'agent-builder'
												) }
											</a>
											{ ' · ' }
											<button
												type="button"
												className="button-link"
												onClick={ () =>
													remove( r.delete_id )
												}
											>
												{ __(
													'Delete',
													'agent-builder'
												) }
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</section>
			) }

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

			{ createOpen && (
				<Modal
					title={ __( 'Create a skill from text', 'agent-builder' ) }
					onRequestClose={ () => setCreateOpen( false ) }
				>
					{ createErr && (
						<Notice
							status="error"
							isDismissible={ false }
						>
							{ createErr }
						</Notice>
					) }
					<p>
						{ __(
							'Describe the task you want to teach the agent. A draft skill is generated from your description for you to review.',
							'agent-builder'
						) }
					</p>
					<TextareaControl
						label={ __( 'Description', 'agent-builder' ) }
						value={ createText }
						onChange={ setCreateText }
						rows={ 6 }
					/>
					<div
						style={ {
							display: 'flex',
							justifyContent: 'flex-end',
							gap: 8,
							marginTop: 16,
						} }
					>
						<Button
							variant="secondary"
							onClick={ () => setCreateOpen( false ) }
						>
							{ __( 'Cancel', 'agent-builder' ) }
						</Button>
						<Button
							variant="primary"
							isBusy={ createBusy }
							disabled={ ! createText.trim() || createBusy }
							onClick={ createFromText }
						>
							{ __( 'Draft skill', 'agent-builder' ) }
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
}

export default SkillsView;
