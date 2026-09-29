import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SearchControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { InfoTip } from '../../shared/components';
import { RISK_EXPLANATIONS } from '../constants';
import TabBar from './TabBar';
import RulesTab from './RulesTab';
import GrantsTab from './GrantsTab';

const APPROVAL_ACTION_HINT = __(
	'An agent tried to run this specific action and paused here first. Nothing happens until you decide — approve to let it run once, or reject to cancel it.',
	'agent-builder'
);


function ApprovalsView( { data, reload } ) {
	const rows = data.rows || [];
	// Server-computed agent+time-window groups (see group_pending_for_bulk()
	// in class-admin-pages-rest.php). Fall back to one group per row for
	// payloads from before this existed, so nothing breaks on a stale cache.
	const groups =
		data.groups && data.groups.length
			? data.groups
			: rows.map( ( r ) => ( {
					agent_id: r.subtitle,
					count: 1,
					time_ago: '',
					ids: [ r.id ],
			  } ) );
	const prefs = data.prefs || {};
	const profiles = data.comfort_profiles || [];
	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	/** @type {[{id:string,phase:string,title:string,steps:Array,message:string,ok:boolean}|null, Function]} */
	const [ progress, setProgress ] = useState( null );
	const [ emailNotify, setEmailNotify ] = useState(
		!! prefs.email_notify
	);
	const [ emailTo, setEmailTo ] = useState( prefs.email_to || '' );
	const [ riskAck, setRiskAck ] = useState( !! prefs.risk_ack );
	const [ comfort, setComfort ] = useState(
		prefs.comfort || 'careful'
	);

	const decide = ( row, action ) => {
		const id = row.id;
		const title = row.title || __( 'Action', 'agent-builder' );
		setBusy( `d-${ id }` );
		setErr( '' );
		setOk( '' );

		if ( action === 'approve' ) {
			setProgress( {
				id,
				phase: 'approving',
				title,
				ok: true,
				message: '',
				steps: [
					{
						key: 'approve',
						label: __( 'Recording your approval…', 'agent-builder' ),
						state: 'active',
					},
					{
						key: 'run',
						label: __( 'Running the approved action…', 'agent-builder' ),
						state: 'pending',
					},
					{
						key: 'done',
						label: __( 'Confirming result…', 'agent-builder' ),
						state: 'pending',
					},
				],
			} );
		} else {
			setProgress( {
				id,
				phase: 'rejecting',
				title,
				ok: true,
				message: '',
				steps: [
					{
						key: 'reject',
						label: __( 'Rejecting this request…', 'agent-builder' ),
						state: 'active',
					},
				],
			} );
		}

		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'approval_decide',
				id,
				decide: action,
			},
		} )
			.then( ( res ) => {
				// Flatten possible envelopes: {…fields}, {data:{…}}, {data:{data:{…}}}
				let payload = res || {};
				if ( payload.data && typeof payload.data === 'object' ) {
					payload = { ...payload, ...payload.data };
					if ( payload.data && typeof payload.data === 'object' ) {
						payload = { ...payload, ...payload.data };
					}
				}
				const exec = payload.execution || null;
				const baseMsg = payload.message || '';

				if ( action === 'reject' ) {
					setProgress( {
						id,
						phase: 'done',
						title,
						ok: true,
						message:
							baseMsg ||
							__(
								'Rejected — the action will not run.',
								'agent-builder'
							),
						steps: [
							{
								key: 'reject',
								label: __(
									'Request rejected',
									'agent-builder'
								),
								state: 'done',
							},
						],
					} );
					setOk(
						baseMsg ||
							__(
								'Rejected — the action will not run.',
								'agent-builder'
							)
					);
				} else {
					const ran = exec?.ran !== false;
					const success =
						exec == null
							? true
							: !! exec.success;
					const runLabel = ! ran
						? exec?.message ||
						  __(
								'Approved, but the action could not be started.',
								'agent-builder'
						  )
						: success
						? exec?.message ||
						  __(
								'Action completed successfully.',
								'agent-builder'
						  )
						: exec?.message ||
						  __(
								'Action ran but reported a problem.',
								'agent-builder'
						  );
					const detail = exec?.detail
						? String( exec.detail )
						: '';

					setProgress( {
						id,
						phase: 'done',
						title,
						ok: success && ran,
						message: [ baseMsg, runLabel, detail ]
							.filter( Boolean )
							.join( ' ' ),
						steps: [
							{
								key: 'approve',
								label: __(
									'Approval recorded',
									'agent-builder'
								),
								state: 'done',
							},
							{
								key: 'run',
								label: runLabel,
								state: ran
									? success
										? 'done'
										: 'error'
									: 'error',
							},
							{
								key: 'done',
								label: success
									? __(
											'Done — agent task finished',
											'agent-builder'
									  )
									: __(
											'Finished with errors — check detail below',
											'agent-builder'
									  ),
								state: success ? 'done' : 'error',
							},
						],
					} );
					setOk(
						success
							? baseMsg ||
									runLabel ||
									__(
										'Approved and completed.',
										'agent-builder'
									)
							: runLabel
					);
				}
				reload( { silent: true } );
			} )
			.catch( ( e ) => {
				const msg =
					e.message ||
					__( 'Could not update that item.', 'agent-builder' );
				setErr( msg );
				setProgress( {
					id,
					phase: 'done',
					title,
					ok: false,
					message: msg,
					steps: [
						{
							key: 'fail',
							label: msg,
							state: 'error',
						},
					],
				} );
			} )
			.finally( () => setBusy( '' ) );
	};

	// Approve/reject a whole group in one call (server enforces scaffold-
	// before-dependents ordering — see order_ids_for_bulk_decide()). Mirrors
	// the classic batch queue's Approve All/Reject All, which this replaces.
	const bulkDecide = ( group, groupIndex, action ) => {
		const ids = group.ids || [];
		if ( ! ids.length ) {
			return;
		}
		const confirmMsg =
			action === 'approve'
				? sprintf(
						/* translators: %d: number of pending actions */
						__(
							'Approve all %d actions in this group? They will run in order.',
							'agent-builder'
						),
						ids.length
				  )
				: sprintf(
						/* translators: %d: number of pending actions */
						__( 'Reject all %d actions in this group?', 'agent-builder' ),
						ids.length
				  );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}
		setBusy( `bulk-${ groupIndex }` );
		setErr( '' );
		setOk( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'approval_decide_bulk',
				ids,
				decide: action,
			},
		} )
			.then( ( res ) => {
				let payload = res || {};
				if ( payload.data && typeof payload.data === 'object' ) {
					payload = { ...payload, ...payload.data };
				}
				const succeeded = payload.succeeded || 0;
				const failed = payload.failed || 0;
				const stoppedEarly = !! payload.stopped_early;
				if ( failed === 0 ) {
					setOk(
						action === 'approve'
							? sprintf(
									/* translators: %d: number of approved actions */
									__( 'Approved %d actions.', 'agent-builder' ),
									succeeded
							  )
							: sprintf(
									/* translators: %d: number of rejected actions */
									__( 'Rejected %d actions.', 'agent-builder' ),
									succeeded
							  )
					);
				} else {
					const failedResult = ( payload.results || [] ).find(
						( r ) => ! r.ok
					);
					setErr(
						stoppedEarly
							? sprintf(
									/* translators: 1: number completed, 2: number requested, 3: failure reason */
									__(
										'Stopped after %1$d of %2$d — %3$s',
										'agent-builder'
									),
									succeeded,
									ids.length,
									failedResult?.message ||
										__( 'one action failed.', 'agent-builder' )
							  )
							: sprintf(
									/* translators: 1: number succeeded, 2: number failed */
									__( '%1$d succeeded, %2$d failed.', 'agent-builder' ),
									succeeded,
									failed
							  )
					);
				}
				reload( { silent: true } );
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not process that group.', 'agent-builder' )
				)
			)
			.finally( () => setBusy( '' ) );
	};

	const savePrefs = ( nextComfort ) => {
		const c = nextComfort || comfort;
		const profile = profiles.find( ( p ) => p.id === c );
		if ( profile?.needs_ack && ! riskAck ) {
			setErr(
				__(
					'Check the box to accept the increased risk before choosing “Trust more”.',
					'agent-builder'
				)
			);
			return;
		}
		setBusy( 'prefs' );
		setErr( '' );
		setOk( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'save_approval_prefs',
				email_notify: emailNotify,
				email_to: emailTo,
				comfort: c,
				risk_ack: riskAck,
			},
		} )
			.then( () => {
				setComfort( c );
				setOk( __( 'Preferences saved.', 'agent-builder' ) );
				reload( { silent: true } );
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not save preferences.', 'agent-builder' )
				)
			)
			.finally( () => setBusy( '' ) );
	};

	// Tab list for this screen: the existing "Approvals" and "Backups" entries
	// from the payload, with the "Rules" and "Grants" tabs inserted between
	// them. The payload is tab-agnostic (class-approvals-payload.php), so these
	// tabs are registered client-side rather than touching a PHP file.
	const tabs = useMemo( () => {
		const base = data.tabs || [];
		const tabUrl = ( id ) => {
			try {
				const u = new URL( window.location.href );
				u.searchParams.set( 'tab', id );
				return u.toString();
			} catch {
				// Tab still renders; only its link is empty.
				return '';
			}
		};
		return [
			...base.filter( ( t ) => t.id !== 'backups' ),
			{ id: 'rules', label: __( 'Rules', 'agent-builder' ), url: tabUrl( 'rules' ) },
			{ id: 'grants', label: __( 'Grants', 'agent-builder' ), url: tabUrl( 'grants' ) },
			...base.filter( ( t ) => t.id === 'backups' ),
		];
	}, [ data.tabs ] );

	if ( data.tab === 'rules' ) {
		return (
			<>
				<TabBar tabs={ tabs } active={ data.tab } />
				<RulesTab />
			</>
		);
	}

	if ( data.tab === 'grants' ) {
		return (
			<>
				<TabBar tabs={ tabs } active={ data.tab } />
				<GrantsTab />
			</>
		);
	}

	return (
		<>
			<TabBar tabs={ tabs } active={ data.tab } />
			{ /* data.description already renders once under the page <h1>
			 * (see AdminPage in shared/components.js) — repeating it here
			 * as a lead paragraph duplicated the same sentence twice in a
			 * row on this screen. */ }
			<p className="agentic-react-muted" style={ { marginTop: 0 } }>
				{ data.is_advanced
					? __(
							'Advanced view shows technical detail.',
							'agent-builder'
					  )
					: __(
							'Simple view for non-technical admins.',
							'agent-builder'
					  ) }
			</p>

			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			{ ok && ! progress && (
				<Notice status="success" isDismissible>
					{ ok }
				</Notice>
			) }

			{ progress && (
				<div
					className={
						'agentic-react-approval-progress' +
						( progress.phase === 'done'
							? progress.ok
								? ' is-success'
								: ' is-error'
							: ' is-running' )
					}
					role="status"
					aria-live="polite"
				>
					<div className="agentic-react-approval-progress__head">
						{ progress.phase !== 'done' && (
							<Spinner />
						) }
						<strong>
							{ progress.phase === 'done'
								? progress.ok
									? __( 'Complete', 'agent-builder' )
									: __( 'Needs attention', 'agent-builder' )
								: __( 'Working…', 'agent-builder' ) }
							{ progress.title
								? ` — ${ progress.title }`
								: '' }
						</strong>
						{ progress.phase === 'done' && (
							<button
								type="button"
								className="button-link"
								onClick={ () => setProgress( null ) }
							>
								{ __( 'Dismiss', 'agent-builder' ) }
							</button>
						) }
					</div>
					<ol className="agentic-react-approval-progress__steps">
						{ ( progress.steps || [] ).map( ( s ) => (
							<li
								key={ s.key }
								className={
									'agentic-react-approval-progress__step is-' +
									( s.state || 'pending' )
								}
							>
								<span className="agentic-react-approval-progress__dot" />
								<span>{ s.label }</span>
							</li>
						) ) }
					</ol>
					{ progress.message && progress.phase === 'done' && (
						<p className="agentic-react-approval-progress__msg">
							{ progress.message }
						</p>
					) }
				</div>
			) }

			{ /* —— Preferences —— */ }
			<div className="agentic-react-approvals-prefs">
				<h3>{ __( 'Preferences', 'agent-builder' ) }</h3>
				<p className="agentic-react-muted">
					{ __(
						'Choose how closely you want to watch agents, and whether we should email you when something is waiting.',
						'agent-builder'
					) }
				</p>

				<div className="agentic-react-profile-grid agentic-react-profile-grid--approvals">
					{ profiles.map( ( p ) => (
						<button
							key={ p.id }
							type="button"
							className={
								'agentic-react-profile-card' +
								( comfort === p.id || p.active
									? ' is-active'
									: '' )
							}
							disabled={ busy === 'prefs' }
							onClick={ () => {
								setComfort( p.id );
								if ( ! p.needs_ack ) {
									// Auto-save safer profiles immediately.
									setTimeout( () => savePrefs( p.id ), 0 );
								}
							} }
						>
							<span className="agentic-react-profile-card__icon">
								{ p.icon }
							</span>
							<span className="agentic-react-profile-card__label">
								{ p.label }
							</span>
							<span className="agentic-react-profile-card__summary">
								{ p.summary }
							</span>
							<span className="agentic-react-profile-card__detail">
								{ p.detail }
							</span>
							<span className="agentic-react-muted">
								{ p.risk_note }
							</span>
							{ ( comfort === p.id || p.active ) && (
								<span className="agentic-react-profile-card__badge">
									{ __( 'Current', 'agent-builder' ) }
								</span>
							) }
						</button>
					) ) }
				</div>

				{ comfort === 'hands_off' && (
					<label className="agentic-react-ack">
						<input
							type="checkbox"
							checked={ riskAck }
							onChange={ ( e ) =>
								setRiskAck( e.target.checked )
							}
						/>
						<span>
							{ __(
								'I understand agents may change my site with less waiting, and I accept that increased risk.',
								'agent-builder'
							) }
						</span>
					</label>
				) }

				{ comfort === 'hands_off' && (
					<p>
						<Button
							variant="primary"
							disabled={ busy === 'prefs' || ! riskAck }
							onClick={ () => savePrefs( 'hands_off' ) }
						>
							{ __(
								'Save “Trust more” preference',
								'agent-builder'
							) }
						</Button>
					</p>
				) }

				<div className="agentic-react-email-prefs">
					<label className="agentic-react-ack">
						<input
							type="checkbox"
							checked={ emailNotify }
							onChange={ ( e ) =>
								setEmailNotify( e.target.checked )
							}
						/>
						<span>
							{ __(
								'Email me when an action is waiting for approval',
								'agent-builder'
							) }
						</span>
					</label>
					{ emailNotify && (
						<label className="agentic-react-form-full">
							<span>
								{ __( 'Send alerts to', 'agent-builder' ) }
							</span>
							<input
								type="email"
								value={ emailTo }
								onChange={ ( e ) =>
									setEmailTo( e.target.value )
								}
								placeholder="you@example.com"
							/>
						</label>
					) }
					<p>
						<Button
							variant="secondary"
							disabled={ busy === 'prefs' }
							onClick={ () => savePrefs( comfort ) }
						>
							{ __( 'Save email preferences', 'agent-builder' ) }
						</Button>
					</p>
				</div>
			</div>

			{ /* —— Queue —— */ }
			<h3>
				{ __( 'Waiting for you', 'agent-builder' ) }
				{ data.pending_count
					? ` (${ data.pending_count })`
					: '' }
			</h3>

			{ ! rows.length ? (
				<div className="agentic-react-approvals-empty">
					<p>
						<strong>
							{ __( 'You’re all caught up', 'agent-builder' ) }
						</strong>
					</p>
					<p className="agentic-react-muted">
						{ __(
							'Approvals pause any action an agent rates as risky until you say yes — nothing waiting here means nothing is on hold right now.',
							'agent-builder'
						) }
					</p>
					<p className="agentic-react-muted">
						{ __(
							'When an agent needs permission for a bigger change, it will show up here',
							'agent-builder'
						) }
						{ emailNotify
							? __(
									' — and we’ll email you.',
									'agent-builder'
							  )
							: '.' }
					</p>
				</div>
			) : (
				<div className="agentic-react-approval-list">
					{ groups.map( ( group, groupIndex ) => {
						const items = ( group.ids || [] )
							.map( ( id ) => rows.find( ( r ) => r.id === id ) )
							.filter( Boolean );
						if ( ! items.length ) {
							return null;
						}
						const groupBusy = busy === `bulk-${ groupIndex }`;
						return (
							<div
								key={ groupIndex }
								className="agentic-react-approval-group"
							>
								{ items.length > 1 && (
									<div className="agentic-react-approval-group__header">
										<span className="agentic-react-muted">
											{ sprintf(
												/* translators: 1: agent name, 2: number of actions, 3: relative time */
												__(
													'%1$s · %2$d actions · %3$s ago',
													'agent-builder'
												),
												group.agent_id ||
													__(
														'Agent',
														'agent-builder'
													),
												items.length,
												group.time_ago || ''
											) }
										</span>
										<div className="agentic-react-approval-group__actions">
											<Button
												variant="primary"
												disabled={ !! busy }
												onClick={ () =>
													bulkDecide(
														group,
														groupIndex,
														'approve'
													)
												}
											>
												{ groupBusy
													? __(
															'Working…',
															'agent-builder'
													  )
													: __(
															'Approve all',
															'agent-builder'
													  ) }
											</Button>
											<Button
												variant="secondary"
												isDestructive
												disabled={ !! busy }
												onClick={ () =>
													bulkDecide(
														group,
														groupIndex,
														'reject'
													)
												}
											>
												{ __(
													'Reject all',
													'agent-builder'
												) }
											</Button>
										</div>
									</div>
								) }
								{ items.map( ( r ) => (
									<div
										key={ r.id }
										className="agentic-react-approval-card"
									>
										<div className="agentic-react-approval-card__main">
											<div className="agentic-react-approval-card__title-row">
												<strong>{ r.title }</strong>
												{ data.is_advanced && r.action && (
													<code className="agentic-react-activity-item__raw">
														{ r.action }
													</code>
												) }
												<InfoTip text={ APPROVAL_ACTION_HINT } />
												<span
													className={
														'agentic-react-risk agentic-react-risk--' +
														( r.risk_level || 'high' )
													}
												>
													{ r.risk_level || 'high' }
												</span>
												<InfoTip
													text={
														RISK_EXPLANATIONS[
															r.risk_level || 'high'
														]
													}
												/>
											</div>
											<div className="agentic-react-muted">
												{ r.subtitle
													? `${ r.subtitle } · `
													: '' }
												{ r.created_at }
											</div>
											{ r.summary && (
												<p className="agentic-react-approval-card__summary">
													{ String( r.summary ).slice(
														0,
														200
													) }
													{ String( r.summary )
														.length > 200
														? '…'
														: '' }
												</p>
											) }
										</div>
										<div className="agentic-react-approval-card__actions">
											<Button
												variant="primary"
												disabled={ !! busy }
												onClick={ () =>
													decide( r, 'approve' )
												}
											>
												{ busy === `d-${ r.id }`
													? __(
															'Working…',
															'agent-builder'
													  )
													: __(
															'Approve',
															'agent-builder'
													  ) }
											</Button>
											<Button
												variant="secondary"
												isDestructive
												disabled={ !! busy }
												onClick={ () =>
													decide( r, 'reject' )
												}
											>
												{ __( 'Reject', 'agent-builder' ) }
											</Button>
										</div>
									</div>
								) ) }
							</div>
						);
					} ) }
				</div>
			) }

			<p className="agentic-react-muted" style={ { marginTop: 16 } }>
				{ __(
					'Agents back up files and database tables automatically before changing them.',
					'agent-builder'
				) }{ ' ' }
				<a href={ data.tabs?.find( ( t ) => t.id === 'backups' )?.url }>
					{ __( 'View backups and restore', 'agent-builder' ) }
				</a>
			</p>
		</>
	);
}


export default ApprovalsView;
