/**
 * Full Agent Builder dashboard — all cards as React + @wordpress/components.
 */
import { createRoot, useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Spinner, Notice } from '@wordpress/components';
import { InfoTip } from '../shared/components';

const DASH_PATH = 'agentic/v1/dashboard';
const STATS_PATH = 'agentic/v1/dashboard-stats?period=week';
const REFRESH_MS = 30000;

function formatInt( n ) {
	return Number( n || 0 ).toLocaleString();
}

function AdminPageFooter( { footer } ) {
	const f = footer || {};
	const docUrl = f.doc_url || 'https://agentic-plugin.com/the-dashboard/';
	const supportUrl = f.support_url || 'https://agentic-plugin.com/support/';
	const promoUrl = f.promo_url || 'https://agentic-plugin.com/pricing/';
	const promoLabel = f.promo_label || __( 'Upgrade to Pro', 'agent-builder' );
	const promoExternal =
		typeof f.promo_external === 'boolean'
			? f.promo_external
			: /^https?:\/\//i.test( promoUrl );
	const policy =
		f.policy ||
		__(
			'The dashboard summarizes agents, approvals, activity, and providers at a glance.',
			'agent-builder'
		);

	return (
		<div className="agentic-page-footer agentic-page-footer--react">
			<span className="agentic-page-footer-left">
				{ policy && (
					<span className="agentic-page-footer-policy">
						{ policy }{ ' ' }
					</span>
				) }
				{ __( 'Need help?', 'agent-builder' ) }{ ' ' }
				<a
					href={ supportUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Visit our Support Center', 'agent-builder' ) }
				</a>
				{ ' | ' }
				<a href={ docUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Documentation', 'agent-builder' ) }
				</a>
				{ ' | ' }
				<a
					href={ promoUrl }
					target={ promoExternal || f.is_pro ? '_blank' : undefined }
					rel={
						promoExternal || f.is_pro
							? 'noopener noreferrer'
							: undefined
					}
				>
					{ promoLabel }
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
					{ __( 'Terms of Service', 'agent-builder' ) }
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
					{ __( 'Privacy Policy', 'agent-builder' ) }
				</a>
				{ ' | ' }
				<a
					href={
						f.gdpr_url || 'https://agentic-plugin.com/gdpr-policy/'
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'GDPR Policy', 'agent-builder' ) }
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
					title={ __( 'Drag to rearrange', 'agent-builder' ) }
					draggable
					onDragStart={ ( e ) =>
						onDragStart && onDragStart( e, cardId )
					}
					onDragEnd={ onDragEnd }
					role="button"
					tabIndex={ 0 }
					aria-label={ __(
						'Drag to rearrange dashboard card',
						'agent-builder'
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
			title={ __( 'Status', 'agent-builder' ) }
			headerLink={
				data.is_pro ? (
					<a
						className="agentic-card-header-link"
						href={ data.urls?.license }
					>
						{ __( 'Manage License →', 'agent-builder' ) }
					</a>
				) : null
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'License', 'agent-builder' ) }>
					{ licClass ? (
						<span className={ licClass }>
							● { lic.label }
						</span>
					) : (
						lic.label
					) }
					{ lic.class === 'error' && (
						<>
							{ ' ' }
							<a
								href={ data.urls?.pricing }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Renew', 'agent-builder' ) }
							</a>
						</>
					) }
				</StatusTile>
				<StatusTile label={ __( 'Version', 'agent-builder' ) }>
					{ data.version }
				</StatusTile>
				<StatusTile
					label={ __( 'Schema', 'agent-builder' ) }
					tip={ __(
						'The version of Agent Builder’s database structure. Updates automatically when needed — you don’t need to do anything here.',
						'agent-builder'
					) }
				>
					<span className="agentic-status-active">●</span>{ ' ' }
					{ data.schema_version }
				</StatusTile>
				<StatusTile label={ __( 'Pending Jobs', 'agent-builder' ) }>
					<span className={ pendClass }>●</span>{ ' ' }
					{ jobs.pending ?? 0 }
					{ jobs.abandoned > 0 && (
						<span className="agentic-status-expiring">
							{ ' ' }({ jobs.abandoned }{ ' ' }
							{ __(
								'abandoned — auto-recovered',
								'agent-builder'
							) }
							)
						</span>
					) }
				</StatusTile>
				<StatusTile label={ __( 'Running Jobs', 'agent-builder' ) }>
					<span className={ runClass }>●</span>{ ' ' }
					{ jobs.processing ?? 0 }
					{ jobs.stuck > 0 && (
						<span className="agentic-status-error">
							{ ' ' }({ jobs.stuck }{ ' ' }
							{ __( 'stuck — auto-recovered', 'agent-builder' ) })
						</span>
					) }
				</StatusTile>
				<StatusTile label={ __( 'Completed Jobs', 'agent-builder' ) }>
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
			title={ __( 'Approvals & Backups', 'agent-builder' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.approvals }
				>
					{ __( 'View Approvals →', 'agent-builder' ) }
				</a>
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'Pending Approvals', 'agent-builder' ) }>
					<a href={ data.urls?.approvals }>
						<span className={ pendingClass }>●</span>{ ' ' }
						{ formatInt( pending ) }
					</a>
				</StatusTile>
				<StatusTile label={ __( 'Completed Approvals', 'agent-builder' ) }>
					<a href={ data.urls?.approvals }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( safety.completed_approvals ) }
					</a>
				</StatusTile>
			</div>
			<div className="agentic-status-grid agentic-mt-8">
				<StatusTile label={ __( 'Files Backed Up', 'agent-builder' ) }>
					<a href={ data.urls?.backups }>
						<span className="agentic-status-active">●</span>{ ' ' }
						{ formatInt( safety.file_backups ) }
					</a>
				</StatusTile>
				<StatusTile label={ __( 'DB Tables Backed Up', 'agent-builder' ) }>
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
						__( '%s waiting for your OK.', 'agent-builder' ),
						formatInt( pending )
					) }
				</p>
			) }
			<p className="agentic-text-muted">
				<a href={ data.urls?.safety_center || '#' }>
					{ __( 'Open Safety Center →', 'agent-builder' ) }
				</a>
			</p>
		</Card>
	);
}

