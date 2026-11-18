/**
 * Full Siteloom dashboard — all cards as React + @wordpress/components.
 */
import {
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import {
	Spinner,
	Notice,
	Button,
	CheckboxControl,
} from '@wordpress/components';
import { InfoTip } from '../shared/components';

const DASH_PATH = 'agentic/v1/dashboard';
const STATS_PATH = 'agentic/v1/dashboard-stats?period=week';
const BRIEF_PATH = 'agentic/v1/site-brief';
const REFRESH_MS = 30000;

const BRIEF_CHECKER_LABELS = {
	plugin_updates: __( 'Plugin updates', 'siteloom' ),
	site_health: __( 'Health', 'siteloom' ),
	comments_queue: __( 'Comments', 'siteloom' ),
	oversized_media: __( 'Media', 'siteloom' ),
	forms: __( 'Forms', 'siteloom' ),
	wc_unpaid: __( 'Store', 'siteloom' ),
};

const BRIEF_CATEGORY_LABELS = {
	security: __( 'Security', 'siteloom' ),
	performance: __( 'Performance', 'siteloom' ),
	media: __( 'Media', 'siteloom' ),
	forms: __( 'Forms', 'siteloom' ),
	maintenance: __( 'Maintenance', 'siteloom' ),
	woocommerce: __( 'WooCommerce', 'siteloom' ),
	general: __( 'General', 'siteloom' ),
};

function formatBriefTime( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return iso;
	}
	return d.toLocaleString();
}

/**
 * Per-test configuration: a checkbox per checker, grouped by category.
 * Collapsed by default; loads the checker list on first expand.
 *
 * @param {Object}  root0        Props.
 * @param {boolean} root0.canRun Whether the current user may change settings.
 */
function SiteBriefSettings( { canRun } ) {
	const [ open, setOpen ] = useState( false );
	const [ checkers, setCheckers ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ settingsError, setSettingsError ] = useState( '' );

	useEffect( () => {
		if ( ! open || checkers ) {
			return;
		}
		apiFetch( { path: BRIEF_PATH + '/checkers' } )
			.then( ( d ) => setCheckers( d.checkers || [] ) )
			.catch( ( e ) => {
				setSettingsError(
					e.message || __( 'Could not load checks.', 'siteloom' )
				);
			} );
	}, [ open, checkers ] );

	if ( ! canRun ) {
		return null;
	}

	const toggle = ( id, value ) => {
		const next = checkers.map( ( c ) =>
			c.id === id ? { ...c, enabled: value } : c
		);
		setCheckers( next );
		setSaving( true );
		const enabled = {};
		next.forEach( ( c ) => {
			enabled[ c.id ] = c.enabled;
		} );
		apiFetch( {
			path: BRIEF_PATH + '/checkers',
			method: 'POST',
			data: { enabled },
		} )
			.then( ( d ) => setCheckers( d.checkers || next ) )
			.catch( ( e ) => {
				setSettingsError(
					e.message ||
						__( 'Could not save that change.', 'siteloom' )
				);
			} )
			.finally( () => setSaving( false ) );
	};

	const grouped = {};
	( checkers || [] ).forEach( ( c ) => {
		const cat = c.category || 'general';
		grouped[ cat ] = grouped[ cat ] || [];
		grouped[ cat ].push( c );
	} );

	return (
		<div className="agentic-site-brief-settings">
			<Button variant="link" onClick={ () => setOpen( ! open ) }>
				{ open
					? __( 'Hide checks', 'siteloom' )
					: __( 'Configure checks', 'siteloom' ) }
			</Button>
			{ open && (
				<div className="agentic-site-brief-settings-panel">
					{ settingsError && (
						<Notice status="error" isDismissible={ false }>
							{ settingsError }
						</Notice>
					) }
					{ ! checkers && <Spinner /> }
					{ Object.keys( grouped )
						.sort()
						.map( ( cat ) => (
							<div
								key={ cat }
								className="agentic-site-brief-settings-group"
							>
								<h4>{ BRIEF_CATEGORY_LABELS[ cat ] || cat }</h4>
								{ grouped[ cat ].map( ( c ) => (
									<CheckboxControl
										key={ c.id }
										label={ c.label }
										checked={ !! c.enabled }
										disabled={ saving || ! c.applicable }
										help={
											c.applicable
												? undefined
												: __(
														'Not applicable on this site.',
														'siteloom'
												  )
										}
										onChange={ ( value ) =>
											toggle( c.id, value )
										}
									/>
								) ) }
							</div>
						) ) }
				</div>
			) }
		</div>
	);
}

