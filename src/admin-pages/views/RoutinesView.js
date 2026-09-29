/**
 * RoutinesView — the Routines core list screen.
 *
 * Lists the user-defined scheduled tasks and event listeners via
 * GET /agentic/v1/routines and offers pause/resume, test-run and delete per
 * row. The create/edit editor and History drawer are deliberate follow-ups —
 * this screen is the list slice only.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

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

function RoutinesView() {
	const [ routines, setRoutines ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	// The routine id (stringified) currently being acted on, for disabling that
	// row's buttons while its request is in flight.
	const [ busy, setBusy ] = useState( '' );
	const [ notice, setNotice ] = useState( { status: '', message: '' } );

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
												__( 'Routine', 'agent-builder' ) }
										</strong>
									</td>
									<td>
										{ 'event_listener' === routine.type ? (
											<code>{ emptyCell( trigger ) }</code>
										) : (
											emptyCell( trigger )
										) }
									</td>
									<td>{ emptyCell( skill( routine ) ) }</td>
									<td>{ emptyCell( nextRun( routine ) ) }</td>
									<td>{ emptyCell( lastStatus( routine ) ) }</td>
									<td>
										<span
											className={
												'agentic-react-led' +
												( routine.enabled ? ' is-on' : '' )
											}
											title={
												routine.enabled
													? __( 'On', 'agent-builder' )
													: __( 'Off', 'agent-builder' )
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
											onClick={ () => togglePause( routine ) }
										>
											{ routine.enabled
												? __( 'Pause', 'agent-builder' )
												: __( 'Resume', 'agent-builder' ) }
										</Button>
										<Button
											variant="link"
											isSmall
											disabled={ isBusy }
											onClick={ () => testRun( routine ) }
										>
											{ __( 'Test run', 'agent-builder' ) }
										</Button>
										<Button
											variant="link"
											isSmall
											isDestructive
											disabled={ isBusy }
											onClick={ () => doDelete( routine ) }
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
				<Button
					variant="primary"
					disabled
					title={ __(
						'The routine editor is coming soon.',
						'agent-builder'
					) }
				>
					{ __( 'Add routine', 'agent-builder' ) }
				</Button>
				<span className="agentic-react-muted">
					{ __( 'Creating routines is coming soon.', 'agent-builder' ) }
				</span>
			</div>

			{ body }
		</>
	);
}

export default RoutinesView;
