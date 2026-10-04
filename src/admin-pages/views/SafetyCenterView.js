import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import { InfoTip } from '../../shared/components';

function SafetyRiskInventory( { inventory, urls } ) {
	const tiers = inventory.tiers || [];
	const highest = inventory.highest_enabled || [];

	return (
		<section
			className="agentic-safety-inventory"
			id="agentic-safety-inventory"
			aria-labelledby="agentic-safety-inventory-heading"
		>
			<h2
				id="agentic-safety-inventory-heading"
				className="agentic-safety-section__title"
			>
				{ __( 'Risk inventory', 'agent-builder' ) }
			</h2>
			<div className="agentic-safety-strip">
				{ tiers.map( ( tier ) => (
					<article
						key={ tier.id }
						className={
							'agentic-safety-tile agentic-safety-tile--' +
							( tier.id || 'none' )
						}
					>
						<h3 className="agentic-safety-tile__title">
							{ tier.short_label || tier.label || tier.id }
						</h3>
						<p className="agentic-safety-tile__stat">
							{ sprintf(
								/* translators: 1: enabled tools, 2: disabled tools */
								__(
									'%1$d enabled · %2$d disabled',
									'agent-builder'
								),
								tier.enabled ?? 0,
								tier.disabled ?? 0
							) }
						</p>
						<p className="agentic-safety-tile__hint">
							{ tier.explanation }
						</p>
						{ ( tier.examples || [] ).length ? (
							<ul className="agentic-safety-tile__examples">
								{ tier.examples.map( ( ex ) => (
									<li key={ ex.name }>
										<code>{ ex.label || ex.name }</code>
									</li>
								) ) }
							</ul>
						) : null }
					</article>
				) ) }
			</div>

			<div className="agentic-safety-highest">
				<h3 className="agentic-safety-card__title">
					{ __(
						'Highest-risk tools currently enabled',
						'agent-builder'
					) }
				</h3>
				{ highest.length ? (
					<ul className="agentic-safety-tool-list">
						{ highest.map( ( tool ) => (
							<li key={ tool.name }>
								<code>{ tool.label || tool.name }</code>
								<span
									className={
										'agentic-react-risk agentic-react-risk--' +
										( tool.risk || 'high' )
									}
								>
									{ tool.risk_label || tool.risk }
								</span>
								{ tool.description ? (
									<span className="agentic-safety-tool-list__desc">
										{ tool.description }
									</span>
								) : null }
							</li>
						) ) }
					</ul>
				) : (
					<p className="agentic-safety-card__hint">
						{ __(
							'No high-risk or extreme-risk tools are enabled on this site right now.',
							'agent-builder'
						) }
					</p>
				) }
				<div className="agentic-safety-highest__actions">
					<a className="button" href={ urls.tools || '#' }>
						{ __( 'Manage tools', 'agent-builder' ) }
					</a>
					<a className="button" href={ urls.approvals || '#' }>
						{ __( 'Open approval settings', 'agent-builder' ) }
					</a>
				</div>
			</div>
		</section>
	);
}

function SafetyScopeToolList( { tools, isAdvanced, high } ) {
	return (
		<ul
			className={
				'agentic-safety-tool-list' +
				( high ? ' agentic-safety-tool-list--high' : '' )
			}
		>
			{ tools.map( ( tool ) => (
				<li key={ tool.name }>
					<code>
						{ isAdvanced ? tool.name : tool.label || tool.name }
					</code>
					{ isAdvanced && tool.label ? (
						<span className="agentic-safety-tool-list__label">
							{ tool.label }
						</span>
					) : null }
					<span
						className={
							'agentic-react-risk agentic-react-risk--' +
							( tool.risk || ( high ? 'high' : 'none' ) )
						}
					>
						{ tool.risk_label || tool.risk }
					</span>
				</li>
			) ) }
		</ul>
	);
}

function SafetyScopeRestTools( { tools, isAdvanced } ) {
	if ( ! tools.length ) {
		return null;
	}
	const heading = sprintf(
		/* translators: %d: remaining tool count */
		__( '%d more tools', 'agent-builder' ),
		tools.length
	);
	const list = (
		<SafetyScopeToolList tools={ tools } isAdvanced={ !! isAdvanced } />
	);
	if ( isAdvanced ) {
		return (
			<>
				<h4 className="agentic-safety-scope__rest-title">
					{ heading }
				</h4>
				{ list }
			</>
		);
	}
	return (
		<details className="agentic-safety-scope__rest">
			<summary>{ heading }</summary>
			{ list }
		</details>
	);
}