function SiteBriefPanel() {
	const [ brief, setBrief ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ hidden, setHidden ] = useState( [] );
	const [ showAll, setShowAll ] = useState( false );
	const [ busyId, setBusyId ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const pollRef = useRef( null );

	const stopPolling = useCallback( () => {
		if ( pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}
	}, [] );

	useEffect( () => stopPolling, [ stopPolling ] );

	const loadBrief = useCallback( () => {
		return apiFetch( { path: BRIEF_PATH } )
			.then( ( d ) => {
				setBrief( d );
				setError( '' );
				return d;
			} )
			.catch( ( e ) => {
				setError(
					e.message ||
						__( 'Could not load Site Brief.', 'siteloom' )
				);
			} );
	}, [] );

	useEffect( () => {
		loadBrief();
	}, [ loadBrief ] );

	const pollProgress = useCallback( () => {
		apiFetch( { path: BRIEF_PATH + '/progress' } )
			.then( ( p ) => setProgress( p ) )
			.catch( () => {
				// Polling failure just means the checklist falls back to the
				// plain spinner below — the scan itself is unaffected.
			} );
	}, [] );

	if ( ! brief || ! brief.has_usable_provider ) {
		return null;
	}

	const runScan = () => {
		setRunning( true );
		setError( '' );
		setProgress( null );
		pollProgress();
		pollRef.current = setInterval( pollProgress, 1000 );
		apiFetch( { path: BRIEF_PATH + '/run', method: 'POST' } )
			.then( ( d ) => {
				setBrief( d );
				setHidden( [] );
				setShowAll( false );
			} )
			.catch( ( e ) => {
				setError(
					e.message || __( 'Scan failed.', 'siteloom' )
				);
			} )
			.finally( () => {
				stopPolling();
				setRunning( false );
			} );
	};

	const act = ( card, action ) => {
		if ( 'not_now' === action ) {
			setHidden( ( prev ) => prev.concat( card.id ) );
			return;
		}
		setBusyId( card.id );
		apiFetch( {
			path: BRIEF_PATH + '/cards/' + encodeURIComponent( card.id ) + '/' + action,
			method: 'POST',
		} )
			.then( ( d ) => {
				if ( d.redirect_url ) {
					window.location.href = d.redirect_url;
					return;
				}
				if ( d.approvals_url && d.approval_id ) {
					window.location.href = d.approvals_url;
					return;
				}
				setBrief( d );
			} )
			.catch( ( e ) => {
				setError(
					e.message ||
						__( 'Could not update that card.', 'siteloom' )
				);
			} )
			.finally( () => setBusyId( '' ) );
	};

	const isRunning = running || brief.status === 'running';
	const cards = ( brief.cards || [] ).filter(
		( c ) => ! hidden.includes( c.id )
	);
	const visible = showAll ? cards : cards.slice( 0, 10 );
	const checkers = brief.checkers || Object.keys( BRIEF_CHECKER_LABELS );
	const canRun = !! brief.caps?.run;
	const scanned = !! brief.last_run;

	return (
		<div className="agentic-card agentic-site-brief">
			<div className="agentic-card-header agentic-flex-between">
				<h2>{ __( 'Site Brief', 'siteloom' ) }</h2>
			</div>
			<p className="agentic-site-brief-lede" id="agentic-site-brief-lede">
				{ __(
					'A read-only look at this site. Assign an agent to investigate a finding together — nothing changes until you agree.',
					'siteloom'
				) }
			</p>

			<SiteBriefSettings canRun={ canRun } />

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ isRunning && (
				<div className="agentic-site-brief-progress" role="status">
					{ progress &&
					progress.steps &&
					progress.steps.length > 0 ? (
						<ul className="agentic-site-brief-checklist">
							{ progress.steps.map( ( step ) => {
								const isDone = (
									progress.done || []
								).includes( step.id );
								const isCurrent =
									progress.current === step.id;
								let marker = '';
								if ( isDone ) {
									marker = '✓ ';
								} else if ( isCurrent ) {
									marker = '… ';
								}
								return (
									<li
										key={ step.id }
										className={
											'agentic-site-brief-step' +
											( isDone ? ' is-done' : '' ) +
											( isCurrent ? ' is-current' : '' )
										}
									>
										{ marker }
										{ step.label }
									</li>
								);
							} ) }
						</ul>
					) : (
						<>
							<Spinner />
							<ul className="agentic-site-brief-checklist">
								{ checkers.map( ( id ) => (
									<li key={ id }>
										{ BRIEF_CHECKER_LABELS[ id ] || id }
									</li>
								) ) }
							</ul>
						</>
					) }
				</div>
			) }

			{ ! scanned && ! isRunning && (
				<p>
					{ /* A native <button> (not a styled div) so it is in the
					     accessibility tree, focusable, and named by its text.
					     aria-disabled keeps it focusable when the user lacks
					     the run capability. */ }
					<button
						type="button"
						className="button button-primary agentic-site-brief-scan"
						onClick={ canRun ? runScan : undefined }
						aria-disabled={ canRun ? undefined : 'true' }
						aria-describedby="agentic-site-brief-lede"
					>
						{ __( 'Scan this site', 'siteloom' ) }
					</button>
				</p>
			) }

			{ scanned && ! isRunning && brief.healthy && cards.length === 0 && (
				<div className="agentic-site-brief-healthy">
					<p>
						{ sprintf(
							/* translators: %s: last scan time. */
							__(
								'No urgent items. Last scan %s.',
								'siteloom'
							),
							formatBriefTime( brief.last_run )
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ runScan }
						disabled={ ! canRun }
					>
						{ __( 'Scan again', 'siteloom' ) }
					</Button>
				</div>
			) }

			{ scanned && ! isRunning && cards.length > 0 && (
				<>
					<ul className="agentic-site-brief-cards">
						{ visible.map( ( card ) => (
							<li
								key={ card.id }
								className="agentic-site-brief-item"
							>
								<div className="agentic-site-brief-item-head">
									<strong>{ card.title }</strong>
									<span className="agentic-text-muted">
										{ card.agent_label }
									</span>
								</div>
								{ card.evidence && (
									<p className="agentic-site-brief-evidence">
										{ card.evidence }
									</p>
								) }
								<p className="agentic-site-brief-action">
									{ card.proposed_action }
								</p>
								{ brief.is_advanced && (
									<details className="agentic-site-brief-advanced">
										<summary>
											{ __(
												'Advanced',
												'siteloom'
											) }
										</summary>
										<p>
											{ ( card.tool_slugs || [] ).join(
												', '
											) }
											{ ' · ' }
											{ card.action_risk }
										</p>
										{ card.raw && (
											<pre>
												{ JSON.stringify(
													card.raw,
													null,
													2
												) }
											</pre>
										) }
									</details>
								) }
								<div className="agentic-site-brief-buttons">
									{ card.assigned ? (
										<span className="agentic-site-brief-assigned">
											{ sprintf(
												/* translators: 1: agent display name, 2: assignment timestamp. */
												__(
													'Assigned to %s on %s',
													'siteloom'
												),
												card.assigned.agent_label ||
													card.assigned.agent,
												formatBriefTime(
													card.assigned.at
												)
											) }
										</span>
									) : (
										<Button
											variant="primary"
											label={ __(
												'Open the recommended agent in chat with this finding, to investigate and fix it together',
												'siteloom'
											) }
											showTooltip
											disabled={
												! canRun ||
												busyId === card.id
											}
											onClick={ () =>
												act( card, 'assign' )
											}
										>
											{ __(
												'Assign Agent',
												'siteloom'
											) }
										</Button>
									) }
									<Button
										variant="secondary"
										label={ __(
											'Dismiss to hide this card. It will not return unless the finding changes.',
											'siteloom'
										) }
										showTooltip
										disabled={
											! canRun || busyId === card.id
										}
										onClick={ () =>
											act( card, 'dismiss' )
										}
									>
										{ __( 'Dismiss', 'siteloom' ) }
									</Button>
									<Button
										variant="tertiary"
										label={ __(
											'Not now leaves it for later. It may reappear on the next scan.',
											'siteloom'
										) }
										showTooltip
										onClick={ () =>
											act( card, 'not_now' )
										}
									>
										{ __( 'Not now', 'siteloom' ) }
									</Button>
								</div>
							</li>
						) ) }
					</ul>
					{ cards.length > 10 && ! showAll && (
						<p>
							<Button
								variant="link"
								onClick={ () => setShowAll( true ) }
							>
								{ sprintf(
									/* translators: %d: hidden card count. */
									__( 'Show all (%d)', 'siteloom' ),
									cards.length
								) }
							</Button>
						</p>
					) }
					<p>
						<Button
							variant="secondary"
							onClick={ runScan }
							disabled={ ! canRun }
						>
							{ __( 'Scan again', 'siteloom' ) }
						</Button>
					</p>
				</>
			) }

			{ brief.store_stats && (
				<p className="agentic-text-muted agentic-site-brief-store">
					{ sprintf(
						/* translators: 1: order count, 2: revenue, 3: currency. */
						__(
							'Store this month: %1$s orders, %2$s %3$s.',
							'siteloom'
						),
						Number( brief.store_stats.total_orders || 0 ),
						Number( brief.store_stats.total_revenue || 0 ),
						brief.store_stats.currency || ''
					) }
				</p>
			) }
		</div>
	);
}

function formatInt( n ) {
	return Number( n || 0 ).toLocaleString();
}

function AdminPageFooter( { footer } ) {
	const f = footer || {};
	const docUrl = f.doc_url || 'https://agentic-plugin.com/documentation/dashboard/';
	const supportUrl = f.support_url || 'https://agentic-plugin.com/support/';
	const policy =
		f.policy ||
		__(
			'The dashboard summarizes agents, approvals, activity, and providers at a glance.',
			'siteloom'
		);

	return (
		<div className="agentic-page-footer agentic-page-footer--react">
			<span className="agentic-page-footer-left">
				{ policy && (
					<span className="agentic-page-footer-policy">
						{ policy }{ ' ' }
					</span>
				) }
				{ __( 'Need help?', 'siteloom' ) }{ ' ' }
				<a
					href={ supportUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Visit our Support Center', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a href={ docUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Documentation', 'siteloom' ) }
				</a>
			</span>
			<span className="agentic-page-footer-right">
				<a
					href={
						f.terms_url ||
						'https://agentic-plugin.com/terms-of-service/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Terms of Service', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a
					href={
						f.privacy_url ||
						'https://agentic-plugin.com/privacy-policy/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Privacy Policy', 'siteloom' ) }
				</a>
				{ ' | ' }
				<a
					href={
						f.gdpr_url || 'https://agentic-plugin.com/gdpr-policy/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'GDPR Policy', 'siteloom' ) }
				</a>
			</span>
		</div>
	);
}

function Card( {
	title,
	headerLink,
	children,
	className = '',
	cardId,
	draggable = false,
	onDragStart,
	onDragOver,
	onDrop,
	onDragEnd,
	isDragging = false,
	isDropTarget = false,
} ) {
	return (
		<div
			className={
				`agentic-card ${ className }` +
				( isDragging ? ' is-dragging' : '' ) +
				( isDropTarget ? ' is-drop-target' : '' ) +
				( draggable ? ' has-drag-handle' : '' )
			}
			data-card-id={ cardId || undefined }
			onDragOver={
				draggable
					? ( e ) => {
							e.preventDefault();
							onDragOver && onDragOver( e, cardId );
					  }
					: undefined
			}
			onDrop={
				draggable
					? ( e ) => {
							e.preventDefault();
							onDrop && onDrop( e, cardId );
					  }
					: undefined
			}
		>
			{ ( title || headerLink ) && (
				<div className="agentic-card-header agentic-flex-between">
					{ title ? <h2>{ title }</h2> : <span /> }
					{ headerLink }
				</div>
			) }
			{ children }
			{ draggable && (
				<span
					className="agentic-card-drag-handle"
					title={ __( 'Drag to rearrange', 'siteloom' ) }
					draggable
					onDragStart={ ( e ) =>
						onDragStart && onDragStart( e, cardId )
					}
					onDragEnd={ onDragEnd }
					role="button"
					tabIndex={ 0 }
					aria-label={ __(
						'Drag to rearrange dashboard card',
						'siteloom'
					) }
				>
					⋮⋮
				</span>
			) }
		</div>
	);
}

function StatusTile( { label, tip, children } ) {
	return (
		<div className="agentic-status-item">
			<div className="agentic-status-label">
				{ label }
				{ tip && <InfoTip text={ tip } /> }
			</div>
			<div className="agentic-status-value">{ children }</div>
		</div>
	);
}

function Metric( { label, value, href } ) {
	return (
		<div className="agentic-metric">
			<div className="agentic-metric-label">
				{ href ? <a href={ href }>{ label }</a> : label }
			</div>
			<div className="agentic-metric-value">{ value }</div>
		</div>
	);
}

function StatusCard( { data, dnd } ) {
	const lic = data.license || {};
	const jobs = data.jobs || {};
	const pendClass =
		jobs.abandoned > 0 ? 'agentic-status-error' : 'agentic-status-active';
	const runClass =
		jobs.stuck > 0 ? 'agentic-status-error' : 'agentic-status-active';
	const licClass =
		lic.class === 'error'
			? 'agentic-status-error'
			: lic.class === 'expiring'
			? 'agentic-status-expiring'
			: lic.class
			? 'agentic-status-active'
			: '';

	return (
		<Card
			cardId="status"
			{ ...dnd }
			title={ __( 'Status', 'siteloom' ) }
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'License', 'siteloom' ) }>
					{ licClass ? (
						<span className={ licClass }>
							● { lic.label }
						</span>
					) : (
						lic.label
					) }
				</StatusTile>
				<StatusTile label={ __( 'Version', 'siteloom' ) }>
					{ data.version }
				</StatusTile>
				<StatusTile
					label={ __( 'Schema', 'siteloom' ) }
					tip={ __(
						'The version of Siteloom’s database structure. Updates automatically when needed — you don’t need to do anything here.',
						'siteloom'
					) }
				>
					<span className="agentic-status-active">●</span>{ ' ' }
					{ data.schema_version }
				</StatusTile>
				<StatusTile label={ __( 'Pending Jobs', 'siteloom' ) }>
					<span className={ pendClass }>●</span>{ ' ' }
					{ jobs.pending ?? 0 }
					{ jobs.abandoned > 0 && (
						<span className="agentic-status-expiring">
							{ ' ' }({ jobs.abandoned }{ ' ' }
							{ __(
								'abandoned — auto-recovered',
								'siteloom'
							) }
							)
						</span>
					) }
				</StatusTile>
				<StatusTile label={ __( 'Running Jobs', 'siteloom' ) }>
					<span className={ runClass }>●</span>{ ' ' }
					{ jobs.processing ?? 0 }
					{ jobs.stuck > 0 && (
						<span className="agentic-status-error">
							{ ' ' }({ jobs.stuck }{ ' ' }
							{ __( 'stuck — auto-recovered', 'siteloom' ) })
						</span>
					) }
				</StatusTile>
				<StatusTile label={ __( 'Completed Jobs', 'siteloom' ) }>
					<span className="agentic-status-active">●</span>{ ' ' }
					{ jobs.completed ?? 0 }
				</StatusTile>
			</div>
		</Card>
	);
}

