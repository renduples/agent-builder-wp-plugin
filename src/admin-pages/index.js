/**
 * Multi-page React admin surfaces (tools, skills list, approvals, logs, etc.).
 * Agents list intentionally excluded (WordPress plugins-style UI later).
 */
import { createRoot, useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Spinner,
	Notice,
	ToggleControl,
	SearchControl,
	ExternalLink,
	Modal,
} from '@wordpress/components';
import { AdminPage, Panel, InfoTip, ScreenModeToggle } from '../shared/components';
import { ChatEmbed } from '../shared/chat-embed';

// One-line, plain-language explanation of what each risk level actually
// means in practice — shown via InfoTip next to any raw risk badge.
const RISK_EXPLANATIONS = {
	none: __( 'Safe to run automatically — read-only, no approval needed.', 'agent-builder' ),
	low: __( 'Runs automatically by default; may read data that includes personal information.', 'agent-builder' ),
	medium: __( 'Changes something — agents pause for your in-chat confirmation first.', 'agent-builder' ),
	high: __( 'A significant or bulk change — waits in the Approvals queue for you to allow it.', 'agent-builder' ),
	extreme: __( 'Too risky to allow at all — hidden from agents entirely, cannot be enabled.', 'agent-builder' ),
};

// Per-tool HIGH reasons from reports/m2-safety-center-design.md §3.5.
// Everything else falls back to the HIGH tier sentence in RISK_EXPLANATIONS
// rather than inventing copy for every HIGH tool.
const HIGH_RISK_REASONS = {
	install_plugin_from_url: __( 'installs code on your site', 'agent-builder' ),
	force_password_reset: __( 'can affect account access', 'agent-builder' ),
	wc_create_refund: __( 'moves money', 'agent-builder' ),
	delete_form: __( 'can permanently remove data', 'agent-builder' ),
	git_push: __( 'changes the deployed codebase', 'agent-builder' ),
	git_pull: __( 'changes the deployed codebase', 'agent-builder' ),
	git_commit: __( 'changes the deployed codebase', 'agent-builder' ),
};

function highRiskReason( toolId ) {
	return HIGH_RISK_REASONS[ toolId ] || RISK_EXPLANATIONS.high;
}

// Off→on gate for HIGH/EXTREME. Returns null when the toggle should proceed
// immediately (turning off, or low/medium/none). Consult before the optimistic
// toggle+fetch so the switch never flashes on.
function toolEnableGate( row, enabling ) {
	if ( ! enabling ) {
		return null;
	}
	const risk = ( row.risk_level || 'none' ).toLowerCase();
	if ( risk !== 'high' && risk !== 'extreme' ) {
		return null;
	}
	return {
		name: row.id,
		title: row.title || row.id,
		risk,
	};
}