function SafetyAgentScopes( { agents, urls, isAdvanced } ) {
	const items = agents.items || [];
	const integrityNote =
		agents.integrity_note ||
		__(
			'Tool list blocked if manifest signature fails.',
			'agent-builder'
		);

	return (
		<section
			className="agentic-safety-scopes"
			id="agentic-safety-scopes"
			aria-labelledby="agentic-safety-scopes-heading"
		>
			<h2
				id="agentic-safety-scopes-heading"
				className="agentic-safety-section__title"
			>
				{ __( 'Per-agent tool scopes', 'agent-builder' ) }
			</h2>
			{ items.length ? (
				<div className="agentic-safety-scope-grid">
					{ items.map( ( agent ) => {
						const counts = agent.risk_counts || {};
						const otherTools = agent.other_tools || [];
						return (
							<article
								key={ agent.slug }
								className="agentic-safety-card agentic-safety-scope"
							>
								<h3 className="agentic-safety-card__title">
									{ agent.name || agent.slug }
								</h3>
								{ isAdvanced && agent.slug ? (
									<p className="agentic-safety-card__meta">
										<code>{ agent.slug }</code>
									</p>
								) : null }
								<p className="agentic-safety-card__meta">
									{ sprintf(
										/* translators: 1: version, 2: author */
										__(
											'Version %1$s · %2$s',
											'agent-builder'
										),
										agent.version || '—',
										agent.author || '—'
									) }
								</p>
								<p className="agentic-safety-card__meta">
									{ sprintf(
										/* translators: %s: yes or no */
										__( 'MCP enabled: %s', 'agent-builder' ),
										agent.mcp_enabled
											? __( 'Yes', 'agent-builder' )
											: __( 'No', 'agent-builder' )
									) }
									{ ' · ' }
									{ __( 'Highest risk', 'agent-builder' ) }{ ' ' }
									<span
										className={
											'agentic-react-risk agentic-react-risk--' +
											( agent.highest_risk || 'none' )
										}
									>
										{ agent.highest_risk_label ||
											agent.highest_risk ||
											'none' }
									</span>
								</p>
								<p className="agentic-safety-card__meta">
									{ sprintf(
										/* translators: 1: none 2: low 3: medium 4: high 5: extreme */
										__(
											'None %1$d · Low %2$d · Medium %3$d · High %4$d · Extreme %5$d',
											'agent-builder'
										),
										counts.none ?? 0,
										counts.low ?? 0,
										counts.medium ?? 0,
										counts.high ?? 0,
										counts.extreme ?? 0
									) }
								</p>
								{ ( agent.high_tools || [] ).length ? (
									<SafetyScopeToolList
										tools={ agent.high_tools }
										isAdvanced={ isAdvanced }
										high
									/>
								) : (
									<p className="agentic-safety-card__hint">
										{ __(
											'No high-risk or extreme-risk tools declared.',
											'agent-builder'
										) }
									</p>
								) }
								<SafetyScopeRestTools
									tools={ otherTools }
									isAdvanced={ isAdvanced }
								/>
								<p className="agentic-safety-scope__integrity">
									{ integrityNote }
								</p>
								<div className="agentic-safety-scope__actions">
									<a
										className="button"
										href={ urls.agents || '#' }
									>
										{ __(
											'Manage this agent',
											'agent-builder'
										) }
									</a>
									<a
										className="button"
										href={ urls.tools || '#' }
									>
										{ __(
											'Manage tools',
											'agent-builder'
										) }
									</a>
								</div>
							</article>
						);
					} ) }
				</div>
			) : (
				<p className="agentic-safety-card__hint">
					{ __(
						'No active agents on this site right now.',
						'agent-builder'
					) }
				</p>
			) }
		</section>
	);
}