function SafetyCard( { data, dnd } ) {
	const safety = data.safety || {};
	const pending = Number( safety.pending_approvals || 0 );
	const pendingClass = pending > 0 ? 'agentic-status-expiring' : 'agentic-status-active';

	return (
		<Card
			cardId="safety"
			{ ...dnd }
			title={ __( 'Approvals & Backups', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.approvals }
				>
					{ __( 'View Approvals →', 'siteloom' ) }
				</a>
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'Pending Approvals', 'siteloom' ) }>
					<a href={ data.urls?.approvals }>
						<span className={ pendingClass }>●</span>{ ' ' }
						{ formatInt( pending ) }
					</a>
				</StatusTile>
				<StatusTile label={ __( 'Completed Approvals', 'siteloom' ) }>
					<a href={ data.urls?.approvals }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( safety.completed_approvals ) }
					</a>
				</StatusTile>
			</div>
			<div className="agentic-status-grid agentic-mt-8">
				<StatusTile label={ __( 'Files Backed Up', 'siteloom' ) }>
					<a href={ data.urls?.backups }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( safety.file_backups ) }
					</a>
				</StatusTile>
				<StatusTile label={ __( 'DB Tables Backed Up', 'siteloom' ) }>
					<a href={ data.urls?.backups }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( safety.table_backups ) }
					</a>
				</StatusTile>
			</div>
			{ pending > 0 && (
				<p className="agentic-text-muted">
					{ sprintf(
						/* translators: %s: number of pending approvals. */
						__( '%s waiting for your OK.', 'siteloom' ),
						formatInt( pending )
					) }
				</p>
			) }
			<p className="agentic-text-muted">
				<a href={ data.urls?.safety_center || '#' }>
					{ __( 'Open Safety Center →', 'siteloom' ) }
				</a>
			</p>
		</Card>
	);
}

