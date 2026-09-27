import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

const AGENT_READY_CHECK_LABELS = {
	mcp_server_reachable: __( 'MCP server reachable', 'agent-builder' ),
	webmcp_tools_registered: __( 'WebMCP tools registered', 'agent-builder' ),
	approval_gate_configured: __( 'Approval gate configured', 'agent-builder' ),
	llms_txt_present: __( 'llms.txt present', 'agent-builder' ),
	robots_ai_directives: __( 'AI crawler directives in robots.txt', 'agent-builder' ),
	schema_org_present: __( 'Organization/WebSite schema', 'agent-builder' ),
	well_known_manifest: __( 'WebMCP discovery manifest', 'agent-builder' ),
	commerce_readiness: __( 'Commerce readiness', 'agent-builder' ),
};

// Checks whose only fix path today is the bundled AI Radar agent (a chat
// conversation, not a one-click REST action like the other fixable checks
// below) — everything else non-fixable (currently just commerce_readiness)
// has no fix UI at all yet, so it must not show the AI Radar link either.
// See class-agent-ready-score.php's check_commerce_readiness() docblock.
const AI_RADAR_FIXABLE_CHECKS = [ 'llms_txt_present', 'robots_ai_directives', 'schema_org_present' ];

function AgentReadyFixList( { categories, onApplyFix, applying } ) {
	const entries = Object.entries( categories || {} )
		.filter( ( [ , check ] ) => Number( check.score ) < 90 )
		.sort( ( a, b ) => Number( a[ 1 ].score ) - Number( b[ 1 ].score ) )
		.slice( 0, 3 );

	if ( 0 === entries.length ) {
		return (
			<p className="agentic-react-lead">
				{ __( 'Nothing urgent — every check looks good.', 'agent-builder' ) }
			</p>
		);
	}

	return (
		<ul className="agentic-agent-ready-fixlist">
			{ entries.map( ( [ id, check ] ) => (
				<li key={ id }>
					<strong>{ AGENT_READY_CHECK_LABELS[ id ] || id }</strong>
					<p className="agentic-react-muted">{ check.detail }</p>
					{ check.fixable ? (
						<Button
							variant="secondary"
							isBusy={ applying === id }
							disabled={ Boolean( applying ) }
							onClick={ () => onApplyFix( id ) }
						>
							{ __( 'Fix now', 'agent-builder' ) }
						</Button>
					) : AI_RADAR_FIXABLE_CHECKS.includes( id ) ? (
						<Button variant="link" href="admin.php?page=agentic-chat&agent=ai-radar">
							{ __( 'Fix this with AI Radar →', 'agent-builder' ) }
						</Button>
					) : (
						<span className="agentic-react-muted">
							{ __( 'No one-click fix yet.', 'agent-builder' ) }
						</span>
					) }
				</li>
			) ) }
		</ul>
	);
}

// The one free fix tool each fixable check maps to (see class-agent-ready-
// score.php's check_*() docblocks for why each check picked this tool).
const FIX_TOOL_FOR_CHECK = {
	mcp_server_reachable: 'resign_agent_manifest',
	webmcp_tools_registered: 'enable_webmcp_defaults',
	approval_gate_configured: 'configure_approval_gate',
	well_known_manifest: 'enable_agent_readiness',
};