function SafetyAuditIntegrity( { integrity, urls, valid, isAdvanced } ) {
	const brokenAt = integrity.broken_at_id;
	const chainStart = integrity.chain_start_id;
	const rawValue = ( value ) =>
		Number.isInteger( value ) ? String( value ) : 'null';

	return (
		<section
			className="agentic-safety-integrity"
			id="agentic-safety-integrity"
			aria-labelledby="agentic-safety-integrity-heading"
		>
			<h2
				id="agentic-safety-integrity-heading"
				className="agentic-safety-section__title"
			>
				{ __( 'Audit-log integrity', 'agent-builder' ) }
			</h2>

			{ ! valid ? (
				<article
					className="agentic-safety-incident"
					role="alert"
				>
					<h3 className="agentic-safety-incident__title">
						{ __(
							'Audit log may have been altered after the fact',
							'agent-builder'
						) }
					</h3>
					<p className="agentic-safety-incident__lead">
						{ brokenAt
							? sprintf(
									/* translators: %d: audit log row id where the hash chain broke */
									__(
										'The verification check failed at entry #%d.',
										'agent-builder'
									),
									brokenAt
							  )
							: __(
									'The verification check failed. An entry was edited or deleted after the fact.',
									'agent-builder'
							  ) }
					</p>
					<p className="agentic-safety-incident__label">
						{ __( 'Recommended next steps', 'agent-builder' ) }
					</p>
					<ul className="agentic-safety-incident__steps">
						<li>
							<a href="#agentic-safety-emergency">
								{ __( 'Pause agents', 'agent-builder' ) }
							</a>
							{ ' — ' }
							{ __(
								'use Emergency Stop on this page until you understand the break.',
								'agent-builder'
							) }
						</li>
						<li>
							{ urls.export ? (
								<a href={ urls.export }>
									{ __( 'Export logs', 'agent-builder' ) }
								</a>
							) : (
								__( 'Export logs', 'agent-builder' )
							) }
							{ ' — ' }
							{ __(
								'download a copy of the current history before anything else changes.',
								'agent-builder'
							) }
						</li>
						<li>
							{ __(
								'Review hosting and database access for unexpected changes.',
								'agent-builder'
							) }
						</li>
					</ul>
				</article>
			) : null }

			<article className="agentic-safety-card agentic-safety-integrity__status">
				<h3 className="agentic-safety-card__title">
					{ __( 'Last verification', 'agent-builder' ) }
				</h3>
				<p className="agentic-safety-card__stat">
					<span
						className={
							'agentic-safety-pill' +
							( valid ? ' is-ok' : ' is-attention' )
						}
					>
						{ valid
							? __( 'Verified', 'agent-builder' )
							: __( 'Needs attention', 'agent-builder' ) }
					</span>
				</p>
				<p className="agentic-safety-card__meta">
					{ sprintf(
						/* translators: %d: number of chained audit rows checked */
						__( '%d rows checked', 'agent-builder' ),
						integrity.checked ?? 0
					) }
				</p>
				{ ! valid && brokenAt ? (
					<p className="agentic-safety-card__meta">
						{ sprintf(
							/* translators: %d: audit log row id */
							__( 'Broken at entry #%d', 'agent-builder' ),
							brokenAt
						) }
					</p>
				) : null }
				{ isAdvanced ? (
					<dl className="agentic-safety-raw">
						<div>
							<dt>
								<code>valid</code>
							</dt>
							<dd>{ valid ? 'true' : 'false' }</dd>
						</div>
						<div>
							<dt>
								<code>checked</code>
							</dt>
							<dd>{ String( integrity.checked ?? 0 ) }</dd>
						</div>
						<div>
							<dt>
								<code>chain_start_id</code>
							</dt>
							<dd>{ rawValue( chainStart ) }</dd>
						</div>
						<div>
							<dt>
								<code>broken_at_id</code>
							</dt>
							<dd>{ rawValue( brokenAt ) }</dd>
						</div>
					</dl>
				) : null }
				<p className="agentic-safety-card__hint">
					{ __(
						'This activity log is tamper-evident. Each entry is linked to the one before it, so if someone edits or deletes a later entry after the fact, the verification check fails.',
						'agent-builder'
					) }
				</p>
				<p className="agentic-safety-card__hint">
					{ __(
						'This does not stop database access by itself. It gives you evidence if the history can no longer be trusted.',
						'agent-builder'
					) }
				</p>
				<p className="agentic-safety-card__hint">
					{ chainStart
						? sprintf(
								/* translators: %d: first chained audit log row id */
								__(
									'Entries written before this feature shipped may predate the chain and therefore define the chain start. The chain starts at entry #%d.',
									'agent-builder'
								),
								chainStart
						  )
						: __(
								'Entries written before this feature shipped may predate the chain and therefore define the chain start. No chained entries have been recorded yet.',
								'agent-builder'
						  ) }
				</p>
				<a className="button" href={ urls.activity || '#' }>
					{ __(
						'View raw Activity / Audit log',
						'agent-builder'
					) }
				</a>
			</article>
		</section>
	);
}

