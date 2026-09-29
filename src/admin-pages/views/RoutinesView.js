/**
 * RoutinesView — the Routines core list screen plus the create/edit editor and
 * the History drawer (both deferred from the initial list-only slice).
 *
 * Lists the user-defined scheduled tasks and event listeners via
 * GET /agentic/v1/routines and offers pause/resume, test-run, edit, history and
 * delete per row. The editor creates/edits through POST/PUT /agentic/v1/routines;
 * the History drawer reads GET /agentic/v1/routines/{id}/history.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

// Recurrence presets the classic Deployment → Scheduled Tasks page offers
// (Agent_Lifecycle::ALLOWED_USER_SCHEDULES), with the standard WP display names.
const SCHEDULE_PRESETS = [
	{ value: 'hourly', label: __( 'Once Hourly', 'agent-builder' ) },
	{ value: 'twicedaily', label: __( 'Twice Daily', 'agent-builder' ) },
	{ value: 'daily', label: __( 'Once Daily', 'agent-builder' ) },
	{ value: 'weekly', label: __( 'Once Weekly', 'agent-builder' ) },
];

// The event-hook list the classic Deployment → Event Listeners page offers,
// grouped the same way. `_custom` opens a free-text hook input.
const EVENT_HOOK_GROUPS = [
	{
		label: __( 'Content', 'agent-builder' ),
		hooks: [
			{
				value: 'save_post',
				label:
					'save_post — ' +
					__( 'post saved or updated', 'agent-builder' ),
			},
			{
				value: 'publish_post',
				label:
					'publish_post — ' + __( 'post published', 'agent-builder' ),
			},
			{
				value: 'draft_to_publish',
				label:
					'draft_to_publish — ' +
					__( 'draft published', 'agent-builder' ),
			},
			{
				value: 'future_to_publish',
				label:
					'future_to_publish — ' +
					__( 'scheduled post goes live', 'agent-builder' ),
			},
			{
				value: 'post_updated',
				label:
					'post_updated — ' + __( 'post updated', 'agent-builder' ),
			},
			{
				value: 'before_delete_post',
				label:
					'before_delete_post — ' +
					__( 'post permanently deleted', 'agent-builder' ),
			},
			{
				value: 'wp_trash_post',
				label:
					'wp_trash_post — ' +
					__( 'post moved to trash', 'agent-builder' ),
			},
			{
				value: 'untrash_post',
				label:
					'untrash_post — ' +
					__( 'post restored from trash', 'agent-builder' ),
			},
		],
	},
	{
		label: __( 'Comments', 'agent-builder' ),
		hooks: [
			{
				value: 'wp_insert_comment',
				label:
					'wp_insert_comment — ' +
					__( 'new comment inserted', 'agent-builder' ),
			},
			{
				value: 'comment_post',
				label:
					'comment_post — ' +
					__( 'comment submitted', 'agent-builder' ),
			},
			{
				value: 'edit_comment',
				label:
					'edit_comment — ' + __( 'comment edited', 'agent-builder' ),
			},
			{
				value: 'delete_comment',
				label:
					'delete_comment — ' +
					__( 'comment deleted', 'agent-builder' ),
			},
			{
				value: 'spam_comment',
				label:
					'spam_comment — ' +
					__( 'comment marked spam', 'agent-builder' ),
			},
		],
	},
	{
		label: __( 'Users', 'agent-builder' ),
		hooks: [
			{
				value: 'user_register',
				label:
					'user_register — ' +
					__( 'new user registered', 'agent-builder' ),
			},
			{
				value: 'profile_update',
				label:
					'profile_update — ' +
					__( 'user profile updated', 'agent-builder' ),
			},
			{
				value: 'wp_login',
				label: 'wp_login — ' + __( 'user logged in', 'agent-builder' ),
			},
			{
				value: 'wp_logout',
				label:
					'wp_logout — ' + __( 'user logged out', 'agent-builder' ),
			},
			{
				value: 'delete_user',
				label: 'delete_user — ' + __( 'user deleted', 'agent-builder' ),
			},
			{
				value: 'password_reset',
				label:
					'password_reset — ' +
					__( 'password reset', 'agent-builder' ),
			},
		],
	},
	{
		label: __( 'Media', 'agent-builder' ),
		hooks: [
			{
				value: 'add_attachment',
				label:
					'add_attachment — ' +
					__( 'file uploaded', 'agent-builder' ),
			},
			{
				value: 'edit_attachment',
				label:
					'edit_attachment — ' +
					__( 'attachment updated', 'agent-builder' ),
			},
			{
				value: 'delete_attachment',
				label:
					'delete_attachment — ' +
					__( 'attachment deleted', 'agent-builder' ),
			},
		],
	},
	{
		label: __( 'WooCommerce', 'agent-builder' ),
		hooks: [
			{
				value: 'woocommerce_new_order',
				label:
					'woocommerce_new_order — ' +
					__( 'new order created', 'agent-builder' ),
			},
			{
				value: 'woocommerce_order_status_changed',
				label:
					'woocommerce_order_status_changed — ' +
					__( 'order status changed', 'agent-builder' ),
			},
			{
				value: 'woocommerce_payment_complete',
				label:
					'woocommerce_payment_complete — ' +
					__( 'payment completed', 'agent-builder' ),
			},
			{
				value: 'woocommerce_low_stock',
				label:
					'woocommerce_low_stock — ' +
					__( 'product low stock', 'agent-builder' ),
			},
			{
				value: 'woocommerce_no_stock',
				label:
					'woocommerce_no_stock — ' +
					__( 'product out of stock', 'agent-builder' ),
			},
		],
	},
	{
		label: __( 'Plugins & Themes', 'agent-builder' ),
		hooks: [
			{
				value: 'activated_plugin',
				label:
					'activated_plugin — ' +
					__( 'plugin activated', 'agent-builder' ),
			},
			{
				value: 'deactivated_plugin',
				label:
					'deactivated_plugin — ' +
					__( 'plugin deactivated', 'agent-builder' ),
			},
			{
				value: 'upgrader_process_complete',
				label:
					'upgrader_process_complete — ' +
					__( 'plugin/theme updated', 'agent-builder' ),
			},
			{
				value: 'switch_theme',
				label:
					'switch_theme — ' + __( 'theme switched', 'agent-builder' ),
			},
		],
	},
];

// Flat list of known hook values, for deciding whether an edited routine's
// stored hook is a known preset or a custom one.
const KNOWN_HOOKS = EVENT_HOOK_GROUPS.reduce(
	( acc, group ) => acc.concat( group.hooks.map( ( h ) => h.value ) ),
	[]
);

// "scheduled_task" / "event_listener" → human-readable trigger text.
function triggerLabel( routine ) {
	const config = routine.config || {};
	if ( 'event_listener' === routine.type ) {
		return config.hook || '';
	}
	return config.schedule || '';
}

function humanize( text ) {
	return String( text || '' ).replace( /_/g, ' ' );
}

// MySQL 'Y-m-d H:i:s' timestamps are stored UTC; parse as UTC and render in the
// browser's locale.
function formatRunTime( ts ) {
	if ( ! ts ) {
		return '';
	}
	const d = new Date( String( ts ).replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( d.getTime() ) ? String( ts ) : d.toLocaleString();
}

// Deep-link a run id into the Tasks screen's drawer.
function tasksRunUrl( runId ) {
	return `admin.php?page=agentic-tasks&run=${ encodeURIComponent( runId ) }`;
}

// Create/edit drawer for a routine. `routine` is null for a new routine, or the
// row being edited. Modeled on AgentsView's AgentProfileDrawer: save → onSaved,
// close via onClose.
function RoutineEditorDrawer( { routine, agents, onClose, onSaved } ) {
	const isEdit = !! routine;
	const config = ( routine && routine.config ) || {};

	const [ kind, setKind ] = useState(
		routine ? routine.type : 'scheduled_task'
	);
	const [ agentSlug, setAgentSlug ] = useState(
		routine ? routine.agent_slug || '' : ''
	);
	const [ name, setName ] = useState( routine ? routine.label || '' : '' );
	const [ prompt, setPrompt ] = useState( config.prompt || '' );
	const [ schedule, setSchedule ] = useState( config.schedule || 'daily' );
	const [ description, setDescription ] = useState(
		config.description || ''
	);
	// Hook: a known preset value, or '_custom' when the stored hook isn't preset.
	const storedHook = config.hook || '';
	const [ hook, setHook ] = useState( () => {
		if ( ! storedHook ) {
			return '';
		}
		return KNOWN_HOOKS.includes( storedHook ) ? storedHook : '_custom';
	} );
	const [ customHook, setCustomHook ] = useState(
		KNOWN_HOOKS.includes( storedHook ) ? '' : storedHook
	);
	const [ priority, setPriority ] = useState(
		config.priority !== undefined ? String( config.priority ) : '10'
	);
	const [ skillSlug, setSkillSlug ] = useState( config.skill_slug || '' );
	const [ enabled, setEnabled ] = useState(
		routine ? !! routine.enabled : true
	);
	const [ saving, setSaving ] = useState( false );
	const [ err, setErr ] = useState( '' );

	const agentOptions = agents.map( ( a ) => ( {
		label: a.icon ? `${ a.icon } ${ a.name || a.id }` : a.name || a.id,
		value: a.id,
	} ) );

	let effectiveHook = '';
	if ( 'event_listener' === kind ) {
		effectiveHook = hook === '_custom' ? customHook.trim() : hook;
	}

	const save = () => {
		if ( saving ) {
			return;
		}
		if ( ! agentSlug ) {
			setErr( __( 'Choose an agent.', 'agent-builder' ) );
			return;
		}
		if ( ! prompt.trim() ) {
			setErr( __( 'Prompt is required.', 'agent-builder' ) );
			return;
		}
		if ( 'event_listener' === kind && ! effectiveHook ) {
			setErr( __( 'Choose or enter a hook name.', 'agent-builder' ) );
			return;
		}

		const body = {
			kind,
			agent_slug: agentSlug,
			name: name.trim(),
			prompt: prompt.trim(),
			skill_slug: skillSlug.trim(),
			enabled,
		};
		if ( 'scheduled_task' === kind ) {
			body.schedule = schedule;
			body.description = description.trim();
		} else {
			body.hook = effectiveHook;
			body.priority = parseInt( priority, 10 ) || 10;
		}

		setSaving( true );
		setErr( '' );
		apiFetch( {
			path: isEdit
				? `agentic/v1/routines/${ routine.id }`
				: 'agentic/v1/routines',
			method: isEdit ? 'PUT' : 'POST',
			data: body,
		} )
			.then( () => {
				setSaving( false );
				onSaved();
			} )
			.catch( ( e ) => {
				setSaving( false );
				setErr(
					e.message ||
						__( 'Could not save the routine.', 'agent-builder' )
				);
			} );
	};

	const title = isEdit
		? sprintf(
				/* translators: %s: routine label */
				__( 'Edit routine: %s', 'agent-builder' ),
				routine.label || __( 'Routine', 'agent-builder' )
		  )
		: __( 'Add routine', 'agent-builder' );

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="agentic-routines-drawer"
		>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }

			{ ! agents.length && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No agents are available to run a routine.',
						'agent-builder'
					) }
				</Notice>
			) }

			<div className="agentic-routines-kind-toggle">
				<Button
					variant={
						'scheduled_task' === kind ? 'primary' : 'secondary'
					}
					disabled={ isEdit }
					onClick={ () => setKind( 'scheduled_task' ) }
				>
					{ __( 'Scheduled task', 'agent-builder' ) }
				</Button>
				<Button
					variant={
						'event_listener' === kind ? 'primary' : 'secondary'
					}
					disabled={ isEdit }
					onClick={ () => setKind( 'event_listener' ) }
				>
					{ __( 'Event listener', 'agent-builder' ) }
				</Button>
			</div>
			{ isEdit && (
				<p className="agentic-react-muted">
					{ __(
						'The routine type cannot be changed after creation.',
						'agent-builder'
					) }
				</p>
			) }

			<SelectControl
				label={ __( 'Agent', 'agent-builder' ) }
				value={ agentSlug }
				options={ agentOptions }
				onChange={ setAgentSlug }
				disabled={ ! agents.length }
			/>

			<TextControl
				label={ __( 'Name', 'agent-builder' ) }
				value={ name }
				onChange={ setName }
				help={ __(
					'Optional — auto-generated if blank.',
					'agent-builder'
				) }
			/>

			<TextareaControl
				label={ __( 'Prompt', 'agent-builder' ) }
				value={ prompt }
				onChange={ setPrompt }
				placeholder={
					'scheduled_task' === kind
						? __(
								'Describe what the agent should do on each run…',
								'agent-builder'
						  )
						: __(
								'Describe what the agent should do when this event fires…',
								'agent-builder'
						  )
				}
			/>

			{ 'scheduled_task' === kind ? (
				<>
					<SelectControl
						label={ __( 'Schedule', 'agent-builder' ) }
						value={ schedule }
						options={ SCHEDULE_PRESETS }
						onChange={ setSchedule }
					/>
					<TextControl
						label={ __( 'Description', 'agent-builder' ) }
						value={ description }
						onChange={ setDescription }
						help={ __( 'Optional short note.', 'agent-builder' ) }
					/>
				</>
			) : (
				<>
					<label
						className="agentic-routines-label"
						htmlFor="agentic-routines-hook"
					>
						{ __( 'WordPress event', 'agent-builder' ) }
					</label>
					<select
						id="agentic-routines-hook"
						className="components-select-control__input"
						value={ hook }
						onChange={ ( e ) => setHook( e.target.value ) }
					>
						<option value="">
							{ __( '— choose an event —', 'agent-builder' ) }
						</option>
						{ EVENT_HOOK_GROUPS.map( ( group ) => (
							<optgroup key={ group.label } label={ group.label }>
								{ group.hooks.map( ( h ) => (
									<option key={ h.value } value={ h.value }>
										{ h.label }
									</option>
								) ) }
							</optgroup>
						) ) }
						<optgroup label={ __( 'Custom', 'agent-builder' ) }>
							<option value="_custom">
								{ __( 'Custom hook name…', 'agent-builder' ) }
							</option>
						</optgroup>
					</select>
					{ '_custom' === hook && (
						<TextControl
							label={ __( 'Custom hook name', 'agent-builder' ) }
							value={ customHook }
							onChange={ setCustomHook }
							placeholder="my_plugin_action"
							help={ __(
								'Enter any WordPress action hook name.',
								'agent-builder'
							) }
						/>
					) }
					<TextControl
						label={ __( 'Priority', 'agent-builder' ) }
						type="number"
						value={ priority }
						onChange={ setPriority }
						help={ __(
							'Lower numbers run first. Default is 10.',
							'agent-builder'
						) }
					/>
				</>
			) }

			<TextControl
				label={ __( 'Skill slug', 'agent-builder' ) }
				value={ skillSlug }
				onChange={ setSkillSlug }
				help={ __(
					'Optional. The slug of a skill to attach to this routine.',
					'agent-builder'
				) }
			/>

			<ToggleControl
				label={ __( 'Enabled', 'agent-builder' ) }
				checked={ enabled }
				onChange={ setEnabled }
			/>

			<div className="agentic-routines-drawer__actions">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'agent-builder' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving || ! agents.length }
					onClick={ save }
				>
					{ isEdit
						? __( 'Save routine', 'agent-builder' )
						: __( 'Add routine', 'agent-builder' ) }
				</Button>
			</div>
		</Modal>
	);
}

