/**
 * Tasks screen — assign an autonomous run, leave, and get pinged when it
 * finishes or pauses on you (M11-3).
 *
 * A standalone React entry (tasks-app) rather than a shared admin-pages view:
 * it holds a live, polling run list and a composer that POSTs straight to the
 * Runs_REST endpoints, so it reads its own localize payload (agenticTasksPage)
 * and fetches /runs directly instead of going through /admin-page.
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Button,
	Flex,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { AdminPage, Panel } from '../shared/components';
import { ProposalCard } from '../shared/chat-embed';

const RUNS_PATH = 'agentic/v1/runs';

// Statuses grouped into the three tabs. "Active" = still progressing in the
// background; "Waiting on you" = paused on an approval/proposal; "Done" = any
// terminal state. Mirrors the statuses Agent_Run reports.
const ACTIVE_STATUSES = [ 'queued', 'running', 'continuing' ];
const WAITING_STATUSES = [ 'waiting' ];
const DONE_STATUSES = [ 'completed', 'failed', 'aborted', 'cancelled', 'error' ];
const NON_TERMINAL = [ ...ACTIVE_STATUSES, ...WAITING_STATUSES ];

const TABS = [
	{ id: 'active', label: __( 'Active', 'agent-builder' ) },
	{ id: 'waiting', label: __( 'Waiting on you', 'agent-builder' ) },
	{ id: 'done', label: __( 'Done', 'agent-builder' ) },
];

const STATUS_LABELS = {
	queued: __( 'Queued', 'agent-builder' ),
	running: __( 'Running', 'agent-builder' ),
	continuing: __( 'Continuing', 'agent-builder' ),
	waiting: __( 'Waiting', 'agent-builder' ),
	completed: __( 'Completed', 'agent-builder' ),
	failed: __( 'Failed', 'agent-builder' ),
	aborted: __( 'Aborted', 'agent-builder' ),
	cancelled: __( 'Cancelled', 'agent-builder' ),
	error: __( 'Error', 'agent-builder' ),
};

function statusGroup( status ) {
	if ( WAITING_STATUSES.includes( status ) ) {
		return 'waiting';
	}
	if ( DONE_STATUSES.includes( status ) ) {
		return 'done';
	}
	return 'active';
}

function humanize( text ) {
	return String( text || '' ).replace( /_/g, ' ' );
}

function formatTime( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return iso;
	}
	return d.toLocaleString();
}

// Elapsed between started_at and finished_at (or "now" for a live run), shown
// as a compact "2m 30s" / "1h 5m" / "3d 2h" string.
function formatElapsed( run, now ) {
	const start = new Date( run.started_at || run.updated_at );
	if ( Number.isNaN( start.getTime() ) ) {
		return '';
	}
	const end = run.finished_at
		? new Date( run.finished_at )
		: new Date( now );
	let secs = Math.max( 0, Math.floor( ( end - start ) / 1000 ) );

	const days = Math.floor( secs / 86400 );
	secs -= days * 86400;
	const hours = Math.floor( secs / 3600 );
	secs -= hours * 3600;
	const mins = Math.floor( secs / 60 );
	secs -= mins * 60;

	if ( days > 0 ) {
		return `${ days }d ${ hours }h`;
	}
	if ( hours > 0 ) {
		return `${ hours }h ${ mins }m`;
	}
	if ( mins > 0 ) {
		return `${ mins }m ${ secs }s`;
	}
	return `${ secs }s`;
}

function StatusChip( { status } ) {
	const label = STATUS_LABELS[ status ] || status;
	return (
		<span className={ `agentic-react-status agentic-react-status--${ status }` }>
			{ label }
		</span>
	);
}

function bootConfig() {
	return window.agenticTasksPage || {};
}

function TasksApp() {
	const cfg = bootConfig();
	const agents = cfg.agents || [];
	const isAdvanced = !! cfg.isAdvanced;
	const canRun = !! cfg.canRun;
	const footer = cfg.footer || {};

	const agentById = useMemo( () => {
		const map = {};
		agents.forEach( ( a ) => {
			map[ a.id ] = a;
		} );
		return map;
	}, [ agents ] );

	const agentName = useCallback(
		( slug ) => ( agentById[ slug ] ? agentById[ slug ].name : slug ),
		[ agentById ]
	);

	const [ runs, setRuns ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ loadError, setLoadError ] = useState( '' );
	const [ activeTab, setActiveTab ] = useState( 'active' );

	// Composer state.
	const [ assignAgent, setAssignAgent ] = useState( '' );
	const [ assignTask, setAssignTask ] = useState( '' );
	const [ assigning, setAssigning ] = useState( false );
	const [ assignError, setAssignError ] = useState( '' );
	const [ assignDone, setAssignDone ] = useState( '' );

	// Drawer state.
	const [ drawerRunId, setDrawerRunId ] = useState( null );
	const [ drawer, setDrawer ] = useState( null );
	const [ drawerLoading, setDrawerLoading ] = useState( false );
	const [ drawerError, setDrawerError ] = useState( '' );
	const [ drawerBusy, setDrawerBusy ] = useState( '' );
	const [ drawerActionError, setDrawerActionError ] = useState( '' );

	// Track the currently-open run in a ref so in-flight drawer fetches can
	// tell whether they're still the run on screen (see loadDrawer's
	// out-of-order guard).
	const drawerRunIdRef = useRef( null );
	useEffect( () => {
		drawerRunIdRef.current = drawerRunId;
	}, [ drawerRunId ] );

	const loadRuns = useCallback( ( silent ) => {
		if ( ! silent ) {
			setLoading( true );
		}
		apiFetch( { path: `${ RUNS_PATH }?per_page=200` } )
			.then( ( res ) => {
				setRuns( Array.isArray( res.runs ) ? res.runs : [] );
				setLoadError( '' );
			} )
			.catch( ( err ) => {
				setLoadError(
					err.message || __( 'Could not load tasks.', 'agent-builder' )
				);
			} )
			.finally( () => {
				if ( ! silent ) {
					setLoading( false );
				}
			} );
	}, [] );

	const loadDrawer = useCallback( ( id, silent ) => {
		if ( ! id ) {
			return;
		}
		if ( ! silent ) {
			setDrawerLoading( true );
		}
		setDrawerError( '' );
		apiFetch( { path: `${ RUNS_PATH }/${ id }` } )
			.then( ( d ) => {
				// Only render when this is still the run on screen — a slower
				// response from a previously-opened run must not clobber the
				// newer one (out-of-order fetch race).
				if ( drawerRunIdRef.current === id && d?.run?.run_id === id ) {
					setDrawer( d );
				}
			} )
			.catch( ( err ) => {
				if ( drawerRunIdRef.current === id ) {
					setDrawerError(
						err.message || __( 'Could not load this run.', 'agent-builder' )
					);
				}
			} )
			.finally( () => {
				if ( drawerRunIdRef.current === id && ! silent ) {
					setDrawerLoading( false );
				}
			} );
	}, [] );

	// Initial list load.
	useEffect( () => {
		loadRuns( false );
	}, [ loadRuns ] );

	const visibleRuns = useMemo(
		() => runs.filter( ( r ) => statusGroup( r.status ) === activeTab ),
		[ runs, activeTab ]
	);

	// Poll the list every 3s while any run in the current tab is still
	// non-terminal. The interval is torn down the moment that stops being true
	// or the component unmounts, so it never leaks.
	const hasActiveInTab = visibleRuns.some( ( r ) =>
		NON_TERMINAL.includes( r.status )
	);
	useEffect( () => {
		if ( ! hasActiveInTab ) {
			return;
		}
		const id = setInterval( () => loadRuns( true ), 3000 );
		return () => clearInterval( id );
	}, [ hasActiveInTab, loadRuns ] );

	// Load the drawer the moment a run is opened, and poll it (same 3s cadence)
	// only while that run is still non-terminal.
	useEffect( () => {
		if ( ! drawerRunId ) {
			return;
		}
		loadDrawer( drawerRunId );
	}, [ drawerRunId, loadDrawer ] );

	useEffect( () => {
		if ( ! drawerRunId ) {
			return;
		}
		const status = drawer?.run?.status;
		if ( ! status || DONE_STATUSES.includes( status ) ) {
			return;
		}
		const id = setInterval( () => loadDrawer( drawerRunId, true ), 3000 );
		return () => clearInterval( id );
	}, [ drawerRunId, drawer?.run?.status, loadDrawer ] );

	const closeDrawer = () => {
		setDrawerRunId( null );
		setDrawer( null );
		setDrawerError( '' );
		setDrawerActionError( '' );
		setDrawerBusy( '' );
	};

	const refreshAll = () => {
		loadRuns( true );
		if ( drawerRunId ) {
			loadDrawer( drawerRunId, true );
		}
	};

	const assign = ( e ) => {
		e.preventDefault();
		if ( assigning ) {
			return;
		}
		const task = assignTask.trim();
		if ( ! assignAgent || ! task ) {
			setAssignError( __( 'Choose an agent and describe the task.', 'agent-builder' ) );
			return;
		}
		setAssigning( true );
		setAssignError( '' );
		setAssignDone( '' );
		apiFetch( {
			path: RUNS_PATH,
			method: 'POST',
			data: { agent_id: assignAgent, task },
		} )
			.then( () => {
				setAssignTask( '' );
				setAssignDone( __( 'Task assigned — it will start running shortly.', 'agent-builder' ) );
				setActiveTab( 'active' );
				loadRuns( true );
			} )
			.catch( ( err ) =>
				setAssignError(
					err.message || __( 'Could not assign the task.', 'agent-builder' )
				)
			)
			.finally( () => setAssigning( false ) );
	};

	const cancelRun = () => {
		if ( ! drawerRunId ) {
			return;
		}
		setDrawerBusy( 'cancel' );
		setDrawerActionError( '' );
		apiFetch( { path: `${ RUNS_PATH }/${ drawerRunId }/cancel`, method: 'POST' } )
			.then( refreshAll )
			.catch( ( err ) =>
				setDrawerActionError(
					err.message || __( 'Could not cancel this run.', 'agent-builder' )
				)
			)
			.finally( () => setDrawerBusy( '' ) );
	};

	const retryRun = () => {
		if ( ! drawerRunId ) {
			return;
		}
		setDrawerBusy( 'retry' );
		setDrawerActionError( '' );
		apiFetch( { path: `${ RUNS_PATH }/${ drawerRunId }/retry`, method: 'POST' } )
			.then( () => {
				// A retry spawns a brand-new run — jump to Active and close.
				setActiveTab( 'active' );
				closeDrawer();
				loadRuns( true );
			} )
			.catch( ( err ) =>
				setDrawerActionError(
					err.message || __( 'Could not retry this run.', 'agent-builder' )
				)
			)
			.finally( () => setDrawerBusy( '' ) );
	};

	const decideApproval = ( action ) => {
		const awaiting = drawer?.awaiting;
		if ( ! awaiting || awaiting.id === undefined ) {
			return;
		}
		setDrawerBusy( 'approval' );
		setDrawerActionError( '' );
		apiFetch( {
			path: `agentic/v1/approvals/${ awaiting.id }`,
			method: 'POST',
			data: { action },
		} )
			.then( refreshAll )
			.catch( ( err ) =>
				setDrawerActionError(
					err.message || __( 'Could not record that decision.', 'agent-builder' )
				)
			)
			.finally( () => setDrawerBusy( '' ) );
	};

	const agentOptions = agents.map( ( a ) => ( {
		label: a.name || a.id,
		value: a.id,
	} ) );

	const run = drawer?.run || null;
	const isNonTerminal = run && NON_TERMINAL.includes( run.status );
	const awaiting = drawer?.awaiting || null;
	const isApprovalAwaiting = awaiting && awaiting.action !== undefined;

	return (
		<div className="agentic-admin">
			<AdminPage
				title={ __( 'Tasks', 'agent-builder' ) }
				description={ __(
					'Assign a task to an agent and it runs in the background — you’ll be pinged when it finishes or pauses for your OK.',
					'agent-builder'
				) }
				wide
			>
				<Panel title={ __( 'Assign a task', 'agent-builder' ) }>
					{ ! canRun ? (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'You can view tasks, but you don’t have permission to assign new ones.',
								'agent-builder'
							) }
						</Notice>
					) : ! agents.length ? (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'No agents are available to assign tasks to.',
								'agent-builder'
							) }
						</Notice>
					) : (
						<form onSubmit={ assign }>
							<SelectControl
								label={ __( 'Agent', 'agent-builder' ) }
								value={ assignAgent }
								options={ agentOptions }
								onChange={ setAssignAgent }
								help={ __(
									'The agent that will work on this task.',
									'agent-builder'
								) }
							/>
							<TextareaControl
								label={ __( 'Task', 'agent-builder' ) }
								value={ assignTask }
								onChange={ setAssignTask }
								placeholder={ __(
									'Describe what you want the agent to do…',
									'agent-builder'
								) }
								help={ __(
									'Be specific — the agent works from this description alone.',
									'agent-builder'
								) }
							/>
							{ assignError && (
								<Notice status="error" isDismissible={ false }>
									{ assignError }
								</Notice>
							) }
							{ assignDone && ! assignError && (
								<Notice status="success" isDismissible>
									{ assignDone }
								</Notice>
							) }
							<Flex justify="flex-start">
								<FlexItem>
									<Button
										variant="primary"
										type="submit"
										isBusy={ assigning }
										disabled={ assigning || ! assignAgent || ! assignTask.trim() }
									>
										{ assigning
											? __( 'Assigning…', 'agent-builder' )
											: __( 'Assign', 'agent-builder' ) }
									</Button>
								</FlexItem>
							</Flex>
						</form>
					) }
				</Panel>

				<Panel title={ __( 'Runs', 'agent-builder' ) }>
					<div className="agentic-react-tabs agentic-tasks-tabs">
						{ TABS.map( ( t ) => {
							const count = runs.filter(
								( r ) => statusGroup( r.status ) === t.id
							).length;
							return (
								<button
									key={ t.id }
									type="button"
									className={
										'agentic-react-tabs__tab' +
										( t.id === activeTab ? ' is-active' : '' )
									}
									onClick={ () => setActiveTab( t.id ) }
								>
									{ t.label }
									{ count > 0 ? ` (${ count })` : '' }
								</button>
							);
						} ) }
					</div>

					{ loadError && (
						<Notice status="error" isDismissible={ false }>
							{ loadError }
						</Notice>
					) }

					{ loading ? (
						<p>
							<Spinner /> { __( 'Loading tasks…', 'agent-builder' ) }
						</p>
					) : ! visibleRuns.length ? (
						<p className="agentic-react-muted">
							{ __( 'Nothing here.', 'agent-builder' ) }
						</p>
					) : (
						<div className="agentic-tasks-list">
							{ visibleRuns.map( ( r ) => (
								<button
									key={ r.run_id }
									type="button"
									className="agentic-tasks-row"
									onClick={ () => {
										setDrawerActionError( '' );
										setDrawerRunId( r.run_id );
									} }
								>
									<div className="agentic-tasks-row__main">
										<strong>{ agentName( r.root_agent ) }</strong>
										<span className="agentic-tasks-row__excerpt">
											{ r.task_text }
										</span>
									</div>
									<div className="agentic-tasks-row__meta">
										<StatusChip status={ r.status } />
										<span className="agentic-tasks-row__elapsed">
											{ formatElapsed( r, Date.now() ) }
										</span>
										{ isAdvanced && r.tokens_used > 0 && (
											<span className="agentic-tasks-row__tokens">
												{ r.tokens_used }{ ' ' }
												{ __( 'tokens', 'agent-builder' ) }
											</span>
										) }
									</div>
								</button>
							) ) }
						</div>
					) }
				</Panel>
			</AdminPage>

			{ drawerRunId && (
				<Modal
					title={ __( 'Run details', 'agent-builder' ) }
					onRequestClose={ closeDrawer }
					className="agentic-tasks-drawer"
				>
					{ drawerLoading ? (
						<p>
							<Spinner /> { __( 'Loading…', 'agent-builder' ) }
						</p>
					) : drawerError ? (
						<Notice status="error" isDismissible={ false }>
							{ drawerError }
						</Notice>
					) : run ? (
						<>
							<div className="agentic-tasks-drawer__head">
								<strong>{ agentName( run.root_agent ) }</strong>
								<StatusChip status={ run.status } />
							</div>
							<p className="agentic-tasks-drawer__task">{ run.task_text }</p>
							<p className="agentic-react-muted">
								{ formatTime( run.started_at ) }{ ' ' }
								{ __( '·', 'agent-builder' ) }{ ' ' }
								{ formatElapsed( run, Date.now() ) }
								{ isAdvanced && run.tokens_used > 0 && (
									<>
										{ ' · ' }
										{ run.tokens_used }{ ' ' }
										{ __( 'tokens', 'agent-builder' ) }
									</>
								) }
							</p>

							{ run.error && (
								<Notice status="error" isDismissible={ false }>
									{ run.error }
								</Notice>
							) }

							{ isApprovalAwaiting ? (
								<ApprovalCard
									approval={ awaiting }
									busy={ drawerBusy === 'approval' }
									onDecide={ decideApproval }
								/>
							) : awaiting ? (
								<ProposalCard
									proposal={ awaiting }
									sessionId={ run.session_id }
								/>
							) : null }

							{ drawerActionError && (
								<Notice status="error" isDismissible={ false }>
									{ drawerActionError }
								</Notice>
							) }

							<h4 className="agentic-tasks-drawer__section">
								{ __( 'Steps', 'agent-builder' ) }
							</h4>
							{ ! ( drawer.steps || [] ).length ? (
								<p className="agentic-react-muted">
									{ __( 'No steps recorded yet.', 'agent-builder' ) }
								</p>
							) : (
								<ul className="agentic-tasks-steps">
									{ drawer.steps.map( ( s, i ) => (
										<li key={ s.id || i }>
											<span className="agentic-tasks-steps__action">
												{ humanize( s.action ) }
											</span>
											<span className="agentic-tasks-steps__time">
												{ formatTime( s.created_at ) }
											</span>
											{ s.reasoning && (
												<span className="agentic-tasks-steps__reasoning">
													{ s.reasoning }
												</span>
											) }
										</li>
									) ) }
								</ul>
							) }

							<Flex justify="flex-end" className="agentic-tasks-drawer__actions">
								{ isNonTerminal && (
									<FlexItem>
										<Button
											variant="secondary"
											isDestructive
											isBusy={ drawerBusy === 'cancel' }
											disabled={ !! drawerBusy }
											onClick={ cancelRun }
										>
											{ __( 'Cancel', 'agent-builder' ) }
										</Button>
									</FlexItem>
								) }
								{ ! isNonTerminal && (
									<FlexItem>
										<Button
											variant="primary"
											isBusy={ drawerBusy === 'retry' }
											disabled={ !! drawerBusy }
											onClick={ retryRun }
										>
											{ __( 'Retry', 'agent-builder' ) }
										</Button>
									</FlexItem>
								) }
							</Flex>
						</>
					) : (
						<p className="agentic-react-muted">
							{ __( 'This run is no longer available.', 'agent-builder' ) }
						</p>
					) }
				</Modal>
			) }
		</div>
	);
}

/**
 * Approval decision card for a run paused on a high-risk tool action. Unlike
 * a medium-risk "proposal" (rendered via the shared ProposalCard), the raw
 * approval_queue row has no `kind`/`description` — it carries the action,
 * reasoning and risk level instead, so it gets its own compact card that POSTs
 * to the same /approvals/{id} endpoint ProposalCard uses.
 */
