import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

// wp#265's dedicated rules CRUD controller — reuse it exactly, no parallel path.
const RULES_PATH = 'agentic/v1/approval-rules';

// The registered-agent roster. Reuse the existing Agents_Payload via the
// admin-page GET (the exact source AgentsView.js reads), rather than adding a
// new route or a duplicate client-side agent list.
const AGENTS_PATH = 'agentic/v1/admin-page?page=agents';

// Plain-English effect copy from PLAN §7 M12. Each completes the sentence the
// rule_text starts, e.g. "Ask me first before publishing anything."
const EFFECT_OPTIONS = [
	{ value: 'ask', label: __( 'Ask me first…', 'agent-builder' ) },
	{ value: 'allow', label: __( 'Allow automatically…', 'agent-builder' ) },
	{ value: 'deny', label: __( 'Never…', 'agent-builder' ) },
];

const EFFECT_LABELS = EFFECT_OPTIONS.reduce( ( map, o ) => {
	map[ o.value ] = o.label;
	return map;
}, {} );

const ALL_AGENTS_LABEL = __( 'All agents', 'agent-builder' );

function ruleEnabled( rule ) {
	return rule.enabled === 1 || rule.enabled === '1' || rule.enabled === true;
}

// Add/edit modal. One form serves both — when `rule` is set we PUT the
// editable fields back to its id, otherwise we POST a fresh rule.
function RuleModal( { rule, agentOptions, onClose, onSaved } ) {
	const isEdit = !! rule;
	const [ agentSlug, setAgentSlug ] = useState(
		rule ? rule.agent_slug || '' : ''
	);
	const [ ruleText, setRuleText ] = useState(
		rule ? rule.rule_text || '' : ''
	);
	const [ effect, setEffect ] = useState(
		rule ? rule.effect || 'ask' : 'ask'
	);
	const [ priority, setPriority ] = useState(
		rule ? String( rule.priority ?? 10 ) : '10'
	);
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState( '' );

	const save = () => {
		if ( busy ) {
			return;
		}
		if ( ! ruleText.trim() ) {
			setErr(
				__(
					'Describe the rule in plain language first.',
					'agent-builder'
				)
			);
			return;
		}
		const parsed = Number.parseInt( priority, 10 );
		setBusy( true );
		setErr( '' );
		apiFetch( {
			path: isEdit ? `${ RULES_PATH }/${ rule.id }` : RULES_PATH,
			method: isEdit ? 'PUT' : 'POST',
			data: {
				agent_slug: agentSlug,
				rule_text: ruleText.trim(),
				effect,
				priority: Number.isNaN( parsed ) ? 10 : parsed,
			},
		} )
			.then( () => {
				setBusy( false );
				onSaved();
			} )
			.catch( ( e ) => {
				setBusy( false );
				setErr(
					e.message ||
						__( 'Could not save the rule.', 'agent-builder' )
				);
			} );
	};

	return (
		<Modal
			title={
				isEdit
					? __( 'Edit rule', 'agent-builder' )
					: __( 'Add rule', 'agent-builder' )
			}
			onRequestClose={ onClose }
			className="agentic-rules-modal"
		>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }

			<SelectControl
				label={ __( 'Applies to', 'agent-builder' ) }
				value={ agentSlug }
				options={ agentOptions }
				onChange={ setAgentSlug }
				help={ __(
					'Choose one agent, or “All agents” to apply the rule to every agent on this site.',
					'agent-builder'
				) }
			/>

			<TextareaControl
				label={ __( 'Rule', 'agent-builder' ) }
				value={ ruleText }
				onChange={ setRuleText }
				placeholder={ __(
					'Ask me first before publishing anything.',
					'agent-builder'
				) }
				help={ __(
					'Describe what the agent should do in plain language.',
					'agent-builder'
				) }
			/>

			<SelectControl
				label={ __( 'Effect', 'agent-builder' ) }
				value={ effect }
				options={ EFFECT_OPTIONS }
				onChange={ setEffect }
			/>

			<TextControl
				label={ __( 'Priority', 'agent-builder' ) }
				type="number"
				value={ priority }
				onChange={ setPriority }
				help={ __(
					'Lower numbers run first when more than one rule matches.',
					'agent-builder'
				) }
			/>

			<div className="agentic-agents-drawer__actions">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'agent-builder' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ save }
				>
					{ isEdit
						? __( 'Save rule', 'agent-builder' )
						: __( 'Add rule', 'agent-builder' ) }
				</Button>
			</div>
		</Modal>
	);
}