// History drawer — reads GET /agentic/v1/routines/{id}/history on open and lists
// the runs newest-first (the route already returns them that way).
function RoutineHistoryDrawer( { routine, onClose } ) {
	const [ history, setHistory ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: `agentic/v1/routines/${ routine.id }/history` } )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setHistory( res.history || [] );
					setLoading( false );
				}
			} )
			.catch( ( e ) => {
				if ( ! cancelled ) {
					setError(
						e.message ||
							__( 'Could not load history.', 'agent-builder' )
					);
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ routine.id ] );

	let body;
	if ( loading ) {
		body = (
			<p>
				<Spinner /> { __( 'Loading…', 'agent-builder' ) }
			</p>
		);
	} else if ( error ) {
		body = (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	} else if ( ! history.length ) {
		body = (
			<p className="agentic-react-muted">
				{ __(
					'No runs yet. Use “Test run” to run this routine once now.',
					'agent-builder'
				) }
			</p>
		);
	} else {
		body = (
			<ul className="agentic-routines-history">
				{ history.map( ( run ) => (
					<li
						key={ run.run_id }
						className="agentic-routines-history__item"
					>
						<div className="agentic-routines-history__head">
							<span
								className={ `agentic-react-status agentic-react-status--${ run.status }` }
							>
								{ humanize( run.status ) }
							</span>
							<span className="agentic-react-muted">
								{ formatRunTime( run.started_at ) }
							</span>
						</div>
						<div className="agentic-routines-history__meta">
							<span>
								{ sprintf(
									/* translators: %d: token count */
									__( '%d tokens', 'agent-builder' ),
									run.tokens_used || 0
								) }
							</span>
							{ run.iterations ? (
								<span>
									{ sprintf(
										/* translators: %d: iteration count */
										__( '%d steps', 'agent-builder' ),
										run.iterations
									) }
								</span>
							) : null }
							<a href={ tasksRunUrl( run.run_id ) }>
								{ __( 'View in Tasks', 'agent-builder' ) }
							</a>
						</div>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: routine label */
				__( 'History: %s', 'agent-builder' ),
				routine.label || __( 'Routine', 'agent-builder' )
			) }
			onRequestClose={ onClose }
			className="agentic-routines-drawer"
		>
			{ body }
		</Modal>
	);
}

function RoutinesView( { data } ) {
	const agents = ( data && data.agents ) || [];
	// Single site-wide signal from the page payload — WP-Cron hasn't ticked
	// recently, so scheduled routines won't fire until it does.
	const cronStale = !!( data && data.cron_stale );
	const [ routines, setRoutines ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	// The routine id (stringified) currently being acted on, for disabling that
	// row's buttons while its request is in flight.
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( { status: '', message: '' } );
	// Open drawers: creating (bool) for a new routine, editing (routine|null) and
	// historyFor (routine|null) for the row being viewed.
	const [ creating, setCreating ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const [ historyFor, setHistoryFor ] = useState( null );

	const fetchRoutines = useCallback( ( silent = false ) => {
		if ( ! silent ) {
			setLoading( true );
		}
		setError( '' );
		apiFetch( { path: 'agentic/v1/routines' } )
			.then( ( res ) => {
				setRoutines( res.routines || [] );
				setLoading( false );
			} )
			.catch( ( e ) => {
				setError(
					e.message ||
						__( 'Could not load routines.', 'agent-builder' )
				);
				setLoading( false );
			} );
	}, [] );

	useEffect( () => {
		fetchRoutines();
	}, [ fetchRoutines ] );

	// Run a POST action against a routine, then silently refetch the list.
	const run = ( routine, verb, { success } = {} ) => {
		setBusy( String( routine.id ) );
		setNotice( { status: '', message: '' } );
		apiFetch( {
			path: `agentic/v1/routines/${ routine.id }/${ verb }`,
			method: 'POST',
		} )
			.then( ( res ) => {
				if ( success ) {
					success( res );
				}
				fetchRoutines( true );
			} )
			.catch( ( e ) => {
				setNotice( {
					status: 'error',
					message:
						e.message ||
						__( 'Something went wrong.', 'agent-builder' ),
				} );
			} )
			.finally( () => setBusy( '' ) );
	};

	const togglePause = ( routine ) => {
		const verb = routine.enabled ? 'pause' : 'resume';
		run( routine, verb, {
			success: () =>
				setNotice( {
					status: 'success',
					message: routine.enabled
						? __( 'Routine paused.', 'agent-builder' )
						: __( 'Routine resumed.', 'agent-builder' ),
				} ),
		} );
	};

	const testRun = ( routine ) => {
		run( routine, 'test-run', {
			success: ( res ) =>
				setNotice( {
					status: 'success',
					message:
						res && res.run_id
							? sprintf(
									/* translators: %s: run id */
									__(
										'Test run started (run %s).',
										'agent-builder'
									),
									res.run_id
							  )
							: __( 'Test run started.', 'agent-builder' ),
				} ),
		} );
	};

	const doDelete = ( routine ) => {
		const name = routine.label || routine.id;
		if (
			! window.confirm(
				sprintf(
					/* translators: %s: routine label */
					__(
						'Delete “%s”? This cannot be undone.',
						'agent-builder'
					),
					name
				)
			)
		) {
			return;
		}
		setBusy( String( routine.id ) );
		setNotice( { status: '', message: '' } );
		apiFetch( {
			path: `agentic/v1/routines/${ routine.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %s: routine label */
						__( 'Deleted “%s”.', 'agent-builder' ),
						name
					),
				} );
				fetchRoutines( true );
			} )
			.catch( ( e ) => {
				setNotice( {
					status: 'error',
					message:
						e.message || __( 'Delete failed.', 'agent-builder' ),
				} );
			} )
			.finally( () => setBusy( '' ) );
	};

	const clearNotice = () => setNotice( { status: '', message: '' } );

	// Close the editor and refresh the list after a successful create/edit,
	// following the same "ignore the response body, just refetch" pattern the
	// other views use.
	const onSaved = ( label ) => {
		setCreating( false );
		setEditing( null );
		setNotice( { status: 'success', message: label } );
		fetchRoutines( true );
	};

	const skill = ( routine ) => {
		const slug = ( routine.config || {} ).skill_slug;
		return slug ? <code>{ slug }</code> : null;
	};

	const nextRun = ( routine ) => {
		// next_run is already formatted in the site's local date/time by the
		// backend (Routines::next_run()); no client-side formatting needed.
		return routine.next_run || '';
	};

	const lastStatus = ( routine ) => {
		const status = ( routine.config || {} ).last_status;
		if ( ! status ) {
			return null;
		}
		return (
			<span
				className={ `agentic-react-status agentic-react-status--${ status }` }
			>
				{ humanize( status ) }
			</span>
		);
	};

	const emptyCell = ( value ) =>
		value || <span className="agentic-react-muted">—</span>;

	let body;
	if ( loading ) {
		body = (
			<p>
				<Spinner /> { __( 'Loading…', 'agent-builder' ) }
			</p>
		);
	} else if ( error ) {
		body = (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	} else if ( ! routines.length ) {
		body = (
			<p className="agentic-react-muted">
				{ __(
					'No routines yet. Routines run your agents on a schedule or in response to site events.',
					'agent-builder'
				) }
			</p>
		);
	} else {
		body = (
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table agentic-routines-table">
					<thead>
						<tr>
							<th>{ __( 'Agent', 'agent-builder' ) }</th>
							<th>{ __( 'Routine', 'agent-builder' ) }</th>
							<th>{ __( 'Trigger', 'agent-builder' ) }</th>
							<th>{ __( 'Skill', 'agent-builder' ) }</th>
							<th>{ __( 'Next run', 'agent-builder' ) }</th>
							<th>{ __( 'Last status', 'agent-builder' ) }</th>
							<th>{ __( 'On / Off', 'agent-builder' ) }</th>
							<th>{ __( 'Actions', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ routines.map( ( routine ) => {
							const isBusy = busy === String( routine.id );
							const trigger = triggerLabel( routine );
							return (
								<tr key={ routine.id }>
									<td>
										<code>{ routine.agent_slug }</code>
									</td>
									<td>
										<strong>
											{ routine.label ||
												__(
													'Routine',
													'agent-builder'
												) }
										</strong>
									</td>
									<td>
										{ 'event_listener' === routine.type ? (
											<code>
												{ emptyCell( trigger ) }
											</code>
										) : (
											emptyCell( trigger )
										) }
									</td>
									<td>{ emptyCell( skill( routine ) ) }</td>
									<td>{ emptyCell( nextRun( routine ) ) }</td>
									<td>
										{ emptyCell( lastStatus( routine ) ) }
									</td>
									<td>
										<span
											className={
												'agentic-react-led' +
												( routine.enabled
													? ' is-on'
													: '' )
											}
											title={
												routine.enabled
													? __(
															'On',
															'agent-builder'
													  )
													: __(
															'Off',
															'agent-builder'
													  )
											}
										/>{ ' ' }
										{ routine.enabled
											? __( 'On', 'agent-builder' )
											: __( 'Off', 'agent-builder' ) }
									</td>
									<td>
										<Button
											variant="link"
											isSmall
											disabled={ isBusy }
											onClick={ () =>
												setEditing( routine )
											}
										>
											{ __( 'Edit', 'agent-builder' ) }
										</Button>
										<Button
											variant="link"
											isSmall
											disabled={ isBusy }
											onClick={ () =>
												togglePause( routine )
											}
										>
											{ routine.enabled
												? __( 'Pause', 'agent-builder' )
												: __(
														'Resume',
														'agent-builder'
												  ) }
										</Button>
										<Button
											variant="link"
											isSmall
											disabled={ isBusy }
											onClick={ () => testRun( routine ) }
										>
											{ __(
												'Test run',
												'agent-builder'
											) }
										</Button>
										<Button
											variant="link"
											isSmall
											disabled={ isBusy }
											onClick={ () =>
												setHistoryFor( routine )
											}
										>
											{ __( 'History', 'agent-builder' ) }
										</Button>
										<Button
											variant="link"
											isSmall
											isDestructive
											disabled={ isBusy }
											onClick={ () =>
												doDelete( routine )
											}
										>
											{ __( 'Delete', 'agent-builder' ) }
										</Button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
		);
	}

	return (
		<>
			{ cronStale && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Scheduled background tasks are overdue (no recent cron tick). Routines on a schedule will not run until WP-Cron ticks again.',
						'agent-builder'
					) }{ ' ' }
					<a href="tools.php?page=health-check">
						{ __( 'Check Site Health', 'agent-builder' ) }
					</a>
				</Notice>
			) }

			{ notice.message && (
				<Notice
					status={ notice.status }
					isDismissible
					onRemove={ clearNotice }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="agentic-routines-toolbar">
				<Button variant="primary" onClick={ () => setCreating( true ) }>
					{ __( 'Add routine', 'agent-builder' ) }
				</Button>
			</div>

			{ body }

			{ creating && (
				<RoutineEditorDrawer
					routine={ null }
					agents={ agents }
					onClose={ () => setCreating( false ) }
					onSaved={ () =>
						onSaved( __( 'Routine created.', 'agent-builder' ) )
					}
				/>
			) }

			{ editing && (
				<RoutineEditorDrawer
					routine={ editing }
					agents={ agents }
					onClose={ () => setEditing( null ) }
					onSaved={ () =>
						onSaved( __( 'Routine saved.', 'agent-builder' ) )
					}
				/>
			) }

			{ historyFor && (
				<RoutineHistoryDrawer
					routine={ historyFor }
					onClose={ () => setHistoryFor( null ) }
				/>
			) }
		</>
	);
}

export default RoutinesView;