function AgentReadyCard( { data, dnd } ) {
	const ready = data.agent_ready || {};
	const overall = Number( ready.overall || 0 );
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
			title={ __( 'Site Passport', 'agent-builder' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.agent_ready }
				>
					{ __( 'View Details →', 'agent-builder' ) }
				</a>
			}
		>
			<div className="agentic-status-grid">
				<StatusTile label={ __( 'Site Score', 'agent-builder' ) }>
					<span className={ gradeClass }>●</span>{ ' ' }
					{ overall } ({ ready.grade || '—' })
				</StatusTile>
			</div>
			{ ready.top_fix && (
				<p className="agentic-text-muted">
					{ ready.top_fix.detail }
					{ ' ' }
					<a href={ data.urls?.agent_ready }>
						{ __( 'Fix now →', 'agent-builder' ) }
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
			title={ __( 'Activity', 'agent-builder' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.activity }
				>
					{ __( 'View Activity →', 'agent-builder' ) }
				</a>
			}
		>
			<div className="agentic-metrics-grid">
				<Metric
					label={ __( 'Total Actions', 'agent-builder' ) }
					value={ formatInt( a.actions ) }
					href={ data.urls?.activity }
				/>
				<Metric
					label={ __( 'Tokens Used', 'agent-builder' ) }
					value={ formatInt( a.tokens ) }
					href={ usageHref }
				/>
				{ Number( a.cost ) > 0 && (
					<Metric
						label={
							data.is_pro
								? __( 'Est. Cost', 'agent-builder' )
								: __( 'Estimated Usage', 'agent-builder' )
						}
						value={ '$' + Number( a.cost ).toFixed( 4 ) }
						href={ usageHref }
					/>
				) }
				<Metric
					label={ __( 'Active Agents', 'agent-builder' ) }
					value={ formatInt( agents.active ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
				<Metric
					label={ __( 'Uploaded Agents', 'agent-builder' ) }
					value={ formatInt( agents.uploaded ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
				<Metric
					label={ __( 'User-Created Agents', 'agent-builder' ) }
					value={ formatInt( agents.user_created ) }
					href={ data.urls?.admin + 'admin.php?page=agentic-agents' }
				/>
				{ agents.community > 0 && (
					<Metric
						label={ __( 'Community Agents', 'agent-builder' ) }
						value={ formatInt( agents.community ) }
						href={ data.urls?.community }
					/>
				) }
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
							__( 'Test failed.', 'agent-builder' ),
					},
				} ) )
			);
	};

	return (
		<Card
			cardId="providers"
			{ ...dnd }
			title={ __( 'Connected Providers', 'agent-builder' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.providers }
				>
					{ __( 'Manage Providers →', 'agent-builder' ) }
				</a>
			}
		>
			{ ! rows.length ? (
				<p className="agentic-mt-12">
					{ __( 'No AI providers connected.', 'agent-builder' ) }{ ' ' }
					<a href={ data.urls?.admin + 'admin.php?page=agentic-setup' }>
						{ __( 'Run the Setup Wizard', 'agent-builder' ) }
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
												'agent-builder'
											) }
										</span>
										<span className="agentic-provider-name">
											{ p.name }
										</span>
									</div>
									<div className="agentic-provider-line">
										<span className="agentic-provider-k">
											{ __( 'Model', 'agent-builder' ) }
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
										{ __( 'Default', 'agent-builder' ) }
									</span>
								) }
								<button
									type="button"
									className="button button-small"
									disabled={ !! t?.testing }
									onClick={ () => testProvider( p.slug ) }
								>
									{ t?.testing
										? __( 'Testing…', 'agent-builder' )
										: __( 'Test', 'agent-builder' ) }
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
					'agent-builder'
			  )
			: __( 'Turn off emergency stop?', 'agent-builder' );
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
							'agent-builder'
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
			title={ __( 'Quick Actions', 'agent-builder' ) }
			headerLink={
				<button
					type="button"
					className="agentic-card-header-link"
					onClick={ () => setManage( ( m ) => ! m ) }
				>
					{ __( 'Manage Actions →', 'agent-builder' ) }
				</button>
			}
		>
			{ ! data.is_configured && (
				<p className="agentic-mb-12">
					{ __( 'Chatbot offline —', 'agent-builder' ) }{ ' ' }
					<a href={ data.urls?.settings }>
						{ __( 'Configure now', 'agent-builder' ) }
					</a>
				</p>
			) }

			{ manage && (
				<div className="agentic-manage-actions-panel">
					<p className="description agentic-mb-12">
						{ __(
							'Choose which links appear in Quick Actions. Setup Wizard is always available.',
							'agent-builder'
						) }
					</p>
					<ul className="agentic-manage-actions-list">
						{ catalog.map( ( item ) => {
							const notes = [];
							if ( item.advanced ) {
								notes.push( __( 'Advanced', 'agent-builder' ) );
							}
							if ( item.pro ) {
								notes.push( __( 'Pro', 'agent-builder' ) );
							}
							if ( item.locked ) {
								notes.push( __( 'Required', 'agent-builder' ) );
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
								? __( 'Saving…', 'agent-builder' )
								: __( 'Save Quick Actions', 'agent-builder' ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ () => setManage( false ) }
						>
							{ __( 'Cancel', 'agent-builder' ) }
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
						{ __( 'Disable All Agents', 'agent-builder' ) }
						{ data.emergency_stop && (
							<span className="agentic-emergency-badge">
								{ __( 'ACTIVE', 'agent-builder' ) }
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
						{ __( 'Emergency stop', 'agent-builder' ) }
					</strong>
					{ __(
						': deactivate and log agent states, cancels all jobs and disconnects providers.',
						'agent-builder'
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
			title={ __( 'Interface Settings', 'agent-builder' ) }
			headerLink={
				<a
					className="agentic-card-header-link"
					href={ data.urls?.interface }
				>
					{ __( 'Manage Interface →', 'agent-builder' ) }
				</a>
			}
			className="agentic-settings-card"
		>
			<div className="agentic-interface-section agentic-mode-toggle">
				<span className="agentic-mode-label">
					{ __( 'Default interface:', 'agent-builder' ) }
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
					{ __( 'Basic', 'agent-builder' ) }
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
					{ __( 'Advanced', 'agent-builder' ) }
				</button>
				<span className="agentic-mode-hint agentic-text-muted">
					{ __(
						'Used by any screen you have not set individually. Tools, Approvals, and Activity each have their own Basic/Advanced switch now.',
						'agent-builder'
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
									'agent-builder'
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
						'agent-builder'
					) }
				</button>
			</div>

			{ data.has_agent_updates_class ? (
				<div className="agentic-interface-section agentic-updates-toggle">
					<div className="agentic-updates-row">
						<span className="agentic-mode-label">
							{ __(
								'Automatic Agent Updates',
								'agent-builder'
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
							'agent-builder'
						) }
					</span>
				</div>
			) : (
				<div className="agentic-interface-section agentic-updates-toggle">
					<div className="agentic-updates-row">
						<span className="agentic-mode-label">
							{ __( 'Community Agents', 'agent-builder' ) }
						</span>
						<a
							className="button button-secondary"
							href={
								data.urls?.community ||
								'https://agentic-plugin.com/community-agents/'
							}
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Browse →', 'agent-builder' ) }
						</a>
					</div>
					<span className="agentic-mode-hint agentic-text-muted">
						{ __(
							'Discover and install agents created by the WordPress community.',
							'agent-builder'
						) }
					</span>
				</div>
			) }
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
			title={ __( 'Getting Started', 'agent-builder' ) }
		>
			<p className="agentic-text-muted agentic-mb-12">
				{ sprintf(
					/* translators: 1: done count, 2: total */
					__( '%1$d of %2$d steps complete', 'agent-builder' ),
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
						__( 'Could not load dashboard.', 'agent-builder' )
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
					<Spinner /> { __( 'Loading dashboard…', 'agent-builder' ) }
				</p>
			</div>
		);
	}

	if ( error || ! data ) {
		return (
			<div className="wrap agentic-admin">
				<Notice status="error" isDismissible={ false }>
					{ error || __( 'Dashboard unavailable.', 'agent-builder' ) }
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
				return <AgentReadyCard key={ id } data={ data } dnd={ dnd } />;
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
					Agent Builder
				</h1>
				<div className="agentic-dashboard-layout-actions">
					{ layoutSaving && (
						<span className="agentic-text-muted">
							{ __( 'Saving layout…', 'agent-builder' ) }
						</span>
					) }
					{ layoutIsCustom && (
						<button
							type="button"
							className="button button-small"
							onClick={ resetLayout }
							disabled={ layoutSaving }
						>
							{ __( 'Reset layout', 'agent-builder' ) }
						</button>
					) }
					<span className="agentic-dashboard-drag-hint agentic-text-muted">
						{ __( 'Drag ⋮⋮ to rearrange cards', 'agent-builder' ) }
					</span>
				</div>
			</div>

			{ data.emergency_stop && (
				<div className="agentic-emergency-banner" role="alert">
					<span>
						<strong>
							{ __(
								'Emergency stop is ACTIVE',
								'agent-builder'
							) }
						</strong>
						{ ' — ' }
						{ __(
							'All agents are deactivated, jobs are cancelled, and LLM providers are disconnected. Chat and agent activation are blocked.',
							'agent-builder'
						) }{ ' ' }
						<a href={ data.urls?.interface }>
							{ __( 'Manage Interface', 'agent-builder' ) }
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