function ApprovalCard( { approval, busy, onDecide } ) {
	return (
		<div className="agentic-proposal-card agentic-tasks-approval">
			<div className="agentic-proposal-header">
				{ __( 'Needs Your Approval', 'agent-builder' ) }
			</div>
			<div className="agentic-tasks-approval__action">
				<code>{ humanize( approval.action ) }</code>
				{ approval.risk_level && (
					<span
						className={
							'agentic-react-risk agentic-react-risk--' +
							( approval.risk_level || 'high' )
						}
					>
						{ approval.risk_level }
					</span>
				) }
			</div>
			{ approval.reasoning && (
				<p className="agentic-tasks-approval__reasoning">
					{ approval.reasoning }
				</p>
			) }
			<div className="agentic-proposal-actions">
				<button
					type="button"
					disabled={ busy }
					className="agentic-proposal-btn agentic-proposal-approve"
					onClick={ () => onDecide( 'approve' ) }
				>
					{ __( 'Approve', 'agent-builder' ) }
				</button>
				<button
					type="button"
					disabled={ busy }
					className="agentic-proposal-btn agentic-proposal-reject"
					onClick={ () => onDecide( 'reject' ) }
				>
					{ __( 'Reject', 'agent-builder' ) }
				</button>
			</div>
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'agentic-tasks-app-root' );
	if ( el ) {
		createRoot( el ).render( <TasksApp /> );
	}
} );