function ToolRiskEnableModal( { gate, onCancel, onEnable } ) {
	if ( ! gate ) {
		return null;
	}
	const name = gate.title || gate.name;
	const isExtreme = gate.risk === 'extreme';

	return (
		<Modal
			title={
				isExtreme
					? __( 'This tool cannot be enabled', 'agent-builder' )
					: __( 'Enable high-risk tool?', 'agent-builder' )
			}
			onRequestClose={ onCancel }
			className="agentic-tool-risk-modal"
		>
			{ isExtreme ? (
				<>
					<p>
						<strong>{ name }</strong>{ ' ' }
						{ __( 'is marked Extreme Risk.', 'agent-builder' ) }
					</p>
					<p>
						{ __(
							'Extreme-risk tools are hidden from agents entirely and blocked from running, because they are too risky for normal use.',
							'agent-builder'
						) }
					</p>
					<div className="agentic-tool-risk-modal__actions">
						<Button variant="primary" onClick={ onCancel }>
							{ __( 'Close', 'agent-builder' ) }
						</Button>
					</div>
				</>
			) : (
				<>
					<p>
						<strong>{ name }</strong>{ ' ' }
						{ __(
							'can make a significant change to your site.',
							'agent-builder'
						) }
					</p>
					<p>
						{ sprintf(
							/* translators: %s: plain-language reason this tool is high-risk */
							__( 'Why this is high-risk: %s', 'agent-builder' ),
							highRiskReason( gate.name )
						) }
					</p>
					<p>
						{ __(
							'If an agent uses this tool later, the action will still wait for human review in the Approvals queue before it runs.',
							'agent-builder'
						) }
					</p>
					<p className="agentic-react-muted">
						{ __(
							'Enabling this tool makes it available to eligible agents. It does not run the tool immediately.',
							'agent-builder'
						) }
					</p>
					<div className="agentic-tool-risk-modal__actions">
						<Button variant="secondary" onClick={ onCancel }>
							{ __( 'Cancel', 'agent-builder' ) }
						</Button>
						<Button variant="primary" onClick={ onEnable }>
							{ __( 'Enable tool', 'agent-builder' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}

const APPROVAL_ACTION_HINT = __(
	'An agent tried to run this specific action and paused here first. Nothing happens until you decide — approve to let it run once, or reject to cancel it.',
	'agent-builder'
);

function bootConfig() {
	return window.agenticAdminPage || { page: 'tools', tab: '' };
}

// Screens with a Basic/Advanced content split, and thus a ScreenModeToggle
// in AdminPage's top-right actions slot. Must match the screens the
// set_screen_mode REST action recognizes (class-admin-pages-rest.php).
const SCREENS_WITH_MODE = [
	'tools',
	'skills',
	'approvals',
	'logs',
	'agent-ready',
	'safety-center',
];

/**
 * Standard admin footer: policy blurb + support/docs + legal links.
 * Matches PHP agentic-page-footer used on classic admin screens.
 */
function AdminPageFooter( { footer } ) {
	const f = footer || bootConfig().footer || {};
	const docUrl = f.doc_url || 'https://agentic-plugin.com/agent-tools/';
	const supportUrl = f.support_url || 'https://agentic-plugin.com/support/';
	// Default off-site pricing — never a missing admin.php?page=agentic-upgrade-pro.
	const promoUrl =
		f.promo_url || 'https://agentic-plugin.com/pricing/';
	const promoLabel =
		f.promo_label || __( 'Upgrade to Pro', 'agent-builder' );
	const promoExternal =
		typeof f.promo_external === 'boolean'
			? f.promo_external
			: /^https?:\/\//i.test( promoUrl );
	const policy =
		f.policy ||
		__(
			'Agents only use the tools you allow. Higher-risk actions still follow Approvals and your safety settings.',
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
				<a href={ supportUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Visit our Support Center', 'agent-builder' ) }
				</a>
				{ ' | ' }
				<a href={ docUrl } target="_blank" rel="noopener noreferrer">
					{ __( 'Documentation', 'agent-builder' ) }
				</a>
				{ ' | ' }
				<a
					href={ promoUrl }
					target={
						promoExternal || f.is_pro ? '_blank' : undefined
					}
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

function usePageData( page, tab ) {
	const [ state, setState ] = useState( {
		loading: true,
		error: '',
		data: null,
	} );
	// Extra query args (e.g. period for Activity) from the current admin URL.
	const extraQuery = useMemo( () => {
		try {
			const sp = new URLSearchParams( window.location.search );
			const period = sp.get( 'period' );
			return period ? { period } : {};
		} catch ( e ) {
			return {};
		}
	}, [] );

	const reload = useCallback(
		( opts = {} ) => {
			const silent = !! opts.silent;
			if ( ! silent ) {
				setState( ( s ) => ( { ...s, loading: true, error: '' } ) );
			}
			const period =
				opts.period ||
				extraQuery.period ||
				new URLSearchParams( window.location.search ).get( 'period' ) ||
				'';
			let path =
				`agentic/v1/admin-page?page=${ encodeURIComponent( page ) }` +
				( tab ? `&tab=${ encodeURIComponent( tab ) }` : '' );
			if ( period ) {
				path += `&period=${ encodeURIComponent( period ) }`;
			}
			apiFetch( { path } )
				.then( ( data ) =>
					setState( { loading: false, error: '', data } )
				)
				.catch( ( err ) =>
					setState( {
						loading: false,
						error:
							err.message ||
							__( 'Could not load page.', 'agent-builder' ),
						data: silent ? state.data : null,
					} )
				);
		},
		// state.data only used on silent failure fallback — omit from deps to keep reload stable.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ page, tab, extraQuery.period ]
	);

	/** Patch page data in place (no loading flash). */
	const patchData = useCallback( ( updater ) => {
		setState( ( s ) => {
			if ( ! s.data ) {
				return s;
			}
			const next =
				typeof updater === 'function' ? updater( s.data ) : updater;
			return { ...s, data: next };
		} );
	}, [] );

	useEffect( () => {
		reload();
	}, [ reload ] );

	return [ state, reload, setState, patchData ];
}

function TabBar( { tabs, active, className = '' } ) {
	if ( ! tabs?.length ) {
		return null;
	}
	return (
		<nav
			className={ `agentic-react-tabs ${ className }`.trim() }
			aria-label={ __( 'Sections', 'agent-builder' ) }
		>
			{ tabs.map( ( t ) => (
				<a
					key={ t.id }
					href={ t.url }
					className={
						'agentic-react-tabs__tab' +
						( t.id === active ? ' is-active' : '' )
					}
				>
					{ t.label }
				</a>
			) ) }
		</nav>
	);
}

function ToolsBasicProfiles( { data, reload } ) {
	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	const profiles = data.profiles || [];
	const active = data.active_profile || '';

	const apply = ( profileId ) => {
		if ( busy ) {
			return;
		}
		setBusy( profileId );
		setErr( '' );
		setOk( '' );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'apply_tools_profile',
				profile: profileId,
			},
		} )
			.then( ( res ) => {
				const r = res?.result || {};
				setOk(
					sprintf(
						/* translators: 1: enabled count, 2: disabled count */
						__(
							'Profile applied: %1$d tools on, %2$d tools off.',
							'agent-builder'
						),
						r.enabled ?? 0,
						r.disabled ?? 0
					)
				);
				reload( { silent: true } );
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not apply profile.', 'agent-builder' )
				)
			)
			.finally( () => setBusy( '' ) );
	};

	return (
		<div className="agentic-react-tools-basic">
			{ /* data.description already renders once under the page <h1>
			   (AdminPage in shared/components.js) — repeating it here duplicated
			   the same sentence twice in a row for every Basic-mode visitor. */ }
			<p className="agentic-react-muted">
				{ __(
					'These profiles control which tools every agent may use. Approvals still apply for riskier actions.',
					'agent-builder'
				) }{ ' ' }
				{ __(
					'Switch to Advanced (top right) for the full tool-by-tool list.',
					'agent-builder'
				) }
			</p>

			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			{ ok && (
				<Notice status="success" isDismissible>
					{ ok }
				</Notice>
			) }

			{ active === 'custom' && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Tools were customized outside a profile. Choose a card below to reset to a simple safety level.',
						'agent-builder'
					) }
				</Notice>
			) }

			<div className="agentic-react-profile-grid">
				{ profiles.map( ( p ) => (
					<button
						key={ p.id }
						type="button"
						className={
							'agentic-react-profile-card' +
							( p.active || active === p.id
								? ' is-active'
								: '' ) +
							( busy === p.id ? ' is-busy' : '' )
						}
						disabled={ !! busy }
						onClick={ () => apply( p.id ) }
					>
						<span className="agentic-react-profile-card__icon">
							{ p.icon || '•' }
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
						<span className="agentic-react-profile-card__risk">
							<span
								className={
									'agentic-react-risk agentic-react-risk--' +
									( p.max_risk || 'low' )
								}
							>
								{ sprintf(
									/* translators: %s: risk level */
									__( 'Up to %s risk', 'agent-builder' ),
									p.max_risk || 'low'
								) }
							</span>
						</span>
						{ ( p.active || active === p.id ) && (
							<span className="agentic-react-profile-card__badge">
								{ __( 'Current', 'agent-builder' ) }
							</span>
						) }
					</button>
				) ) }
			</div>

			<p className="agentic-react-muted agentic-react-tools-basic__stats">
				{ sprintf(
					/* translators: 1: enabled tools, 2: disabled tools, 3: max risk */
					__(
						'Right now: %1$d tools on, %2$d off · highest enabled risk: %3$s',
						'agent-builder'
					),
					data.enabled_count ?? 0,
					data.disabled_count ?? 0,
					data.enabled_max_risk || 'none'
				) }
			</p>
		</div>
	);
}

function ToolsView( { data, reload, patchData } ) {
	const [ q, setQ ] = useState( '' );
	const [ riskFilter, setRiskFilter ] = useState( 'all' );
	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ gate, setGate ] = useState( null );
	const activeTab = data.tab || 'all';
	const isAdvanced = !! data.is_advanced;
	const categoryHref = ( slug ) => {
		const found = ( data.tabs || [] ).find( ( t ) => t.id === slug );
		return (
			found?.url ||
			`admin.php?page=agentic-tools&tab=${ encodeURIComponent( slug ) }`
		);
	};

	// Hooks must run unconditionally on every render (Rules of Hooks) — this
	// screen can now flip basic/advanced in place via ScreenModeToggle
	// without a full page reload (previously it always required navigating
	// away and back, which remounted the component fresh and masked any
	// hook ordered after the early return below).
	const riskCounts = useMemo( () => {
		const counts = {
			all: 0,
			none: 0,
			low: 0,
			medium: 0,
			high: 0,
			extreme: 0,
		};
		( data.rows || [] ).forEach( ( r ) => {
			const risk = ( r.risk_level || 'none' ).toLowerCase();
			counts.all += 1;
			if ( counts[ risk ] !== undefined ) {
				counts[ risk ] += 1;
			} else {
				counts.none += 1;
			}
		} );
		return counts;
	}, [ data.rows ] );

	const riskFilters = useMemo(
		() => [
			{ id: 'all', label: __( 'All risks', 'agent-builder' ) },
			{ id: 'none', label: __( 'None', 'agent-builder' ) },
			{ id: 'low', label: __( 'Low', 'agent-builder' ) },
			{ id: 'medium', label: __( 'Medium', 'agent-builder' ) },
			{ id: 'high', label: __( 'High', 'agent-builder' ) },
			{ id: 'extreme', label: __( 'Extreme', 'agent-builder' ) },
		],
		[]
	);

	const rows = useMemo( () => {
		let list = data.rows || [];
		if ( riskFilter !== 'all' ) {
			list = list.filter( ( r ) => {
				const risk = ( r.risk_level || 'none' ).toLowerCase();
				return risk === riskFilter;
			} );
		}
		const n = q.trim().toLowerCase();
		if ( ! n ) {
			return list;
		}
		return list.filter( ( r ) =>
			[
				r.title,
				r.subtitle,
				r.category,
				r.category_label,
				r.id,
				r.risk_level,
			]
				.join( ' ' )
				.toLowerCase()
				.includes( n )
		);
	}, [ data.rows, q, riskFilter ] );

	const setRowEnabled = ( name, enabled ) => {
		if ( typeof patchData === 'function' ) {
			patchData( ( d ) => ( {
				...d,
				rows: ( d.rows || [] ).map( ( r ) =>
					r.id === name ? { ...r, enabled } : r
				),
			} ) );
		}
	};

	const toggle = ( name, enabled ) => {
		setBusy( name );
		setErr( '' );
		// Optimistic: flip the switch without a full-page reload/spinner.
		setRowEnabled( name, enabled );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: {
				action_name: 'toggle_tool',
				name,
				enabled,
			},
		} )
			.then( () => {
				// Background refresh keeps counts/tabs in sync; silent = no jump.
				if ( typeof reload === 'function' ) {
					reload( { silent: true } );
				}
			} )
			.catch( ( e ) => {
				setRowEnabled( name, ! enabled );
				setErr(
					e.message || __( 'Toggle failed.', 'agent-builder' )
				);
			} )
			.finally( () => setBusy( '' ) );
	};

	const requestToggle = ( row, enabled ) => {
		const pending = toolEnableGate( row, enabled );
		if ( pending ) {
			setGate( pending );
			return;
		}
		toggle( row.id, enabled );
	};

	const riskModal = (
		<ToolRiskEnableModal
			gate={ gate }
			onCancel={ () => setGate( null ) }
			onEnable={ () => {
				const pending = gate;
				setGate( null );
				if ( pending && pending.risk !== 'extreme' ) {
					toggle( pending.name, true );
				}
			} }
		/>
	);

	// Basic Interface mode: simple ability profiles only (not the tool table).
	if ( ! isAdvanced ) {
		return (
			<>
				<ToolsBasicProfiles data={ data } reload={ reload } />
				{ riskModal }
			</>
		);
	}

	return (
		<>
			<TabBar tabs={ data.tabs } active={ activeTab } className="agentic-react-tabs--tools" />
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }

			<>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'You are in Advanced view (full tool list).',
						'agent-builder'
					) }
				</p>
				<div className="agentic-react-tools-toolbar">
					<SearchControl
						value={ q }
						onChange={ setQ }
						placeholder={ __( 'Search tools…', 'agent-builder' ) }
						__nextHasNoMarginBottom
					/>
					<span className="agentic-react-muted">
						{ rows.length === 1
							? __( '1 tool', 'agent-builder' )
							: `${ rows.length } ${ __(
									'tools',
									'agent-builder'
							  ) }` }
					</span>
				</div>
				<nav
						className="agentic-react-risk-filters"
						aria-label={ __( 'Filter by risk', 'agent-builder' ) }
					>
						{ riskFilters.map( ( f ) => {
							const count = riskCounts[ f.id ] ?? 0;
							// Hide empty risk levels except "all" and the active filter.
							if (
								f.id !== 'all' &&
								f.id !== riskFilter &&
								count === 0
							) {
								return null;
							}
							return (
								<button
									key={ f.id }
									type="button"
									className={
										'agentic-react-risk-filters__btn' +
										( f.id !== 'all'
											? ` agentic-react-risk--${ f.id }`
											: '' ) +
										( riskFilter === f.id
											? ' is-active'
											: '' )
									}
									onClick={ () => setRiskFilter( f.id ) }
									aria-pressed={ riskFilter === f.id }
								>
									{ f.label }
									<span className="agentic-react-risk-filters__count">
										{ count }
									</span>
								</button>
							);
						} ) }
					</nav>
					{ ! rows.length ? (
						<p className="agentic-react-muted">
							{ q || riskFilter !== 'all'
								? __(
										'No tools match this search or risk filter.',
										'agent-builder'
								  )
								: __(
										'No tools in this category.',
										'agent-builder'
								  ) }
						</p>
					) : (
						<div className="agentic-react-table-wrap">
							<table className="agentic-react-table">
								<thead>
									<tr>
										<th>
											{ __( 'Enabled', 'agent-builder' ) }
										</th>
										<th>{ __( 'Tool', 'agent-builder' ) }</th>
										{ activeTab === 'all' && (
											<th>
												{ __(
													'Category',
													'agent-builder'
												) }
											</th>
										) }
										<th>
											{ __( 'Risk', 'agent-builder' ) }
										</th>
										<th>
											{ __( 'Source', 'agent-builder' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ rows.map( ( r ) => (
										<tr
											key={ r.id }
											style={ {
												opacity: r.enabled ? 1 : 0.6,
											} }
										>
											<td style={ { width: 90 } }>
												<ToggleControl
													label={
														<span className="screen-reader-text">
															{ sprintf(
																/* translators: %s: tool name */
																__(
																	'Enabled: %s',
																	'agent-builder'
																),
																r.title || r.id
															) }
														</span>
													}
													checked={ !! r.enabled }
													disabled={ busy === r.id }
													onChange={ ( v ) =>
														requestToggle( r, v )
													}
													__nextHasNoMarginBottom
												/>
											</td>
											<td>
												<strong>{ r.title }</strong>
												{ r.subtitle && (
													<div className="agentic-react-muted">
														{ r.subtitle.slice(
															0,
															140
														) }
														{ r.subtitle.length >
														140
															? '…'
															: '' }
													</div>
												) }
											</td>
											{ activeTab === 'all' && (
												<td>
													<a
														href={ categoryHref(
															r.category
														) }
													>
														{ r.category_label ||
															r.category }
													</a>
												</td>
											) }
											<td>
												<span
													className={
														'agentic-react-risk agentic-react-risk--' +
														( r.risk_level ||
															'none' )
													}
												>
													{ r.risk_level ||
														__(
															'none',
															'agent-builder'
														) }
												</span>
												<InfoTip
													text={
														RISK_EXPLANATIONS[
															r.risk_level ||
																'none'
														]
													}
												/>
											</td>
											<td>{ r.source }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</>
			{ riskModal }
			</>
		);
	}

function SkillsView( { data, reload } ) {
	const [ q, setQ ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const isAdvanced = !! data.is_advanced;
	const rows = useMemo( () => {
		const all = data.rows || [];
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

	return (
		<>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
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
			</div>
			<div style={ { marginBottom: 16 } }>
				<SearchControl
					value={ q }
					onChange={ setQ }
					__nextHasNoMarginBottom
				/>
			</div>
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
		</>
	);
}

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

	return (
		<>
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

function LogsView( { data, reload } ) {
	const [ q, setQ ] = useState( '' );
	const [ kind, setKind ] = useState( 'all' );
	const period = data.period || 'week';
	const stats = data.stats || {};

	const kindCounts = useMemo( () => {
		const c = { all: 0, tool: 0, approval: 0, chat: 0, settings: 0, other: 0, security: 0 };
		( data.rows || [] ).forEach( ( r ) => {
			const k = r.kind || 'other';
			c.all += 1;
			if ( c[ k ] !== undefined ) {
				c[ k ] += 1;
			} else {
				c.other += 1;
			}
		} );
		return c;
	}, [ data.rows ] );

	const rows = useMemo( () => {
		let list = data.rows || [];
		if ( kind !== 'all' ) {
			list = list.filter( ( r ) => ( r.kind || 'other' ) === kind );
		}
		const n = q.trim().toLowerCase();
		if ( ! n ) {
			return list;
		}
		return list.filter( ( r ) =>
			[ r.title, r.subtitle, r.detail, r.raw_action, r.kind ]
				.join( ' ' )
				.toLowerCase()
				.includes( n )
		);
	}, [ data.rows, kind, q ] );

	const setPeriod = ( p ) => {
		const url = new URL( window.location.href );
		url.searchParams.set( 'period', p );
		window.history.replaceState( {}, '', url.toString() );
		reload( { period: p } );
	};

	return (
		<>
			{ /* data.description already renders once under the page <h1>
			 * (see AdminPage in shared/components.js) — repeating it here
			 * as a lead paragraph duplicated the same sentence twice in a
			 * row on this screen. */ }
			<p className="agentic-react-muted" style={ { marginTop: 0 } }>
				{ __(
					'This is a friendly activity feed. Technical names stay in Advanced detail when useful.',
					'agent-builder'
				) }
			</p>

			{ data.tab === 'audit' && data.integrity && (
				<p className="agentic-react-muted">
					{ __( 'Log integrity:', 'agent-builder' ) }{ ' ' }
					<span
						className={
							'agentic-react-badge' +
							( data.integrity.valid ? '' : ' agentic-react-badge--danger' )
						}
						title={
							data.integrity.valid
								? sprintf(
										/* translators: %d: number of log entries checked */
										__( '%d entries checked, chain intact.', 'agent-builder' ),
										data.integrity.checked || 0
								  )
								: sprintf(
										/* translators: %d: id of the first entry where the hash chain broke */
										__( 'Chain broken at entry #%d — an entry was edited or deleted after the fact.', 'agent-builder' ),
										data.integrity.broken_at_id || 0
								  )
						}
					>
						{ data.integrity.valid
							? __( 'Verified', 'agent-builder' )
							: __( 'Tampering detected', 'agent-builder' ) }
					</span>
				</p>
			) }

			{ /* Summary metrics */ }
			<div className="agentic-react-activity-stats">
				<div className="agentic-react-activity-stat">
					<span className="agentic-react-activity-stat__val">
						{ stats.total ?? rows.length }
					</span>
					<span className="agentic-react-activity-stat__lbl">
						{ __( 'Events', 'agent-builder' ) }
					</span>
				</div>
				{ data.tab === 'audit' && (
					<>
						<div className="agentic-react-activity-stat">
							<span className="agentic-react-activity-stat__val">
								{ stats.tools ?? 0 }
							</span>
							<span className="agentic-react-activity-stat__lbl">
								{ __( 'Tools used', 'agent-builder' ) }
							</span>
						</div>
						<div className="agentic-react-activity-stat">
							<span className="agentic-react-activity-stat__val">
								{ stats.approvals ?? 0 }
							</span>
							<span className="agentic-react-activity-stat__lbl">
								{ __( 'Approvals', 'agent-builder' ) }
							</span>
						</div>
						{ Number( stats.tokens ) > 0 && (
							<div className="agentic-react-activity-stat">
								<span className="agentic-react-activity-stat__val">
									{ Number( stats.tokens ).toLocaleString() }
								</span>
								<span className="agentic-react-activity-stat__lbl">
									{ __( 'Tokens', 'agent-builder' ) }
								</span>
							</div>
						) }
					</>
				) }
			</div>

			<TabBar tabs={ data.tabs } active={ data.tab } />

			{ /* Period */ }
			<nav
				className="agentic-react-risk-filters"
				aria-label={ __( 'Time period', 'agent-builder' ) }
			>
				{ ( data.period_options || [] ).map( ( p ) => (
					<button
						key={ p.id }
						type="button"
						className={
							'agentic-react-risk-filters__btn' +
							( period === p.id ? ' is-active' : '' )
						}
						onClick={ () => setPeriod( p.id ) }
						aria-pressed={ period === p.id }
					>
						{ p.label }
					</button>
				) ) }
			</nav>

			{ data.tab === 'audit' && (
				<nav
					className="agentic-react-risk-filters"
					aria-label={ __( 'Activity type', 'agent-builder' ) }
				>
					{ ( data.kind_filters || [] ).map( ( f ) => {
						const count = kindCounts[ f.id ] ?? 0;
						if ( f.id !== 'all' && f.id !== kind && count === 0 ) {
							return null;
						}
						return (
							<button
								key={ f.id }
								type="button"
								className={
									'agentic-react-risk-filters__btn' +
									( kind === f.id ? ' is-active' : '' )
								}
								onClick={ () => setKind( f.id ) }
								aria-pressed={ kind === f.id }
							>
								{ f.label }
								<span className="agentic-react-risk-filters__count">
									{ count }
								</span>
							</button>
						);
					} ) }
				</nav>
			) }

			<div className="agentic-react-tools-toolbar">
				<SearchControl
					value={ q }
					onChange={ setQ }
					placeholder={ __( 'Search activity…', 'agent-builder' ) }
					__nextHasNoMarginBottom
				/>
				<span className="agentic-react-muted">
					{ rows.length === 1
						? __( '1 event', 'agent-builder' )
						: `${ rows.length } ${ __( 'events', 'agent-builder' ) }` }
				</span>
				{ data.export_url && (
					<Button
						variant="secondary"
						href={ data.export_url }
						title={ __(
							'Download this log as a CSV file — handy to attach when emailing support about an issue.',
							'agent-builder'
						) }
					>
						{ __( 'Export CSV', 'agent-builder' ) }
					</Button>
				) }
			</div>

			{ ! rows.length ? (
				<div className="agentic-react-approvals-empty">
					<p>
						<strong>
							{ __( 'Nothing here yet', 'agent-builder' ) }
						</strong>
					</p>
					<p className="agentic-react-muted">
						{ q || kind !== 'all'
							? __(
									'No events match this filter. Try another period or clear search.',
									'agent-builder'
							  )
							: __(
									'When agents chat or use tools, their activity will show up here.',
									'agent-builder'
							  ) }
					</p>
				</div>
			) : (
				<ul className="agentic-react-activity-timeline">
					{ rows.map( ( r ) => (
						<li
							key={ r.id || r.when + r.title }
							className={
								'agentic-react-activity-item kind-' +
								( r.kind || 'other' )
							}
						>
							<span
								className="agentic-react-activity-item__icon"
								aria-hidden="true"
							>
								{ r.icon || '•' }
							</span>
							<div className="agentic-react-activity-item__body">
								<div className="agentic-react-activity-item__title">
									<strong>{ r.title }</strong>
									{ r.kind && (
										<span
											className={
												'agentic-react-activity-kind agentic-react-activity-kind--' +
												r.kind
											}
										>
											{ r.kind }
										</span>
									) }
								</div>
								<div className="agentic-react-muted">
									{ r.subtitle ? `${ r.subtitle } · ` : '' }
									{ r.when_human || r.when }
								</div>
								{ r.detail && (
									<p className="agentic-react-activity-item__detail">
										{ r.detail }
									</p>
								) }
								{ data.is_advanced && r.raw_action && (
									<code className="agentic-react-activity-item__raw">
										{ r.raw_action }
									</code>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) }
		</>
	);
}

function DeploymentView( { data } ) {
	return (
		<>
			<p className="agentic-react-lead">{ data.description }</p>
			<div
				style={ {
					display: 'grid',
					gap: 12,
					gridTemplateColumns:
						'repeat(auto-fill, minmax(220px, 1fr))',
				} }
			>
				{ ( data.links || [] ).map( ( link ) => (
					<a
						key={ link.url }
						href={ link.url }
						className="agentic-react-panel components-card"
						style={ {
							display: 'block',
							padding: 16,
							textDecoration: 'none',
							color: 'inherit',
							border: '1px solid #e0e0e0',
							borderRadius: 8,
						} }
					>
						<strong style={ { color: '#2271b1' } }>
							{ link.label }
						</strong>
						<div className="agentic-react-muted">{ link.hint }</div>
					</a>
				) ) }
			</div>
			{ data.legacy_note && (
				<p className="agentic-react-muted" style={ { marginTop: 16 } }>
					{ data.legacy_note }
				</p>
			) }
		</>
	);
}

function UpgradeView( { data } ) {
	return (
		<>
			<p className="agentic-react-lead">{ data.description }</p>
			<ul style={ { marginTop: 0 } }>
				{ ( data.features || [] ).map( ( f ) => (
					<li key={ f }>{ f }</li>
				) ) }
			</ul>
			{ data.is_pro ? (
				<p>
					<span className="agentic-react-badge">
						{ __( 'Pro active', 'agent-builder' ) }
					</span>
				</p>
			) : (
				<p>
					<Button variant="primary" href={ data.pricing_url }>
						{ __( 'View pricing', 'agent-builder' ) }
					</Button>
				</p>
			) }
		</>
	);
}

function TrainView( { data } ) {
	const concepts = data.concepts || [];
	return (
		<>
			<TabBar tabs={ data.tabs } active={ data.tab } />
			<p className="agentic-react-lead">{ data.description }</p>
			<p>
				<Button variant="primary" href={ data.manage_url }>
					{ __( 'Open full Knowledge editor', 'agent-builder' ) }
				</Button>
			</p>
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table">
					<thead>
						<tr>
							<th>{ __( 'Concept', 'agent-builder' ) }</th>
							<th>{ __( 'Type', 'agent-builder' ) }</th>
							<th>{ __( 'Status', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ concepts.map( ( c ) => (
							<tr key={ c.id }>
								<td>
									<strong>{ c.title }</strong>
									{ c.example && (
										<span className="agentic-react-muted">
											{ ' ' }
											(example)
										</span>
									) }
								</td>
								<td>
									<code>{ c.type || '—' }</code>
								</td>
								<td>{ c.status || '—' }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}

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

// Checks whose only fix path today is Pro's AI Radar agent — everything else
// non-fixable (currently just commerce_readiness) has no fix UI at all yet,
// so it must not show the AI Radar upsell, which can't fix it either. See
// class-agent-ready-score.php's check_commerce_readiness() docblock.
const PRO_FIXABLE_CHECKS = [ 'llms_txt_present', 'robots_ai_directives', 'schema_org_present' ];

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
					) : PRO_FIXABLE_CHECKS.includes( id ) ? (
						<Button variant="link" href="https://agentic-plugin.com/pricing/" target="_blank">
							{ __( 'Fix this with AI Radar (Pro) →', 'agent-builder' ) }
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
							'When an agent wants to make an important change, it stops here first. Nothing runs until you approve it.',
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

function AdminPagesApp() {
	const cfg = bootConfig();
	const page = cfg.page || 'tools';
	const tab = cfg.tab || '';
	const [ state, reload, , patchData ] = usePageData( page, tab );

	const footer = cfg.footer || {};

	if ( state.loading ) {
		return (
			<div className="agentic-admin">
				<p>
					<Spinner /> { __( 'Loading…', 'agent-builder' ) }
				</p>
				<AdminPageFooter footer={ footer } />
			</div>
		);
	}

	if ( state.error || ! state.data ) {
		return (
			<div className="agentic-admin">
				<Notice status="error" isDismissible={ false }>
					{ state.error || __( 'Unavailable.', 'agent-builder' ) }
				</Notice>
				<AdminPageFooter footer={ footer } />
			</div>
		);
	}

	const data = state.data;
	// Prefer page-specific docs when payload includes them.
	const pageFooter = {
		...footer,
		...( data.docs_url
			? { doc_url: data.docs_url }
			: {} ),
		...( data.footer_policy
			? { policy: data.footer_policy }
			: {} ),
	};
	let body = null;
	switch ( data.page ) {
		case 'tools':
			body = (
				<ToolsView
					data={ data }
					reload={ reload }
					patchData={ patchData }
				/>
			);
			break;
		case 'skills':
			body = data.is_advanced ? (
				<SkillsView data={ data } reload={ reload } />
			) : (
				<ChatEmbed
					assistant={ data.assistant }
					deploymentContext="admin_page_skills"
					className="agentic-skills-chat-embed"
				/>
			);
			break;
		case 'approvals':
			body = <ApprovalsView data={ data } reload={ reload } />;
			break;
		case 'logs':
			body = <LogsView data={ data } reload={ reload } />;
			break;
		case 'deployment':
			body = <DeploymentView data={ data } />;
			break;
		case 'upgrade-pro':
			body = <UpgradeView data={ data } />;
			break;
		case 'train-data':
			body = <TrainView data={ data } />;
			break;
		case 'agent-ready':
			body = <AgentReadyView data={ data } reload={ reload } />;
			break;
		case 'safety-center':
			body = (
				<SafetyCenterView data={ data } reload={ reload } />
			);
			break;
		default:
			body = (
				<p>{ __( 'Unknown page.', 'agent-builder' ) }</p>
			);
	}

	const panelTitle =
		data.panel_title || data.title || __( 'Tools', 'agent-builder' );

	// The Skills chat embed already brings its own bordered container/header
	// (assets/css/chat.css) — wrapping it in the plain white Panel card too
	// would double-box it, so it renders directly instead.
	const skipPanel =
		( 'skills' === data.page && ! data.is_advanced ) ||
		'safety-center' === data.page;

	// Every screen with a Basic/Advanced content split gets the same switch
	// in the same top-right spot, so the control's location stays familiar
	// as users move between pages instead of living inline in body copy.
	const headerActions = SCREENS_WITH_MODE.includes( data.page ) ? (
		<ScreenModeToggle
			screen={ data.page }
			isAdvanced={ data.is_advanced }
			onChanged={ () => reload( { silent: true } ) }
		/>
	) : null;

	// Outer .wrap is provided by PHP so the shared admin footer can attach.
	return (
		<div className="agentic-admin">
			<AdminPage
				title={ data.title }
				description={ data.description }
				actions={ headerActions }
				wide={ 'safety-center' !== data.page }
			>
				{ skipPanel ? (
					body
				) : (
					<Panel title={ panelTitle }>{ body }</Panel>
				) }
			</AdminPage>
			<AdminPageFooter footer={ pageFooter } />
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( 'agentic-admin-pages-root' );
	if ( el ) {
		createRoot( el ).render( <AdminPagesApp /> );
	}
} );