function RulesTab() {
	const [ rules, setRules ] = useState( null ); // null = still loading
	const [ agents, setAgents ] = useState( [] );
	const [ isAdmin, setIsAdmin ] = useState( false );
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	const [ busyId, setBusyId ] = useState( '' ); // rule id mid-toggle
	const [ editing, setEditing ] = useState( null ); // rule object being edited
	const [ adding, setAdding ] = useState( false );

	const loadRules = () => {
		apiFetch( { path: RULES_PATH } )
			.then( ( res ) => setRules( res.rules || [] ) )
			.catch( ( e ) => {
				setRules( [] );
				setErr(
					e.message || __( 'Could not load rules.', 'agent-builder' )
				);
			} );
	};

	const loadAgents = () => {
		apiFetch( { path: AGENTS_PATH } )
			.then( ( res ) => {
				setAgents( res.agents || [] );
				setIsAdmin( !! res.is_admin );
			} )
			.catch( () => {
				// Agent list is best-effort; the select still offers the
				// "All agents" option and free editing keeps working without it.
			} );
	};

	useEffect( () => {
		loadRules();
		loadAgents();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const clear = () => {
		setErr( '' );
		setOk( '' );
	};

	const agentOptions = [
		{ value: '', label: ALL_AGENTS_LABEL },
		...agents.map( ( a ) => ( {
			value: a.slug,
			label: a.display_name || a.name || a.slug,
		} ) ),
	];

	const agentLabel = ( slug ) => {
		if ( ! slug ) {
			return ALL_AGENTS_LABEL;
		}
		const found = agents.find( ( a ) => a.slug === slug );
		return found ? found.display_name || found.name || found.slug : slug;
	};

	const toggleEnabled = ( rule ) => {
		const next = ! ruleEnabled( rule );
		setBusyId( String( rule.id ) );
		setErr( '' );
		apiFetch( {
			path: `${ RULES_PATH }/${ rule.id }`,
			method: 'PUT',
			data: { enabled: next },
		} )
			.then( () => {
				setOk(
					next
						? __( 'Rule enabled.', 'agent-builder' )
						: __( 'Rule disabled.', 'agent-builder' )
				);
				loadRules();
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not update the rule.', 'agent-builder' )
				)
			)
			.finally( () => setBusyId( '' ) );
	};

	const deleteRule = ( rule ) => {
		if (
			! window.confirm(
				__(
					'Delete this rule? Agents will no longer follow it.',
					'agent-builder'
				)
			)
		) {
			return;
		}
		setBusyId( String( rule.id ) );
		setErr( '' );
		apiFetch( {
			path: `${ RULES_PATH }/${ rule.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setOk( __( 'Rule deleted.', 'agent-builder' ) );
				loadRules();
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not delete the rule.', 'agent-builder' )
				)
			)
			.finally( () => setBusyId( '' ) );
	};

	const onSaved = () => {
		setAdding( false );
		setEditing( null );
		setOk( __( 'Rule saved.', 'agent-builder' ) );
		loadRules();
	};

	return (
		<>
			{ err && (
				<Notice status="error" isDismissible onRemove={ clear }>
					{ err }
				</Notice>
			) }
			{ ok && (
				<Notice status="success" isDismissible onRemove={ clear }>
					{ ok }
				</Notice>
			) }

			<p className="agentic-react-muted">
				{ __(
					'Rules tell agents how much to ask before acting. An agent pauses for anything a rule asks about, and runs automatically when a rule allows it.',
					'agent-builder'
				) }
			</p>

			<div className="agentic-agents-toolbar">
				{ isAdmin && (
					<Button variant="primary" onClick={ () => setAdding( true ) }>
						{ __( 'Add rule', 'agent-builder' ) }
					</Button>
				) }
				{ ! isAdmin && (
					<span className="agentic-react-muted">
						{ __(
							'Only administrators can add or edit rules.',
							'agent-builder'
						) }
					</span>
				) }
			</div>

			{ rules === null ? (
				<p>
					<Spinner /> { __( 'Loading rules…', 'agent-builder' ) }
				</p>
			) : ! rules.length ? (
				<div className="agentic-react-approvals-empty">
					<p>
						<strong>{ __( 'No rules yet', 'agent-builder' ) }</strong>
					</p>
					<p className="agentic-react-muted">
						{ __(
							'Add a rule to decide how much an agent should ask before it changes your site.',
							'agent-builder'
						) }
					</p>
				</div>
			) : (
				<div className="agentic-react-table-wrap">
					<table className="agentic-react-table agentic-rules-table">
						<thead>
							<tr>
								<th>{ __( 'Agent', 'agent-builder' ) }</th>
								<th>{ __( 'Rule', 'agent-builder' ) }</th>
								<th>{ __( 'Effect', 'agent-builder' ) }</th>
								<th>{ __( 'Priority', 'agent-builder' ) }</th>
								<th>{ __( 'Enabled', 'agent-builder' ) }</th>
								{ isAdmin && <th /> }
							</tr>
						</thead>
						<tbody>
							{ rules.map( ( rule ) => {
								const busy = busyId === String( rule.id );
								return (
									<tr key={ rule.id }>
										<td>{ agentLabel( rule.agent_slug ) }</td>
										<td>{ rule.rule_text }</td>
										<td>
											{ EFFECT_LABELS[ rule.effect ] ||
												rule.effect }
										</td>
										<td>{ rule.priority }</td>
										<td>
											<ToggleControl
												checked={ ruleEnabled( rule ) }
												disabled={ ! isAdmin || busy }
												onChange={ () =>
													toggleEnabled( rule )
												}
											/>
										</td>
										{ isAdmin && (
											<td>
												<div className="agentic-agents-card__row-actions">
													<Button
														variant="link"
														isSmall
														disabled={ busy }
														onClick={ () =>
															setEditing( rule )
														}
													>
														{ __(
															'Edit',
															'agent-builder'
														) }
													</Button>
													<Button
														variant="link"
														isSmall
														isDestructive
														disabled={ busy }
														onClick={ () =>
															deleteRule( rule )
														}
													>
														{ __(
															'Delete',
															'agent-builder'
														) }
													</Button>
												</div>
											</td>
										) }
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ ( adding || editing ) && (
				<RuleModal
					rule={ editing }
					agentOptions={ agentOptions }
					onClose={ () => {
						setAdding( false );
						setEditing( null );
					} }
					onSaved={ onSaved }
				/>
			) }
		</>
	);
}

export default RulesTab;