function SafetyCenterView( { data, reload } ) {
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState( '' );
	const tools = data.tools || {};
	const approvals = data.approvals || {};
	const integrity = data.integrity || {};
	const emergency = data.emergency_stop || {};
	const agents = data.agents || {};
	const urls = data.urls || {};
	const integrityValid = !! integrity.valid;

	const setEmergency = ( enable ) => {
		const msg = enable
			? __(
					'EMERGENCY STOP: deactivate and log agent states, cancel all jobs, and disconnect providers. Continue?',
					'agent-builder'
			  )
			: __( 'Turn off emergency stop?', 'agent-builder' );
		if ( ! window.confirm( msg ) ) {
			return;
		}
		setBusy( true );
		setErr( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'set_emergency_stop', enable },
		} )
			.then( ( res ) => {
				const warnings = Array.isArray( res?.warnings )
					? res.warnings
					: [];
				if ( warnings.length ) {
					window.alert(
						__(
							'Emergency stop restore finished with warnings:',
							'agent-builder'
						) +
							'\n\n' +
							warnings.join( '\n' )
					);
				}
				reload( { silent: true } );
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__(
							'Could not change Emergency Stop.',
							'agent-builder'
						)
				)
			)
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			{ err && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setErr( '' ) }
				>
					{ err }
				</Notice>
			) }

			{ data.is_advanced ? (
				<p className="agentic-react-muted">
					{ __(
						"Advanced view expands every agent's tool list and shows the raw verify_chain() fields for the audit log.",
						'agent-builder'
					) }
				</p>
			) : null }

			<div className="agentic-safety-overview">
				<article className="agentic-safety-card">
					<h3 className="agentic-safety-card__title">
						{ __( 'Tool risk inventory', 'agent-builder' ) }
					</h3>
					<p className="agentic-safety-card__stat">
						{ sprintf(
							/* translators: 1: enabled tools, 2: disabled tools */
							__( '%1$d enabled · %2$d disabled', 'agent-builder' ),
							tools.enabled_count ?? 0,
							tools.disabled_count ?? 0
						) }
					</p>
					<p className="agentic-safety-card__meta">
						{ __( 'Highest enabled risk', 'agent-builder' ) }{ ' ' }
						<span
							className={
								'agentic-react-risk agentic-react-risk--' +
								( tools.enabled_max_risk || 'none' )
							}
						>
							{ tools.max_risk_label ||
								tools.enabled_max_risk ||
								'none' }
						</span>
					</p>
					<p className="agentic-safety-card__hint">
						{ __(
							'High-risk tools require extra care when you enable them. Review them in Tools.',
							'agent-builder'
						) }
					</p>
					<a className="button" href={ urls.tools || '#' }>
						{ __( 'Review tools', 'agent-builder' ) }
					</a>
				</article>

				<article className="agentic-safety-card">
					<h3 className="agentic-safety-card__title">
						{ __( 'Approvals status', 'agent-builder' ) }
					</h3>
					<p className="agentic-safety-card__stat">
						{ sprintf(
							/* translators: %d: pending approvals */
							__( '%d waiting for your OK', 'agent-builder' ),
							approvals.pending_count ?? 0
						) }
					</p>
					<p className="agentic-safety-card__meta">
						{ sprintf(
							/* translators: 1: operating mode, 2: comfort profile */
							__( 'Mode: %1$s · Comfort: %2$s', 'agent-builder' ),
							approvals.agent_mode_label ||
								approvals.agent_mode ||
								'',
							approvals.comfort_label || approvals.comfort || ''
						) }
					</p>
					<p className="agentic-safety-card__hint">
						{ __(
							'Actions that need your approval wait here. A queued action does not run until you approve it.',
							'agent-builder'
						) }
					</p>
					<a className="button" href={ urls.approvals || '#' }>
						{ __( 'Open approvals', 'agent-builder' ) }
					</a>
				</article>

				<article
					className={
						'agentic-safety-card' +
						( integrityValid ? '' : ' is-emergency' )
					}
				>
					<h3 className="agentic-safety-card__title">
						{ __( 'Last verification', 'agent-builder' ) }
					</h3>
					<p className="agentic-safety-card__stat">
						<span
							className={
								'agentic-safety-pill' +
								( integrityValid
									? ' is-ok'
									: ' is-attention' )
							}
						>
							{ integrityValid
								? __( 'Verified', 'agent-builder' )
								: __( 'Needs attention', 'agent-builder' ) }
						</span>
					</p>
					<p className="agentic-safety-card__meta">
						{ sprintf(
							/* translators: %d: number of chained audit rows checked */
							__( '%d rows checked', 'agent-builder' ),
							integrity.checked ?? 0
						) }
					</p>
					{ ! integrityValid && integrity.broken_at_id ? (
						<p className="agentic-safety-card__meta">
							{ sprintf(
								/* translators: %d: audit log row id */
								__(
									'Broken at entry #%d',
									'agent-builder'
								),
								integrity.broken_at_id
							) }
						</p>
					) : null }
					<a className="button" href="#agentic-safety-integrity">
						{ __( 'View integrity details', 'agent-builder' ) }
					</a>
				</article>

				<article
					id="agentic-safety-emergency"
					className={
						'agentic-safety-card' +
						( emergency.active ? ' is-emergency' : '' )
					}
				>
					<h3 className="agentic-safety-card__title">
						{ __( 'Emergency Stop', 'agent-builder' ) }
					</h3>
					<p className="agentic-safety-card__stat">
						<span
							className={
								'agentic-safety-pill' +
								( emergency.active
									? ' is-attention'
									: ' is-ok' )
							}
						>
							{ emergency.active
								? __( 'On', 'agent-builder' )
								: __( 'Off', 'agent-builder' ) }
						</span>
					</p>
					<p className="agentic-safety-card__hint">
						{ __(
							'Emergency Stop turns off every active agent, cancels pending and in-progress jobs, disconnects AI providers, and blocks new agent activity until an administrator restores service.',
							'agent-builder'
						) }
					</p>
					<Button
						variant={
							emergency.active ? 'primary' : 'secondary'
						}
						isDestructive={ ! emergency.active }
						isBusy={ busy }
						disabled={ busy }
						onClick={ () =>
							setEmergency( ! emergency.active )
						}
					>
						{ emergency.active
							? __( 'Restore agent system', 'agent-builder' )
							: __( 'Disable All Agents', 'agent-builder' ) }
					</Button>
				</article>

				<article className="agentic-safety-card">
					<h3 className="agentic-safety-card__title">
						{ __( 'Active agents', 'agent-builder' ) }
					</h3>
					<p className="agentic-safety-card__stat">
						{ sprintf(
							/* translators: %d: active agent count */
							__( '%d active', 'agent-builder' ),
							agents.active_count ?? 0
						) }
					</p>
					<p className="agentic-safety-card__meta">
						{ sprintf(
							/* translators: 1: agents with a high-risk tool, 2: agents with MCP enabled */
							__(
								'%1$d with a high-risk tool · %2$d with MCP on',
								'agent-builder'
							),
							agents.high_risk_count ?? 0,
							agents.mcp_count ?? 0
						) }
					</p>
					<a className="button" href="#agentic-safety-scopes">
						{ __( 'View agent scopes', 'agent-builder' ) }
					</a>
				</article>
			</div>

			<SafetyRiskInventory
				inventory={ data.risk_inventory || {} }
				urls={ urls }
			/>

			<SafetyAuditIntegrity
				integrity={ integrity }
				urls={ urls }
				valid={ integrityValid }
				isAdvanced={ !! data.is_advanced }
			/>

			<SafetyAgentScopes
				agents={ agents }
				urls={ urls }
				isAdvanced={ !! data.is_advanced }
			/>

			<aside className="agentic-safety-passport">
				<h3 className="agentic-safety-card__title">
					{ __(
						'Looking for AI discoverability and access, not safety controls?',
						'agent-builder'
					) }
				</h3>
				<p className="agentic-safety-card__hint">
					{ __(
						'Visit Site Passport to see what outside AI systems can discover and reach on this site.',
						'agent-builder'
					) }
				</p>
				<a className="button" href={ urls.passport || '#' }>
					{ __( 'Open Site Passport', 'agent-builder' ) }
				</a>
			</aside>

			<nav
				className="agentic-safety-crosslinks"
				aria-label={ __( 'Related screens', 'agent-builder' ) }
			>
				<a href={ urls.tools || '#' }>
					{ __( 'Tools', 'agent-builder' ) }
				</a>
				<a href={ urls.approvals || '#' }>
					{ __( 'Approvals', 'agent-builder' ) }
				</a>
				<a href={ urls.activity || '#' }>
					{ __( 'Activity', 'agent-builder' ) }
				</a>
				<a href={ urls.passport || '#' }>
					{ __( 'Passport', 'agent-builder' ) }
				</a>
			</nav>
		</>
	);
}


export default SafetyCenterView;