function AgentReadyView( { data, reload } ) {
	const [ applying, setApplying ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const score = data.score || {};
	const categories = score.categories || {};

	const applyFix = ( checkId ) => {
		const toolName = FIX_TOOL_FOR_CHECK[ checkId ];
		if ( ! toolName ) {
			return;
		}
		setApplying( checkId );
		setError( '' );
		const args = 'well_known_manifest' === checkId ? { enabled: true } : {};
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'apply_free_fix', tool_name: toolName, arguments: args },
		} )
			.then( () => reload( { silent: true } ) )
			.catch( ( e ) => setError( e.message || __( 'Could not apply that fix.', 'agent-builder' ) ) )
			.finally( () => setApplying( '' ) );
	};

	const toggleWebmcp = ( enabled ) => {
		setError( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'apply_free_fix',
				tool_name: 'enable_agent_readiness',
				arguments: { enabled },
			},
		} )
			.then( () => reload( { silent: true } ) )
			.catch( ( e ) => setError( e.message || __( 'Could not change that setting.', 'agent-builder' ) ) );
	};

	const [ submitting, setSubmitting ] = useState( false );
	const submitToDirectory = () => {
		setSubmitting( true );
		setError( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'submit_to_directory' },
		} )
			.then( () => reload( { silent: true } ) )
			.catch( ( e ) => setError( e.message || __( 'Could not submit to the directory.', 'agent-builder' ) ) )
			.finally( () => setSubmitting( false ) );
	};

	const toggleExpose = ( agentSlug, toolName, expose ) => {
		setError( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'toggle_webmcp_expose', agent_slug: agentSlug, tool_name: toolName, expose },
		} )
			.then( () => reload( { silent: true } ) )
			.catch( ( e ) => setError( e.message || __( 'Could not change exposure.', 'agent-builder' ) ) );
	};

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			<div className="agentic-agent-ready-gauge">
				<span className="agentic-agent-ready-gauge__score">{ score.overall ?? 0 }</span>
				<span className="agentic-agent-ready-gauge__grade">{ score.grade || '—' }</span>
			</div>

			<AgentReadyFixList categories={ categories } onApplyFix={ applyFix } applying={ applying } />

			<div className="agentic-agent-ready-webmcp-toggle">
				<ToggleControl
					label={ __( 'Let AI agents access my site (turn on the WebMCP Bridge)', 'agent-builder' ) }
					checked={ Boolean( data.webmcp_enabled ) }
					onChange={ toggleWebmcp }
				/>
			</div>

			<p>
				<Button variant="secondary" isBusy={ submitting } disabled={ submitting } onClick={ submitToDirectory }>
					{ __( 'Submit to Directory', 'agent-builder' ) }
				</Button>
			</p>

			{ data.is_advanced && (
				<>
					<h3>{ __( 'All checks', 'agent-builder' ) }</h3>
					<div className="agentic-react-table-wrap">
						<table className="agentic-react-table">
							<thead>
								<tr>
									<th>{ __( 'Check', 'agent-builder' ) }</th>
									<th>{ __( 'Category', 'agent-builder' ) }</th>
									<th>{ __( 'Score', 'agent-builder' ) }</th>
									<th>{ __( 'Detail', 'agent-builder' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ Object.entries( categories ).map( ( [ id, check ] ) => (
									<tr key={ id }>
										<td>{ AGENT_READY_CHECK_LABELS[ id ] || id }</td>
										<td>{ check.category }</td>
										<td>{ check.score }</td>
										<td>{ check.detail }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					<h3>{ __( 'WebMCP tool exposure', 'agent-builder' ) }</h3>
					{ 0 === ( data.webmcp_matrix || [] ).length ? (
						<p className="agentic-react-muted">
							{ __( 'No tools are currently exposed to agents via WebMCP.', 'agent-builder' ) }
						</p>
					) : (
					<div className="agentic-react-table-wrap">
						<table className="agentic-react-table">
							<thead>
								<tr>
									<th>{ __( 'Agent', 'agent-builder' ) }</th>
									<th>{ __( 'Tool', 'agent-builder' ) }</th>
									<th>{ __( 'Context', 'agent-builder' ) }</th>
									<th>{ __( 'Risk', 'agent-builder' ) }</th>
									<th>{ __( 'Exposed', 'agent-builder' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.webmcp_matrix.map( ( row ) => (
									<tr key={ `${ row.agent_slug }:${ row.tool_name }` }>
										<td>{ row.agent_slug }</td>
										<td><code>{ row.tool_name }</code></td>
										<td>{ row.webmcp_context }</td>
										<td>{ row.risk }</td>
										<td>
											<ToggleControl
												checked
												onChange={ ( value ) => toggleExpose( row.agent_slug, row.tool_name, value ) }
											/>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					) }

					{ data.directory_status && data.directory_status.submitted_at && (
						<p className="agentic-react-muted">
							{ sprintf(
								/* translators: 1: submission status, 2: date. */
								__( 'Directory submission: %1$s (%2$s)', 'agent-builder' ),
								data.directory_status.status,
								data.directory_status.submitted_at
							) }
						</p>
					) }
				</>
			) }
		</>
	);
}


export default AgentReadyView;