function TasksCard( { data, dnd } ) {
	const tasks = data.tasks || {};
	const runs = tasks.runs || [];
	const total = Number( tasks.active || 0 ) + Number( tasks.waiting || 0 );
	const waitingClass =
		Number( tasks.waiting ) > 0
			? 'agentic-status-expiring'
			: 'agentic-status-active';

	return (
		<Card
			cardId="tasks"
			{ ...dnd }
			title={ __( 'Tasks', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.tasks }
				>
					{ __( 'Open Tasks →', 'siteloom' ) }
				</a>
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'Active', 'siteloom' ) }>
					<a href={ data.urls?.tasks }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( tasks.active ) }
					</a>
				</StatusTile>
				<StatusTile label={ __( 'Waiting on you', 'siteloom' ) }>
					<a href={ data.urls?.tasks }>
						<span className={ waitingClass }>●</span>{ ' ' }
						{ formatInt( tasks.waiting ) }
					</a>
				</StatusTile>
			</div>

			{ ! runs.length ? (
				<p className="agentic-text-muted">
					{ __(
						'No tasks running. Assign one and you’ll be pinged when it finishes.',
						'siteloom'
					) }
				</p>
			) : (
				<ul className="agentic-tasks-card-list">
					{ runs.map( ( r ) => (
						<li key={ r.run_id }>
							<a href={ data.urls?.tasks }>
								<strong>{ r.agent }</strong>
								<span className="agentic-tasks-card-excerpt">
									{ r.task_text }
								</span>
								<span
									className={
										'agentic-tasks-card-status agentic-tasks-card-status--' +
										r.status
									}
								>
									{ r.status }
								</span>
							</a>
						</li>
					) ) }
				</ul>
			) }

			{ total > runs.length && (
				<p className="agentic-text-muted">
					{ sprintf(
						/* translators: %s: number of additional tasks not shown */
						__(
							'%s more — open Tasks to see them all.',
							'siteloom'
						),
						formatInt( total - runs.length )
					) }
				</p>
			) }
		</Card>
	);
}

function AgentReadyCard( { data, dnd, onScanned } ) {
	const ready = data.agent_ready || {};
	const overall = Number( ready.overall || 0 );
	const [ scanning, setScanning ] = useState( false );
	const [ scanError, setScanError ] = useState( '' );

	const runFirstScan = () => {
		setScanning( true );
		setScanError( '' );
		apiFetch( { path: 'agentic/v1/score/rescan', method: 'POST' } )
			.then( () => ( onScanned ? onScanned() : null ) )
			.catch( ( e ) => {
				setScanError(
					e.message || __( 'Scan failed.', 'siteloom' )
				);
			} )
			.finally( () => setScanning( false ) );
	};

	// Fresh install: no completed scan yet — say so and offer the scan,
	// instead of a red grade computed before the site is set up.
	if ( false === ready.scanned ) {
		return (
			<Card
				cardId="agent-ready"
				{ ...dnd }
				title={ __( 'Site Passport', 'siteloom' ) }
				headerLink={
					<a
						className="agentic-card-header-link"
						href={ data.urls?.agent_ready }
					>
						{ __( 'View Details →', 'siteloom' ) }
					</a>
				}
			>
				<div className="agentic-status-grid">
					<StatusTile label={ __( 'Site Score', 'siteloom' ) }>
						{ __( 'Not scanned yet', 'siteloom' ) }
					</StatusTile>
				</div>
				<p className="agentic-text-muted">
					{ __(
						'Check how ready this site is for AI agents. The scan runs on this site only and changes nothing.',
						'siteloom'
					) }
				</p>
				{ scanError && (
					<p className="agentic-status-error" role="alert">
						{ scanError }
					</p>
				) }
				<p>
					<Button
						variant="secondary"
						onClick={ runFirstScan }
						isBusy={ scanning }
						disabled={ scanning }
					>
						{ scanning
							? __( 'Scanning…', 'siteloom' )
							: __( 'Scan now', 'siteloom' ) }
					</Button>
				</p>
			</Card>
		);
	}
	const gradeClass =
		overall >= 75
			? 'agentic-status-active'
			: overall >= 40
			? 'agentic-status-expiring'
			: 'agentic-status-error';

	return (
		<Card
			cardId="agent-ready"
			{ ...dnd }
			title={ __( 'Site Passport', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.agent_ready }
				>
					{ __( 'View Details →', 'siteloom' ) }
				</a>
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'Site Score', 'siteloom' ) }>
					<span className={ gradeClass }>●</span>{ ' ' }
					{ overall } ({ ready.grade || '—' })
				</StatusTile>
			</div>
			{ ready.top_fix && (
				<p className="agentic-text-muted">
					{ ready.top_fix.detail }
					{ ' ' }
					<a href={ data.urls?.agent_ready }>
						{ __( 'Fix now →', 'siteloom' ) }
					</a>
				</p>
			) }
		</Card>
	);
}

function ActivityCard( { data, activity, dnd } ) {
	const a = activity || data.activity || {};
	const agents = data.agents || {};
	const usageHref = data.is_pro
		? data.urls?.admin + 'admin.php?page=agentic-costs'
		: data.urls?.activity;
	return (
		<Card
			cardId="activity"
			{ ...dnd }
			title={ __( 'Activity', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.activity }
				>
					{ __( 'View Activity →', 'siteloom' ) }
				</a>
			}
		>
			<div className="agentic-metrics-grid">
				<Metric
					label={ __( 'Total Actions', 'siteloom' ) }
					value={ formatInt( a.actions ) }
					href={ data.urls?.activity }
				/>
				<Metric
					label={ __( 'Tokens Used', 'siteloom' ) }
					value={ formatInt( a.tokens ) }
					href={ usageHref }
				/>
				{ Number( a.cost ) > 0 && (
					<Metric
						label={
							data.is_pro
								? __( 'Est. Cost', 'siteloom' )
								: __( 'Estimated Usage', 'siteloom' )
						}
						value={ '$' + Number( a.cost ).toFixed( 4 ) }
						href={ usageHref }
					/>
				) }
				<Metric
					label={ __( 'Active Agents', 'siteloom' ) }
					value={ formatInt( agents.active ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
				<Metric
					label={ __( 'Uploaded Agents', 'siteloom' ) }
					value={ formatInt( agents.uploaded ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
				<Metric
					label={ __( 'User-Created Agents', 'siteloom' ) }
					value={ formatInt( agents.user_created ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
			</div>
		</Card>
	);
}

function ProvidersCard( { data, dnd } ) {
	const rows = data.providers || [];
	const [ tests, setTests ] = useState( {} );

	const testProvider = ( slug ) => {
		setTests( ( prev ) => ( {
			...prev,
			[ slug ]: { testing: true, ok: null, message: '' },
		} ) );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'test_provider', slug },
		} )
			.then( ( res ) =>
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: !! res.ok,
						message: res.message || '',
					},
				} ) )
			)
			.catch( ( err ) =>
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: false,
						message:
							err.message ||
							__( 'Test failed.', 'siteloom' ),
					},
				} ) )
			);
	};

	return (
		<Card
			cardId="providers"
			{ ...dnd }
			title={ __( 'Connected Providers', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.providers }
				>
					{ __( 'Manage Providers →', 'siteloom' ) }
				</a>
			}
		>
			{ ! rows.length ? (
				<p className="agentic-mt-12">
					{ __( 'No AI providers connected.', 'siteloom' ) }{ ' ' }
					<a href={ data.urls?.admin + 'admin.php?page=agentic-setup' }>
						{ __( 'Run the Setup Wizard', 'siteloom' ) }
					</a>
				</p>
			) : (
				<div className="agentic-provider-list">
					{ rows.map( ( p ) => {
						const t = tests[ p.slug ];
						return (
							<div
								key={ p.slug }
								className={
									'agentic-provider-row' +
									( p.is_default
										? ' agentic-provider-active'
										: '' )
								}
							>
								<span className="agentic-provider-dot">●</span>
								<div className="agentic-provider-meta">
									<div className="agentic-provider-line">
										<span className="agentic-provider-k">
											{ __(
												'Provider',
												'siteloom'
											) }
										</span>
										<span className="agentic-provider-name">
											{ p.name }
										</span>
									</div>
									<div className="agentic-provider-line">
										<span className="agentic-provider-k">
											{ __( 'Model', 'siteloom' ) }
										</span>
										<span className="agentic-provider-model">
											{ p.model || '—' }
										</span>
									</div>
									{ t && null !== t.ok && (
										<div className="agentic-provider-line">
											<span
												className={
													t.ok
														? 'agentic-status-active'
														: 'agentic-status-error'
												}
											>
												{ t.ok ? '✓' : '✗' }{ ' ' }
												{ t.message }
											</span>
										</div>
									) }
								</div>
								{ p.is_default && (
									<span className="agentic-provider-badge">
										{ __( 'Default', 'siteloom' ) }
									</span>
								) }
								<button
									type="button"
									className="button button-small"
									disabled={ !! t?.testing }
									onClick={ () => testProvider( p.slug ) }
								>
									{ t?.testing
										? __( 'Testing…', 'siteloom' )
										: __( 'Test', 'siteloom' ) }
								</button>
							</div>
						);
					} ) }
				</div>
			) }
		</Card>
	);
}

function QuickActionsCard( { data, onSaveQuickActions, mutate, dnd } ) {
	const [ manage, setManage ] = useState( false );
	const [ selected, setSelected ] = useState( () =>
		( data.quick_actions || [] )
			.filter( ( a ) => a.enabled )
			.map( ( a ) => a.slug )
	);
	const [ saving, setSaving ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const setEmergency = ( enable ) => {
		const msg = enable
			? __(
					'EMERGENCY STOP: deactivate and log agent states, cancel all jobs, and disconnect providers. Continue?',
					'siteloom'
			  )
			: __( 'Turn off emergency stop?', 'siteloom' );
		if ( ! window.confirm( msg ) ) {
			return;
		}
		setBusy( true );
		mutate( { action_name: 'set_emergency_stop', enable } )
			.then( ( d ) => {
				const warnings = Array.isArray( d?.warnings ) ? d.warnings : [];
				if ( warnings.length ) {
					window.alert(
						__(
							'Emergency stop restore finished with warnings:',
							'siteloom'
						) +
							'\n\n' +
							warnings.join( '\n' )
					);
				}
			} )
			.finally( () => setBusy( false ) );
	};

	useEffect( () => {
		setSelected(
			( data.quick_actions || [] )
				.filter( ( a ) => a.enabled )
				.map( ( a ) => a.slug )
		);
	}, [ data.quick_actions ] );

	// A user who explicitly enabled an action via "Manage Actions" sees it
	// regardless of Basic/Advanced mode — that per-user choice is the whole
	// point of the picker. The "Advanced" note in the picker itself is just
	// informational context, not a second gate on top of `enabled`.
	const catalog = data.quick_actions || [];
	const primary = catalog.filter(
		( a ) => a.enabled && a.group !== 'secondary'
	);
	const secondary = catalog.filter(
		( a ) => a.enabled && a.group === 'secondary'
	);

	const toggle = ( slug, locked ) => {
		if ( locked ) {
			return;
		}
		setSelected( ( prev ) =>
			prev.includes( slug )
				? prev.filter( ( s ) => s !== slug )
				: [ ...prev, slug ]
		);
	};

	const save = () => {
		setSaving( true );
		onSaveQuickActions( selected ).finally( () => {
			setSaving( false );
			setManage( false );
		} );
	};

	return (
		<Card
			cardId="quick-actions"
			{ ...dnd }
			title={ __( 'Quick Actions', 'siteloom' ) }
			headerLink={
				<button
					type="button"
					className="agentic-card-header-link"
					onClick={ () => setManage( ( m ) => ! m ) }
				>
					{ __( 'Manage Actions →', 'siteloom' ) }
				</button>
			}
		>
			{ data.emergency_stop ? (
				<p className="agentic-mb-12">
					{ __( 'All agents are stopped —', 'siteloom' ) }{ ' ' }
					<a href={ data.urls?.safety_center }>
						{ __( 'resume in Safety Center', 'siteloom' ) }
					</a>
				</p>
			) : (
				! data.is_configured && (
					<p className="agentic-mb-12">
						{ __( 'Chat agent offline —', 'siteloom' ) }{ ' ' }
						<a href={ data.urls?.signup }>
							{ __( 'Configure now', 'siteloom' ) }
						</a>
					</p>
				)
			) }

			{ manage && (
				<div className="agentic-manage-actions-panel">
					<p className="description agentic-mb-12">
						{ __(
							'Choose which links appear in Quick Actions. Setup Wizard is always available.',
							'siteloom'
						) }
					</p>
					<ul className="agentic-manage-actions-list">
						{ catalog.map( ( item ) => {
							const notes = [];
							if ( item.advanced ) {
								notes.push( __( 'Advanced', 'siteloom' ) );
							}
							if ( item.locked ) {
								notes.push( __( 'Required', 'siteloom' ) );
							}
							const checked =
								item.locked || selected.includes( item.slug );
							return (
								<li key={ item.slug }>
									<label
										className={
											'agentic-manage-actions-item' +
											( item.locked ? ' is-locked' : '' )
										}
									>
										<input
											type="checkbox"
											checked={ checked }
											disabled={ item.locked }
											onChange={ () =>
												toggle(
													item.slug,
													item.locked
												)
											}
										/>
										<span className="agentic-manage-actions-label">
											{ item.label }
										</span>
										{ notes.length > 0 && (
											<span className="agentic-manage-actions-note">
												{ notes.join( ' · ' ) }
											</span>
										) }
									</label>
								</li>
							);
						} ) }
					</ul>
					<p className="agentic-manage-actions-footer">
						<button
							type="button"
							className="button button-primary"
							disabled={ saving }
							onClick={ save }
						>
							{ saving
								? __( 'Saving…', 'siteloom' )
								: __( 'Save Quick Actions', 'siteloom' ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ () => setManage( false ) }
						>
							{ __( 'Cancel', 'siteloom' ) }
						</button>
					</p>
				</div>
			) }

			<div className="agentic-quick-actions">
				{ primary.map( ( a ) => (
					<a
						key={ a.slug }
						href={ a.url }
						className={
							'setup' === a.slug
								? 'button button-primary'
								: 'button'
						}
					>
						{ a.label }
					</a>
				) ) }
			</div>
			{ secondary.length > 0 && (
				<div className="agentic-quick-actions agentic-quick-actions-secondary agentic-mt-8">
					{ secondary.map( ( a ) => (
						<a key={ a.slug } href={ a.url } className="button">
							{ a.label }
						</a>
					) ) }
				</div>
			) }

			<div className="agentic-quick-actions-emergency">
				<div className="agentic-updates-row">
					<span className="agentic-mode-label agentic-emergency-label">
						{ __( 'Disable All Agents', 'siteloom' ) }
						{ data.emergency_stop && (
							<span className="agentic-emergency-badge">
								{ __( 'ACTIVE', 'siteloom' ) }
							</span>
						) }
					</span>
					<label className="agentic-switch">
						<input
							type="checkbox"
							checked={ !! data.emergency_stop }
							disabled={ busy }
							onChange={ ( e ) =>
								setEmergency( e.target.checked )
							}
						/>
						<span className="agentic-switch-slider" />
					</label>
				</div>
				<span className="agentic-mode-hint agentic-emergency-stop-hint">
					<strong className="agentic-emergency-stop-text">
						{ __( 'Emergency stop', 'siteloom' ) }
					</strong>
					{ __(
						': deactivate and log agent states, cancels all jobs and disconnects providers.',
						'siteloom'
					) }
				</span>
			</div>
		</Card>
	);
}

function InterfaceCard( { data, mutate, dnd } ) {
	const [ busy, setBusy ] = useState( false );

	const setMode = ( mode ) => {
		setBusy( true );
		mutate( { action_name: 'set_ui_mode', mode } ).finally( () =>
			setBusy( false )
		);
	};

	const setUpdates = ( enable ) => {
		setBusy( true );
		mutate( { action_name: 'set_agent_updates', enable } ).finally( () =>
			setBusy( false )
		);
	};

	return (
		<Card
			cardId="interface"
			{ ...dnd }
			title={ __( 'Interface Settings', 'siteloom' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.interface }
				>
					{ __( 'Manage Interface →', 'siteloom' ) }
				</a>
			}
			className="agentic-settings-card"
		>
			<div className="agentic-interface-section agentic-mode-toggle">
				<span className="agentic-mode-label">
					{ __( 'Default interface:', 'siteloom' ) }
				</span>
				<button
					type="button"
					className={
						'button' +
						( ! data.is_advanced ? ' button-primary' : '' )
					}
					disabled={ busy }
					onClick={ () => setMode( 'basic' ) }
				>
					{ __( 'Basic', 'siteloom' ) }
				</button>
				<button
					type="button"
					className={
						'button' +
						( data.is_advanced ? ' button-primary' : '' )
					}
					disabled={ busy }
					onClick={ () => setMode( 'advanced' ) }
				>
					{ __( 'Advanced', 'siteloom' ) }
				</button>
				<span className="agentic-mode-hint agentic-text-muted">
					{ __(
						'Used by any screen you have not set individually. Tools, Approvals, and Activity each have their own Basic/Advanced switch now.',
						'siteloom'
					) }
				</span>
				<button
					type="button"
					className="button-link agentic-reset-screens-link"
					disabled={ busy }
					onClick={ () => {
						if (
							! window.confirm(
								__(
									'Reset every screen back to your default interface? Any screen you switched individually will lose that override.',
									'siteloom'
								)
							)
						) {
							return;
						}
						setBusy( true );
						apiFetch( {
							path: 'agentic/v1/admin-page',
							method: 'POST',
							data: { action_name: 'reset_screen_modes' },
						} ).finally( () => setBusy( false ) );
					} }
				>
					{ __(
						'Reset all screens to my default',
						'siteloom'
					) }
				</button>
			</div>

			{ data.has_agent_updates_class ? (
				<div className="agentic-interface-section agentic-updates-toggle">
					<div className="agentic-updates-row">
						<span className="agentic-mode-label">
							{ __(
								'Automatic Agent Updates',
								'siteloom'
							) }
						</span>
						<label className="agentic-switch">
							<input
								type="checkbox"
								checked={ !! data.agent_updates }
								disabled={ busy }
								onChange={ ( e ) =>
									setUpdates( e.target.checked )
								}
							/>
							<span className="agentic-switch-slider" />
						</label>
					</div>
					<span className="agentic-mode-hint agentic-text-muted">
						{ __(
							'Keep installed agents updated automatically. Opt out any time.',
							'siteloom'
						) }
					</span>
				</div>
			) : null }
		</Card>
	);
}

function GettingStartedCard( { data, dnd } ) {
	const steps = data.onboarding || [];
	const done = steps.filter( ( s ) => s.done ).length;
	return (
		<Card
			cardId="getting-started"
			{ ...dnd }
			title={ __( 'Getting Started', 'siteloom' ) }
		>
			<p className="agentic-text-muted agentic-mb-12">
				{ sprintf(
					/* translators: 1: done count, 2: total */
					__( '%1$d of %2$d steps complete', 'siteloom' ),
					done,
					steps.length
				) }
			</p>
			<ul className="agentic-onboarding-list">
				{ steps.map( ( step ) => (
					<li
						key={ step.id }
						className={
							'agentic-onboarding-item' +
							( step.done ? ' is-done' : '' )
						}
					>
						<span
							className={
								'agentic-onboarding-check dashicons ' +
								( step.done
									? 'dashicons-yes-alt'
									: 'dashicons-marker' )
							}
						/>
						<span className="agentic-onboarding-label">
							{ step.label }
						</span>
						{ ! step.done && (
							<a
								href={ step.url }
								className="button button-small"
							>
								{ step.cta }
							</a>
						) }
					</li>
				) ) }
			</ul>
		</Card>
	);
}

// sprintf helper without full @wordpress/i18n sprintf import issues
function sprintf( format, ...args ) {
	let i = 0;
	return format.replace( /%(\d+)\$d|%d|%s/g, ( match ) => {
		if ( match.startsWith( '%' ) && match.includes( '$' ) ) {
			const n = parseInt( match.slice( 1 ), 10 ) - 1;
			return String( args[ n ] ?? '' );
		}
		return String( args[ i++ ] ?? '' );
	} );
}

const DEFAULT_LAYOUT = [
	'status',
	'safety',
	'tasks',
	'activity',
	'providers',
	'quick-actions',
	'interface',
	'getting-started',
];

function DashboardApp() {
	const [ data, setData ] = useState( null );
	const [ activity, setActivity ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ layout, setLayout ] = useState( DEFAULT_LAYOUT );
	const [ dragId, setDragId ] = useState( null );
	const [ overId, setOverId ] = useState( null );
	const [ layoutSaving, setLayoutSaving ] = useState( false );

	const load = useCallback( () => {
		return apiFetch( { path: DASH_PATH } )
			.then( ( d ) => {
				setData( d );
				setActivity( d.activity );
				if ( Array.isArray( d.layout ) && d.layout.length ) {
					setLayout( d.layout );
				}
				setError( '' );
				setLoading( false );
			} )
			.catch( ( e ) => {
				setError(
					e.message ||
						__( 'Could not load dashboard.', 'siteloom' )
				);
				setLoading( false );
			} );
	}, [] );

	const mutate = useCallback(
		( body ) =>
			apiFetch( {
				path: DASH_PATH,
				method: 'POST',
				data: body,
			} ).then( ( d ) => {
				setData( d );
				setActivity( d.activity );
				if ( Array.isArray( d.layout ) && d.layout.length ) {
					setLayout( d.layout );
				}
				return d;
			} ),
		[]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	useEffect( () => {
		const t = setInterval( () => {
			apiFetch( { path: STATS_PATH } )
				.then( ( res ) => {
					setActivity( res.activity );
					if ( res.agents ) {
						setData( ( prev ) =>
							prev ? { ...prev, agents: res.agents } : prev
						);
					}
				} )
				.catch( () => {} );
		}, REFRESH_MS );
		return () => clearInterval( t );
	}, [] );

	const saveQuickActions = ( actions ) =>
		mutate( { action_name: 'save_quick_actions', actions } );

	const persistLayout = ( next ) => {
		setLayout( next );
		setLayoutSaving( true );
		mutate( { action_name: 'save_layout', layout: next } )
			.catch( () => {} )
			.finally( () => setLayoutSaving( false ) );
	};

	const resetLayout = () => {
		setLayoutSaving( true );
		mutate( { action_name: 'reset_layout' } )
			.then( ( d ) => {
				if ( Array.isArray( d.layout_default ) ) {
					setLayout( d.layout_default );
				} else {
					setLayout( DEFAULT_LAYOUT );
				}
			} )
			.finally( () => setLayoutSaving( false ) );
	};

	const onDragStart = ( e, cardId ) => {
		setDragId( cardId );
		try {
			e.dataTransfer.setData( 'text/plain', cardId );
			e.dataTransfer.effectAllowed = 'move';
		} catch ( err ) {
			// ignore
		}
	};

	const onDragOver = ( e, cardId ) => {
		e.preventDefault();
		if ( cardId && cardId !== overId ) {
			setOverId( cardId );
		}
	};

	const onDrop = ( e, targetId ) => {
		e.preventDefault();
		let sourceId = dragId;
		try {
			sourceId = e.dataTransfer.getData( 'text/plain' ) || dragId;
		} catch ( err ) {
			// ignore
		}
		setDragId( null );
		setOverId( null );
		if ( ! sourceId || ! targetId || sourceId === targetId ) {
			return;
		}
		setLayout( ( prev ) => {
			const next = prev.filter( ( id ) => id !== sourceId );
			const idx = next.indexOf( targetId );
			if ( idx === -1 ) {
				next.push( sourceId );
			} else {
				next.splice( idx, 0, sourceId );
			}
			// Persist outside setState
			setTimeout( () => persistLayout( next ), 0 );
			return next;
		} );
	};

	const onDragEnd = () => {
		setDragId( null );
		setOverId( null );
	};

	const dndFor = ( cardId ) => ( {
		draggable: true,
		onDragStart,
		onDragOver,
		onDrop,
		onDragEnd,
		isDragging: dragId === cardId,
		isDropTarget: overId === cardId && dragId && dragId !== cardId,
	} );

	if ( loading ) {
		return (
			<div className="wrap agentic-admin">
				<p>
					<Spinner /> { __( 'Loading dashboard…', 'siteloom' ) }
				</p>
			</div>
		);
	}

	if ( error || ! data ) {
		return (
			<div className="wrap agentic-admin">
				<Notice status="error" isDismissible={ false }>
					{ error || __( 'Dashboard unavailable.', 'siteloom' ) }
				</Notice>
			</div>
		);
	}

	const onboardingSteps = data.onboarding || [];
	const onboardingComplete =
		onboardingSteps.length > 0 &&
		onboardingSteps.every( ( s ) => s.done );

	const visibleLayout = layout.filter( ( id ) => {
		// Hide when toggled off in Interface settings, or when every step is done.
		if (
			id === 'getting-started' &&
			( ! data.show_onboarding || onboardingComplete )
		) {
			return false;
		}
		return true;
	} );

	const renderCard = ( id ) => {
		const dnd = dndFor( id );
		switch ( id ) {
			case 'status':
				return <StatusCard key={ id } data={ data } dnd={ dnd } />;
			case 'safety':
				return <SafetyCard key={ id } data={ data } dnd={ dnd } />;
			case 'agent-ready':
				return (
					<AgentReadyCard
						key={ id }
						data={ data }
						dnd={ dnd }
						onScanned={ load }
					/>
				);
			case 'tasks':
				return <TasksCard key={ id } data={ data } dnd={ dnd } />;
			case 'activity':
				return (
					<ActivityCard
						key={ id }
						data={ data }
						activity={ activity }
						dnd={ dnd }
					/>
				);
			case 'providers':
				return <ProvidersCard key={ id } data={ data } dnd={ dnd } />;
			case 'quick-actions':
				return (
					<QuickActionsCard
						key={ id }
						data={ data }
						onSaveQuickActions={ saveQuickActions }
						mutate={ mutate }
						dnd={ dnd }
					/>
				);
			case 'interface':
				return (
					<InterfaceCard
						key={ id }
						data={ data }
						mutate={ mutate }
						dnd={ dnd }
					/>
				);
			case 'getting-started':
				return (
					<GettingStartedCard key={ id } data={ data } dnd={ dnd } />
				);
			default:
				return null;
		}
	};

	const layoutIsCustom =
		JSON.stringify( layout ) !==
		JSON.stringify( data.layout_default || DEFAULT_LAYOUT );

	return (
		<div className="wrap agentic-admin">
			<div className="agentic-dashboard-title-row">
				<h1>
					<img
						src={ data.urls?.icon }
						width={ 30 }
						height={ 30 }
						className="agentic-icon-sm"
						alt=""
					/>{ ' ' }
					Siteloom
				</h1>
				<div className="agentic-dashboard-layout-actions">
					{ layoutSaving && (
						<span className="agentic-text-muted">
							{ __( 'Saving layout…', 'siteloom' ) }
						</span>
					) }
					{ layoutIsCustom && (
						<button
							type="button"
							className="button button-small"
							onClick={ resetLayout }
							disabled={ layoutSaving }
						>
							{ __( 'Reset layout', 'siteloom' ) }
						</button>
					) }
					<span className="agentic-dashboard-drag-hint agentic-text-muted">
						{ __( 'Drag ⋮⋮ to rearrange cards', 'siteloom' ) }
					</span>
				</div>
			</div>

			<SiteBriefPanel />

			{ data.emergency_stop && (
				<div className="agentic-emergency-banner" role="alert">
					<span>
						<strong>
							{ __(
								'Emergency stop is ACTIVE',
								'siteloom'
							) }
						</strong>
						{ ' — ' }
						{ __(
							'All agents are deactivated, jobs are cancelled, and LLM providers are disconnected. Chat and agent activation are blocked.',
							'siteloom'
						) }{ ' ' }
						<a href={ data.urls?.interface }>
							{ __( 'Manage Interface', 'siteloom' ) }
						</a>
					</span>
				</div>
			) }

			<div className="agentic-dashboard-grid">
				{ visibleLayout.map( ( id ) => renderCard( id ) ) }
			</div>

			<AdminPageFooter footer={ data.footer } />
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-dashboard-app-root' );
	if ( root ) {
		createRoot( root ).render( <DashboardApp /> );
	}
} );
