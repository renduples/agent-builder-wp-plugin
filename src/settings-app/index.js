/**
 * Agent Builder Settings — full React app (WordPress components).
 */
import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	SelectControl,
	RadioControl,
	Spinner,
	Notice,
	ExternalLink,
	BaseControl,
} from '@wordpress/components';
import {
	AdminPage,
	Panel,
	SaveBar,
	StatusNotice,
	Section,
	ScreenModeToggle,
} from '../shared/components';
import { ChatEmbed } from '../shared/chat-embed';

const REST = 'agentic/v1/admin-settings';

function useSettingsBootstrap() {
	const [ state, setState ] = useState( {
		loading: true,
		error: '',
		bootstrap: null,
	} );

	useEffect( () => {
		apiFetch( { path: REST } )
			.then( ( bootstrap ) =>
				setState( { loading: false, error: '', bootstrap } )
			)
			.catch( ( err ) =>
				setState( {
					loading: false,
					error:
						err.message ||
						__( 'Could not load settings.', 'agent-builder' ),
					bootstrap: null,
				} )
			);
	}, [] );

	return [ state, setState ];
}

function saveTab( tab, data ) {
	return apiFetch( {
		path: REST,
		method: 'POST',
		data: { tab, data },
	} );
}

/**
 * Standard admin footer: policy blurb + Support / Documentation / promo + legal.
 * Docs URL follows the active tab via agenticSettingsBoot.footerByTab.
 */
function SettingsPageFooter( { tab } ) {
	const boot =
		( typeof window !== 'undefined' && window.agenticSettingsBoot ) || {};
	const byTab = boot.footerByTab || {};
	const f = ( tab && byTab[ tab ] ) || boot.footer || {};
	const docUrl = f.doc_url || 'https://agentic-plugin.com/settings/';
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
			'Settings control how agents behave on this site. Changes are stored locally and take effect for new conversations.',
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

function SettingsNav( { bootstrap, active, onChange, filter } ) {
	const q = ( filter || '' ).toLowerCase();
	const tabs = bootstrap.tabs || {};
	const groups = bootstrap.groups || [];
	const settingsAdvanced = !! bootstrap.is_settings_advanced;
	const advancedOnly = Array.isArray( bootstrap.advanced_only_tabs )
		? bootstrap.advanced_only_tabs
		: [ 'apis', 'endpoints', 'mcp' ];
	const onAdvancedTab = advancedOnly.includes( active );
	const [ hatchBusy, setHatchBusy ] = useState( false );

	const setSettingsMode = ( mode ) => {
		if ( hatchBusy ) {
			return;
		}
		setHatchBusy( true );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'set_screen_mode', screen: 'settings', mode },
		} )
			.then( () =>
				onChange( {
					type: 'settings-mode',
					value: mode,
				} )
			)
			.finally( () => setHatchBusy( false ) );
	};

	return (
		<aside className="agentic-settings-app__nav">
			<input
				type="search"
				className="agentic-settings-app__search"
				placeholder={ __( 'Search settings…', 'agent-builder' ) }
				value={ filter }
				onChange={ ( e ) => onChange( { type: 'filter', value: e.target.value } ) }
			/>
			{ groups.map( ( group ) => {
				// Hide the developer-facing group in Basic unless the user
				// is searching, already on one of those tabs (deep link),
				// or has used the Settings-page escape hatch.
				if (
					group.id === 'advanced' &&
					! settingsAdvanced &&
					! q &&
					! onAdvancedTab
				) {
					return null;
				}
				const items = ( group.slugs || [] )
					.filter( ( slug ) => tabs[ slug ] )
					.filter(
						( slug ) =>
							! q ||
							String( tabs[ slug ] )
								.toLowerCase()
								.includes( q ) ||
							slug.includes( q )
					);
				if ( ! items.length ) {
					return null;
				}
				return (
					<div
						key={ group.id }
						className="agentic-settings-app__nav-group"
					>
						<span className="agentic-settings-app__nav-label">
							{ group.label }
						</span>
						<ul className="agentic-settings-app__nav-list">
							{ items.map( ( slug ) => (
								<li key={ slug }>
									<button
										type="button"
										className={
											'agentic-settings-app__nav-item' +
											( slug === active
												? ' is-active'
												: '' )
										}
										onClick={ () =>
											onChange( {
												type: 'tab',
												value: slug,
											} )
										}
									>
										{ tabs[ slug ] }
									</button>
								</li>
							) ) }
						</ul>
					</div>
				);
			} ) }
			{ ! settingsAdvanced ? (
				<button
					type="button"
					className="agentic-settings-app__advanced-hatch"
					disabled={ hatchBusy }
					onClick={ () => setSettingsMode( 'advanced' ) }
				>
					{ __( 'Advanced settings', 'agent-builder' ) }
				</button>
			) : (
				<button
					type="button"
					className="agentic-settings-app__advanced-hatch"
					disabled={ hatchBusy }
					onClick={ () => setSettingsMode( 'basic' ) }
				>
					{ __( 'Hide advanced settings', 'agent-builder' ) }
				</button>
			) }
		</aside>
	);
}

function InterfaceTab( {
	data,
	setData,
	onSave,
	saving,
	error,
	saved,
	clearSaved,
	reloadBootstrap,
} ) {
	const isAdvanced = !! data.is_advanced;
	const useThemeAccent = ! data.global_accent;
	const [ resetting, setResetting ] = useState( false );
	const modeToggle = (
		<ScreenModeToggle
			screen="settings-interface"
			isAdvanced={ isAdvanced }
			onChanged={ reloadBootstrap }
		/>
	);
	const resetScreens = () => {
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
		setResetting( true );
		apiFetch( {
			path: 'agentic/v1/admin-page',
			method: 'POST',
			data: { action_name: 'reset_screen_modes' },
		} ).finally( () => setResetting( false ) );
	};
	return (
		<Panel
			title={ __( 'Interface', 'agent-builder' ) }
			actions={ modeToggle }
			footer={ <SaveBar onSave={ onSave } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<RadioControl
				label={ __( 'Default interface mode', 'agent-builder' ) }
				selected={ data.ui_mode || 'basic' }
				options={ [
					{ label: __( 'Basic', 'agent-builder' ), value: 'basic' },
					{
						label: __( 'Advanced', 'agent-builder' ),
						value: 'advanced',
					},
				] }
				onChange={ ( v ) => setData( { ...data, ui_mode: v } ) }
				help={ __(
					'Starting point for screens you have not switched individually. Basic is guided and uses plain language; Advanced shows every control.',
					'agent-builder'
				) }
			/>
			<Button
				variant="link"
				isDestructive
				disabled={ resetting }
				onClick={ resetScreens }
			>
				{ __( 'Reset all screens to my default', 'agent-builder' ) }
			</Button>
			<hr
				className="agentic-react-section-sep"
				style={ {
					margin: '24px 0 8px',
					border: 0,
					borderTop: '1px solid #dcdcde',
				} }
			/>
			{ isAdvanced && (
			<Section title={ __( 'How agents address people', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Site knowledge and standing guidance are under',
						'agent-builder'
					) }{ ' ' }
					<a href="admin.php?page=agentic-train-data">
						{ __( 'Knowledge', 'agent-builder' ) }
					</a>
					{ '. ' }
					{ __(
						'Per-agent personality is under Knowledge → Instructions.',
						'agent-builder'
					) }
				</p>
				<TextControl
					label={ __(
						'What should agents call administrators?',
						'agent-builder'
					) }
					value={ data.admin_address || '' }
					onChange={ ( v ) =>
						setData( { ...data, admin_address: v } )
					}
					help={ __(
						'Leave blank to use the WordPress display name.',
						'agent-builder'
					) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<div style={ { height: 16 } } />
				<TextControl
					label={ __(
						'What should agents call frontend visitors?',
						'agent-builder'
					) }
					value={ data.frontend_address || '' }
					onChange={ ( v ) =>
						setData( { ...data, frontend_address: v } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</Section>
			) }
			<Section title={ __( 'Chat theme', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Visual theme for admin chat and frontend shortcodes. Click a preview to select.',
						'agent-builder'
					) }
				</p>
				<div className="agentic-theme-grid">
					{ ( data.chat_themes || [] ).map( ( t ) => {
						const selected =
							( data.chat_theme || 'light' ) === t.value;
						return (
							<button
								key={ t.value }
								type="button"
								className={
									'agentic-theme-card' +
									( selected ? ' is-selected' : '' )
								}
								onClick={ () =>
									setData( {
										...data,
										chat_theme: t.value,
									} )
								}
								aria-pressed={ selected }
								style={ {
									cursor: 'pointer',
									border: selected
										? '2px solid #2271b1'
										: '2px solid #dcdcde',
									borderRadius: 12,
									overflow: 'hidden',
									padding: 0,
									background: '#fff',
									textAlign: 'left',
									width: '100%',
								} }
							>
								<div
									style={ {
										background: t.bg,
										padding: 16,
										minHeight: 100,
									} }
								>
									<div
										style={ {
											display: 'flex',
											gap: 8,
											alignItems: 'center',
											marginBottom: 12,
										} }
									>
										<div
											style={ {
												width: 28,
												height: 28,
												borderRadius: 6,
												background: t.accent,
											} }
										/>
										<span
											style={ {
												color: t.text,
												fontWeight: 600,
												fontSize: 13,
											} }
										>
											{ __(
												'AI Agent',
												'agent-builder'
											) }
										</span>
									</div>
									<div
										style={ {
											background: t.msg,
											borderRadius: 8,
											padding: '8px 10px',
											marginBottom: 6,
										} }
									>
										<span
											style={ {
												color: t.text,
												fontSize: 11,
											} }
										>
											{ __(
												'Hello! How can I help?',
												'agent-builder'
											) }
										</span>
									</div>
									<div
										style={ {
											marginLeft: 'auto',
											maxWidth: '70%',
											background: t.accent + '33',
											borderRadius: 8,
											padding: '8px 10px',
										} }
									>
										<span
											style={ {
												color: t.text,
												fontSize: 11,
											} }
										>
											{ __(
												'Update my homepage',
												'agent-builder'
											) }
										</span>
									</div>
								</div>
								<div className="agentic-theme-card-footer">
									<strong className="agentic-theme-label">
										{ t.label }
									</strong>
									<span className="agentic-text-xs agentic-text-muted">
										{ t.desc }
									</span>
								</div>
							</button>
						);
					} ) }
				</div>
				{ isAdvanced && (
					<>
				<div style={ { marginTop: 16 } }>
					<SelectControl
						label={ __( 'Chat font', 'agent-builder' ) }
						value={ data.global_font || '' }
						options={ data.font_options || [] }
						onChange={ ( v ) =>
							setData( { ...data, global_font: v } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</div>
				<div style={ { marginTop: 16 } }>
					<ToggleControl
						label={ __(
							'Use theme accent colour',
							'agent-builder'
						) }
						checked={ useThemeAccent }
						onChange={ ( v ) =>
							setData( {
								...data,
								global_accent: v
									? ''
									: data.global_accent || '#8b5cf6',
								use_theme_accent: v,
							} )
						}
						__nextHasNoMarginBottom
					/>
				</div>
				{ ! useThemeAccent && (
					<div style={ { marginTop: 12 } }>
						<BaseControl
							id="agentic-accent"
							label={ __( 'Accent colour', 'agent-builder' ) }
							__nextHasNoMarginBottom
						>
							<input
								id="agentic-accent"
								type="color"
								value={ data.global_accent || '#8b5cf6' }
								onChange={ ( e ) =>
									setData( {
										...data,
										global_accent: e.target.value,
										use_theme_accent: false,
									} )
								}
								style={ {
									width: 48,
									height: 36,
									border: '1px solid #c3c4c7',
									borderRadius: 4,
								} }
							/>
						</BaseControl>
					</div>
				) }
					</>
				) }
			</Section>
			{ isAdvanced && (
			<div style={ { marginTop: 24 } }>
				<ToggleControl
					label={ __(
						'Show the Getting Started checklist on the dashboard',
						'agent-builder'
					) }
					checked={ !! data.show_onboarding }
					onChange={ ( v ) =>
						setData( { ...data, show_onboarding: v } )
					}
					__nextHasNoMarginBottom
				/>
			</div>
			) }
		</Panel>
	);
}

function ProvidersTab( { data } ) {
	// Phase 6 Basic/Advanced lives on the classic add/edit form (screen
	// key 'providers'). This list only links into that form — no second
	// Settings-tab toggle here, so the two modes cannot conflict.
	const rows = data.providers || [];
	const formAction =
		data.form_action ||
		'admin.php?page=agentic-settings&tab=providers';
	const nonce = data.provider_nonce || '';

	return (
		<Panel title={ __( 'Providers', 'agent-builder' ) }>
			<p className="agentic-react-lead">
				{ __(
					'Configure the AI providers available on this site. Only a connected provider can be set as the site default.',
					'agent-builder'
				) }
			</p>
			<p>
				<Button variant="primary" href={ data.add_url }>
					{ __( 'Add Provider', 'agent-builder' ) }
				</Button>
			</p>
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table">
					<thead>
						<tr>
							<th>{ __( 'Status', 'agent-builder' ) }</th>
							<th>{ __( 'Name', 'agent-builder' ) }</th>
							<th>{ __( 'Slug', 'agent-builder' ) }</th>
							<th>{ __( 'Model', 'agent-builder' ) }</th>
							<th>{ __( 'Actions', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.slug }>
								<td>
									<span
										className={
											'agentic-react-led' +
											( row.connected ? ' is-on' : '' )
										}
									/>{ ' ' }
									{ row.connected
										? __( 'Connected', 'agent-builder' )
										: __(
												'Not connected',
												'agent-builder'
										  ) }
								</td>
								<td>
									<strong>{ row.name }</strong>
									{ row.is_builtin && (
										<span className="agentic-react-muted">
											{ ' ' }
											built-in
										</span>
									) }
								</td>
								<td>
									<code>{ row.slug }</code>
								</td>
								<td>
									<small>{ row.default_model }</small>
								</td>
								<td className="agentic-providers-actions">
									<a href={ row.edit_url }>
										{ __( 'Edit', 'agent-builder' ) }
									</a>
									{ row.is_default && (
										<>
											{ ' · ' }
											<span className="agentic-react-badge">
												{ __(
													'Default',
													'agent-builder'
												) }
											</span>
										</>
									) }
									{ ! row.is_default && row.connected && (
										<>
											{ ' · ' }
											<form
												method="post"
												action={ formAction }
												className="agentic-form-inline agentic-providers-setdefault"
											>
												<input
													type="hidden"
													name="_wpnonce"
													value={ nonce }
												/>
												<input
													type="hidden"
													name="_wp_http_referer"
													value={ formAction }
												/>
												<input
													type="hidden"
													name="agentic_provider_action"
													value="set_default"
												/>
												<input
													type="hidden"
													name="provider_slug"
													value={ row.slug }
												/>
												<button
													type="submit"
													className="button-link agentic-provider-setdefault-btn"
												>
													{ __(
														'Set Default',
														'agent-builder'
													) }
												</button>
											</form>
										</>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<p className="agentic-react-muted" style={ { marginTop: 12 } }>
				{ __(
					'API keys: use Edit on a provider. Setting a default updates the global provider and model used by agents.',
					'agent-builder'
				) }
			</p>
		</Panel>
	);
}

function SecurityTab( {
	data,
	setData,
	onSave,
	saving,
	error,
	saved,
	clearSaved,
	reloadBootstrap,
} ) {
	const isAdvanced = !! data.is_advanced;
	const agentMode = data.default_agent_mode || 'supervised';
	let agentModeHelp = __(
		'Agents propose actions for you to approve before anything changes. Recommended for most sites.',
		'agent-builder'
	);
	if ( agentMode === 'autonomous' ) {
		agentModeHelp = __(
			'Agents act immediately without waiting for your OK. Use only if you trust them on this site.',
			'agent-builder'
		);
	} else if ( agentMode === 'readonly' ) {
		agentModeHelp = __(
			'Agents can chat but cannot change anything on the site.',
			'agent-builder'
		);
	}
	const modeToggle = (
		<ScreenModeToggle
			screen="settings-security"
			isAdvanced={ isAdvanced }
			onChanged={ reloadBootstrap }
		/>
	);
	return (
		<Panel
			title={ __( 'Security', 'agent-builder' ) }
			actions={ modeToggle }
			footer={ <SaveBar onSave={ onSave } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<SelectControl
				label={ __( 'Default agent mode', 'agent-builder' ) }
				value={ agentMode }
				options={ [
					{
						label: __( 'Supervised', 'agent-builder' ),
						value: 'supervised',
					},
					{
						label: __( 'Autonomous', 'agent-builder' ),
						value: 'autonomous',
					},
					{
						label: __( 'Read-only', 'agent-builder' ),
						value: 'readonly',
					},
				] }
				onChange={ ( v ) =>
					setData( { ...data, default_agent_mode: v } )
				}
				help={ agentModeHelp }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<div style={ { height: 16 } } />
			<ToggleControl
				label={ __( 'Message scanning', 'agent-builder' ) }
				help={ __(
					'Blocks common injection patterns and flags personal data in chat messages.',
					'agent-builder'
				) }
				checked={ !! data.message_scanning }
				onChange={ ( v ) =>
					setData( { ...data, message_scanning: v } )
				}
				__nextHasNoMarginBottom
			/>
			<div style={ { height: 8 } } />
			<ToggleControl
				label={ __( 'Require chat consent', 'agent-builder' ) }
				help={ __(
					'Ask visitors to agree before chatting. Required in some regions.',
					'agent-builder'
				) }
				checked={ !! data.chat_consent_enabled }
				onChange={ ( v ) =>
					setData( { ...data, chat_consent_enabled: v } )
				}
				__nextHasNoMarginBottom
			/>
			{ data.chat_consent_enabled && (
				<div style={ { marginTop: 12 } }>
					<TextareaControl
						label={ __( 'Consent text', 'agent-builder' ) }
						value={ data.chat_consent_text || '' }
						onChange={ ( v ) =>
							setData( { ...data, chat_consent_text: v } )
						}
						rows={ 3 }
						__nextHasNoMarginBottom
					/>
				</div>
			) }
			<div style={ { height: 16 } } />
			<TextControl
				label={ __(
					'Conversation retention (days)',
					'agent-builder'
				) }
				help={ __(
					'How long to keep chat history. 0 means keep forever.',
					'agent-builder'
				) }
				type="number"
				value={ String( data.retention_conversations ?? 30 ) }
				onChange={ ( v ) =>
					setData( {
						...data,
						retention_conversations: parseInt( v, 10 ) || 0,
					} )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			{ isAdvanced && (
				<>
			<div style={ { height: 12 } } />
			<TextControl
				label={ __( 'Audit log retention (days)', 'agent-builder' ) }
				type="number"
				value={ String( data.retention_audit_log ?? 30 ) }
				onChange={ ( v ) =>
					setData( {
						...data,
						retention_audit_log: parseInt( v, 10 ) || 0,
					} )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<div style={ { height: 16 } } />
			<ToggleControl
				label={ __( 'Refresh model catalog from Agentic', 'agent-builder' ) }
				help={ __(
					'Off by default. When on, a daily cron fetches model names and list prices from agentic-plugin.com. BYOK providers work without this; enable it only if you want the curated catalog.',
					'agent-builder'
				) }
				checked={ !! data.allow_platform_sync }
				onChange={ ( v ) =>
					setData( { ...data, allow_platform_sync: v } )
				}
				__nextHasNoMarginBottom
			/>
			<div style={ { height: 24 } } />
			<Section title={ __( 'Request Rate Limits', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'IP-based throttle applied before role-based daily quotas. Protects against rapid-fire requests.',
						'agent-builder'
					) }
				</p>
				<TextControl
					label={ __(
						'Authenticated users (requests per minute)',
						'agent-builder'
					) }
					type="number"
					value={ String( data.rate_limit_authenticated ?? 30 ) }
					onChange={ ( v ) =>
						setData( {
							...data,
							rate_limit_authenticated: parseInt( v, 10 ) || 1,
						} )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				<div style={ { height: 12 } } />
				<TextControl
					label={ __(
						'Anonymous visitors (requests per minute)',
						'agent-builder'
					) }
					type="number"
					value={ String( data.rate_limit_anonymous ?? 10 ) }
					onChange={ ( v ) =>
						setData( {
							...data,
							rate_limit_anonymous: parseInt( v, 10 ) || 1,
						} )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</Section>
				</>
			) }
		</Panel>
	);
}

function UsersTab( {
	data,
	setData,
	onSave,
	saving,
	error,
	saved,
	clearSaved,
	reloadBootstrap,
} ) {
	const isAdvanced = !! data.is_advanced;
	const roles = data.roles || [];
	const pluginPrivs = data.plugin_privileges || [];
	const agentPrivs = data.agent_privileges || [];
	const limits = data.usage_limits || {};

	const roleHasPriv = ( privRoles, roleSlug ) => {
		if ( roleSlug === 'administrator' ) {
			return true;
		}
		return ( privRoles || [] ).includes( roleSlug );
	};

	const togglePluginPriv = ( privKey, roleSlug, checked ) => {
		if ( roleSlug === 'administrator' ) {
			return;
		}
		const next = ( pluginPrivs || [] ).map( ( p ) => {
			if ( p.key !== privKey ) {
				return p;
			}
			const set = new Set( p.roles || [] );
			if ( checked ) {
				set.add( roleSlug );
			} else {
				set.delete( roleSlug );
			}
			set.add( 'administrator' );
			return { ...p, roles: Array.from( set ) };
		} );
		setData( { ...data, plugin_privileges: next } );
	};

	const toggleAgentPriv = ( privKey, roleSlug, checked ) => {
		if ( roleSlug === 'administrator' ) {
			return;
		}
		const next = ( agentPrivs || [] ).map( ( p ) => {
			if ( p.key !== privKey ) {
				return p;
			}
			const set = new Set( p.roles || [] );
			if ( checked ) {
				set.add( roleSlug );
			} else {
				set.delete( roleSlug );
			}
			set.add( 'administrator' );
			return { ...p, roles: Array.from( set ) };
		} );
		setData( { ...data, agent_privileges: next } );
	};

	const setLimit = ( roleSlug, field, value ) => {
		const n = Math.max( 0, parseInt( value, 10 ) || 0 );
		setData( {
			...data,
			usage_limits: {
				...limits,
				[ roleSlug ]: {
					...( limits[ roleSlug ] || {} ),
					[ field ]: n,
				},
			},
		} );
	};

	const buildRoleSettings = () => {
		const plugin = {};
		const agents = {};
		pluginPrivs.forEach( ( p ) => {
			plugin[ p.key ] = Array.from(
				new Set( [ ...( p.roles || [] ), 'administrator' ] )
			);
		} );
		agentPrivs.forEach( ( p ) => {
			agents[ p.key ] = Array.from(
				new Set( [ ...( p.roles || [] ), 'administrator' ] )
			);
		} );
		return { plugin, agents };
	};

	const saveUsers = () => {
		onSave( {
			...data,
			role_settings: buildRoleSettings(),
		} );
	};

	const modeToggle = (
		<ScreenModeToggle
			screen="settings-users"
			isAdvanced={ isAdvanced }
			onChanged={ reloadBootstrap }
		/>
	);

	// Basic mode swaps the raw role/privilege matrix for a chat with the
	// bundled User Assistant — same full-content-swap pattern Skills/Publish
	// use, not a partial hide. The chat embed brings its own bordered
	// container/header, so it renders directly (not wrapped in Panel) to
	// avoid double-boxing it inside another card.
	if ( ! isAdvanced ) {
		return (
			<AdminPage
				title={ __( 'Users', 'agent-builder' ) }
				description={ __(
					'Control who can administer the plugin and use AI agents.',
					'agent-builder'
				) }
				actions={ modeToggle }
			>
				<ChatEmbed
					assistant={ data.assistant }
					deploymentContext="settings_users_tab"
					className="agentic-users-chat-embed"
				/>
			</AdminPage>
		);
	}

	return (
		<AdminPage title={ __( 'Users', 'agent-builder' ) } actions={ modeToggle }>
		<Panel
			title={ __( 'Users', 'agent-builder' ) }
			footer={ <SaveBar onSave={ saveUsers } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<p className="agentic-react-lead">
				{ __(
					'Control which WordPress roles can administer the plugin and interact with AI agents. Administrators always retain full access.',
					'agent-builder'
				) }
			</p>
			<p className="agentic-react-muted">
				{ __(
					'These rules apply site-wide. A user needs at least one ticked box to access the corresponding feature. Removing a role from all plugin privileges will hide the Agent Builder menu from that role entirely.',
					'agent-builder'
				) }
			</p>

			{ /* Plugin Administration */ }
			<Section title={ __( 'Plugin Administration', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Who can access and configure the Agent Builder admin pages.',
						'agent-builder'
					) }
				</p>
				<div className="agentic-react-table-wrap agentic-users-matrix">
					<table className="agentic-react-table">
						<thead>
							<tr>
								<th>
									{ __( 'Permission', 'agent-builder' ) }
								</th>
								{ roles.map( ( r ) => (
									<th
										key={ r.slug }
										className="agentic-users-matrix__role"
									>
										{ r.name }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ pluginPrivs.map( ( p ) => (
								<tr key={ p.key }>
									<td>
										<span className="agentic-users-matrix__perm">
											<strong>{ p.label }</strong>
											{ p.description && (
												<span
													className="agentic-users-matrix__help"
													title={ p.description }
													aria-label={ p.description }
												>
													?
												</span>
											) }
										</span>
									</td>
									{ roles.map( ( r ) => (
										<td
											key={ r.slug }
											className="agentic-users-matrix__cell"
										>
											<input
												type="checkbox"
												checked={ roleHasPriv(
													p.roles,
													r.slug
												) }
												disabled={
													r.slug === 'administrator'
												}
												title={
													r.slug === 'administrator'
														? __(
																'Administrators always have full access',
																'agent-builder'
														  )
														: p.label
												}
												onChange={ ( e ) =>
													togglePluginPriv(
														p.key,
														r.slug,
														e.target.checked
													)
												}
											/>
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			</Section>

			{ /* AI Agents */ }
			<Section title={ __( 'AI Agents', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Who can chat with and interact with installed AI agents, and how much they may use per day.',
						'agent-builder'
					) }
				</p>
				<div className="agentic-react-table-wrap agentic-users-matrix">
					<table className="agentic-react-table">
						<thead>
							<tr>
								<th>
									{ __(
										'Permission / Limit',
										'agent-builder'
									) }
								</th>
								<th className="agentic-users-matrix__role">
									{ __( 'Visitor', 'agent-builder' ) }
									<div className="agentic-react-muted">
										{ __(
											'Not logged in',
											'agent-builder'
										) }
									</div>
								</th>
								{ roles.map( ( r ) => (
									<th
										key={ r.slug }
										className="agentic-users-matrix__role"
									>
										{ r.name }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ agentPrivs.map( ( p ) => (
								<tr key={ p.key }>
									<td>
										<span className="agentic-users-matrix__perm">
											<strong>{ p.label }</strong>
											{ p.description && (
												<span
													className="agentic-users-matrix__help"
													title={ p.description }
													aria-label={ p.description }
												>
													?
												</span>
											) }
										</span>
									</td>
									<td className="agentic-users-matrix__cell">
										{ p.key === 'chat_frontend' ? (
											<input
												type="checkbox"
												checked={
													!! data.allow_anonymous_chat
												}
												title={ __(
													'Allow non-logged-in visitors to use frontend chat',
													'agent-builder'
												) }
												onChange={ ( e ) =>
													setData( {
														...data,
														allow_anonymous_chat:
															e.target.checked,
													} )
												}
											/>
										) : (
											<span className="agentic-react-muted">
												—
											</span>
										) }
									</td>
									{ roles.map( ( r ) => (
										<td
											key={ r.slug }
											className="agentic-users-matrix__cell"
										>
											<input
												type="checkbox"
												checked={ roleHasPriv(
													p.roles,
													r.slug
												) }
												disabled={
													r.slug === 'administrator'
												}
												title={
													r.slug === 'administrator'
														? __(
																'Administrators always have full access',
																'agent-builder'
														  )
														: p.label
												}
												onChange={ ( e ) =>
													toggleAgentPriv(
														p.key,
														r.slug,
														e.target.checked
													)
												}
											/>
										</td>
									) ) }
								</tr>
							) ) }
							<tr className="agentic-users-matrix__section">
								<td
									colSpan={ roles.length + 2 }
								>
									<strong>
										{ __(
											'Daily Limits',
											'agent-builder'
										) }
									</strong>{ ' ' }
									<span className="agentic-react-muted">
										{ __(
											'0 = unlimited, resets at midnight UTC. Administrators are never limited.',
											'agent-builder'
										) }
									</span>
								</td>
							</tr>
							<tr>
								<td>
									<span className="agentic-users-matrix__perm">
										<strong>
											{ __(
												'Queries / day',
												'agent-builder'
											) }
										</strong>
										<span
											className="agentic-users-matrix__help"
											title={ __(
												'Max AI chat messages per user per day.',
												'agent-builder'
											) }
											aria-label={ __(
												'Max AI chat messages per user per day.',
												'agent-builder'
											) }
										>
											?
										</span>
									</span>
								</td>
								<td className="agentic-users-matrix__cell">
									<input
										type="number"
										min={ 0 }
										step={ 1 }
										className="agentic-users-matrix__num"
										value={
											limits.anonymous?.queries ?? 0
										}
										onChange={ ( e ) =>
											setLimit(
												'anonymous',
												'queries',
												e.target.value
											)
										}
									/>
								</td>
								{ roles.map( ( r ) => (
									<td
										key={ r.slug }
										className="agentic-users-matrix__cell"
									>
										<input
											type="number"
											min={ 0 }
											step={ 1 }
											className="agentic-users-matrix__num"
											disabled={
												r.slug === 'administrator'
											}
											value={
												r.slug === 'administrator'
													? 0
													: limits[ r.slug ]
															?.queries ?? 0
											}
											onChange={ ( e ) =>
												setLimit(
													r.slug,
													'queries',
													e.target.value
												)
											}
										/>
									</td>
								) ) }
							</tr>
							<tr>
								<td>
									<span className="agentic-users-matrix__perm">
										<strong>
											{ __(
												'Tokens / day',
												'agent-builder'
											) }
										</strong>
										<span
											className="agentic-users-matrix__help"
											title={ __(
												'Max AI tokens consumed per user per day.',
												'agent-builder'
											) }
											aria-label={ __(
												'Max AI tokens consumed per user per day.',
												'agent-builder'
											) }
										>
											?
										</span>
									</span>
								</td>
								<td className="agentic-users-matrix__cell">
									<input
										type="number"
										min={ 0 }
										step={ 1000 }
										className="agentic-users-matrix__num"
										value={
											limits.anonymous?.tokens ?? 0
										}
										onChange={ ( e ) =>
											setLimit(
												'anonymous',
												'tokens',
												e.target.value
											)
										}
									/>
								</td>
								{ roles.map( ( r ) => (
									<td
										key={ r.slug }
										className="agentic-users-matrix__cell"
									>
										<input
											type="number"
											min={ 0 }
											step={ 1000 }
											className="agentic-users-matrix__num"
											disabled={
												r.slug === 'administrator'
											}
											value={
												r.slug === 'administrator'
													? 0
													: limits[ r.slug ]
															?.tokens ?? 0
											}
											onChange={ ( e ) =>
												setLimit(
													r.slug,
													'tokens',
													e.target.value
												)
											}
										/>
									</td>
								) ) }
							</tr>
						</tbody>
					</table>
				</div>
			</Section>

			<p className="agentic-react-muted">
				{ __(
					'How enforcement works: these settings control WordPress admin menu visibility, admin bar chat access, the REST chat API, and AJAX task triggers. Unchecked roles are blocked at the server, not just hidden in the UI. Request rate limits are under Security.',
					'agent-builder'
				) }
			</p>
		</Panel>
		</AdminPage>
	);
}

function MemoryTab( { data, setData, onSave, saving, error, saved, clearSaved } ) {
	return (
		<Panel
			title={ __( 'Memory', 'agent-builder' ) }
			footer={ <SaveBar onSave={ onSave } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<p className="agentic-react-lead">
				{ __(
					'Persistent key-value memory agents use across conversations.',
					'agent-builder'
				) }
			</p>
			<TextControl
				label={ __( 'Default memory TTL (days)', 'agent-builder' ) }
				help={ __( '0 = never expires', 'agent-builder' ) }
				type="number"
				value={ String( data.ttl_days ?? 30 ) }
				onChange={ ( v ) =>
					setData( {
						...data,
						ttl_days: parseInt( v, 10 ) || 0,
					} )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<div style={ { height: 12 } } />
			<ToggleControl
				label={ __( 'Enable local browser memory', 'agent-builder' ) }
				checked={ !! data.local_memory_enabled }
				onChange={ ( v ) =>
					setData( { ...data, local_memory_enabled: v } )
				}
				__nextHasNoMarginBottom
			/>
		</Panel>
	);
}

/**
 * Normalize provider.models (string[] or object[]) into SelectControl options.
 *
 * @param {Object|undefined} provider Provider row from bootstrap.
 * @return {Array<{label:string,value:string}>}
 */
function modelOptionsForProvider( provider ) {
	const raw = provider?.models || [];
	const opts = [];
	raw.forEach( ( m ) => {
		if ( typeof m === 'string' ) {
			opts.push( { label: m, value: m } );
			return;
		}
		if ( m && typeof m === 'object' ) {
			const value = String( m.id || m.slug || m.model || m.value || '' );
			if ( ! value ) {
				return;
			}
			const label = String( m.name || m.label || value );
			opts.push( { label, value } );
		}
	} );
	return opts;
}

function AgentsTab( { data, setData, onSave, saving, error, saved, clearSaved } ) {
	const providers = data.providers || [];
	const agents = data.agents || [];
	const defaultProviderSlug = data.global_provider || '';
	const defaultModel =
		data.global_model ||
		providers.find( ( p ) => p.slug === defaultProviderSlug )
			?.default_model ||
		'';

	const providerBySlug = ( slug ) =>
		providers.find( ( p ) => p.slug === slug );

	const effectiveProvider = ( a ) =>
		a.override_provider || defaultProviderSlug || '';

	const effectiveModel = ( a ) => {
		if ( a.override_model ) {
			return a.override_model;
		}
		const prov = providerBySlug( effectiveProvider( a ) );
		return (
			defaultModel ||
			prov?.default_model ||
			modelOptionsForProvider( prov )[ 0 ]?.value ||
			''
		);
	};

	// Only connected providers (plus current value if it is disconnected).
	const providerOptionsBase = providers
		.filter( ( p ) => p.connected )
		.map( ( p ) => ( {
			label:
				p.name +
				( p.slug === defaultProviderSlug
					? ' ' + __( '(default)', 'agent-builder' )
					: '' ),
			value: p.slug,
		} ) );

	const providerOptionsForAgent = ( a ) => {
		const opts = [ ...providerOptionsBase ];
		const cur = effectiveProvider( a );
		if ( cur && ! opts.some( ( o ) => o.value === cur ) ) {
			const p = providerBySlug( cur );
			opts.unshift( {
				label: p?.name || cur,
				value: cur,
			} );
		}
		return opts;
	};

	const updateAgent = ( slug, patch ) => {
		setData( {
			...data,
			agents: agents.map( ( x ) =>
				x.slug === slug ? { ...x, ...patch } : x
			),
		} );
	};

	const modelOptionsForAgent = ( a ) => {
		const prov = providerBySlug( effectiveProvider( a ) );
		const opts = modelOptionsForProvider( prov );
		const cur = effectiveModel( a );
		if ( cur && ! opts.some( ( o ) => o.value === cur ) ) {
			opts.push( { label: cur, value: cur } );
		}
		return opts;
	};

	return (
		<Panel
			title={ __( 'Agents', 'agent-builder' ) }
			footer={ <SaveBar onSave={ onSave } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<p className="agentic-react-lead">
				{ __(
					'Chat capabilities and per-agent provider/model. Set the site default provider on the Providers tab. Theme is under Interface.',
					'agent-builder'
				) }
			</p>
			<Section title={ __( 'Chat features', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Features available in agent chat.',
						'agent-builder'
					) }
				</p>
				<ToggleControl
					label={ __( 'Audio input', 'agent-builder' ) }
					checked={ !! data.chat_audio }
					onChange={ ( v ) => setData( { ...data, chat_audio: v } ) }
					__nextHasNoMarginBottom
				/>
				<div style={ { height: 8 } } />
				<ToggleControl
					label={ __( 'Text-to-speech', 'agent-builder' ) }
					checked={ !! data.chat_tts }
					onChange={ ( v ) => setData( { ...data, chat_tts: v } ) }
					__nextHasNoMarginBottom
				/>
				<div style={ { height: 8 } } />
				<ToggleControl
					label={ __( 'Vision / image input', 'agent-builder' ) }
					checked={ !! data.chat_vision }
					onChange={ ( v ) =>
						setData( { ...data, chat_vision: v } )
					}
					__nextHasNoMarginBottom
				/>
				<div style={ { height: 8 } } />
				<ToggleControl
					label={ __( 'Hide “Powered by Agent Builder” branding', 'agent-builder' ) }
					help={ __(
						'On by default. Uncheck only if you want a small credit line in the chat footer.',
						'agent-builder'
					) }
					checked={ !! data.chat_whitelabel }
					onChange={ ( v ) =>
						setData( { ...data, chat_whitelabel: v } )
					}
					__nextHasNoMarginBottom
				/>
				<div style={ { height: 8 } } />
				<ToggleControl
					label={ __( 'Show the WhatsApp Pro promo in admin chat', 'agent-builder' ) }
					help={ __(
						'Off by default. When on, admin chat shows a link to the WhatsApp connector.',
						'agent-builder'
					) }
					checked={ !! data.show_whatsapp_cta }
					onChange={ ( v ) =>
						setData( { ...data, show_whatsapp_cta: v } )
					}
					__nextHasNoMarginBottom
				/>
			</Section>
			<Section title={ __( 'Active agent overrides', 'agent-builder' ) }>
				<p className="agentic-react-muted" style={ { marginTop: 0 } }>
					{ __(
						'Each row starts on the site default provider and model (Providers tab). Change only the agents that need a different setup.',
						'agent-builder'
					) }
				</p>
				{ ! agents.length ? (
					<p className="agentic-react-muted">
						{ __( 'No active agents.', 'agent-builder' ) }
					</p>
				) : (
					<div className="agentic-react-table-wrap agentic-agents-overrides">
						<table className="agentic-react-table agentic-agents-overrides__table">
							<thead>
								<tr>
									<th>
										{ __( 'Agent', 'agent-builder' ) }
									</th>
									<th>
										{ __( 'Provider', 'agent-builder' ) }
									</th>
									<th>
										{ __( 'Model', 'agent-builder' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ agents.map( ( a ) => (
									<tr key={ a.slug }>
										<td className="agentic-agents-overrides__name">
											<strong>{ a.name }</strong>
											<div className="agentic-react-muted">
												{ a.slug }
											</div>
										</td>
										<td>
											<select
												className="agentic-agents-overrides__select"
												value={ effectiveProvider( a ) }
												aria-label={
													a.name +
													' ' +
													__(
														'provider',
														'agent-builder'
													)
												}
												onChange={ ( e ) => {
													const v = e.target.value;
													// Selecting the site default clears the override.
													const nextProvider =
														v ===
														defaultProviderSlug
															? ''
															: v;
													const prov =
														providerBySlug( v );
													const models =
														modelOptionsForProvider(
															prov
														).map(
															( o ) => o.value
														);
													const keep =
														a.override_model &&
														models.includes(
															a.override_model
														);
													updateAgent( a.slug, {
														override_provider:
															nextProvider,
														override_model: keep
															? a.override_model
															: '',
													} );
												} }
											>
												{ providerOptionsForAgent(
													a
												).map( ( o ) => (
													<option
														key={ o.value }
														value={ o.value }
													>
														{ o.label }
													</option>
												) ) }
											</select>
										</td>
										<td>
											<select
												className="agentic-agents-overrides__select"
												value={ effectiveModel( a ) }
												aria-label={
													a.name +
													' ' +
													__(
														'model',
														'agent-builder'
													)
												}
												onChange={ ( e ) => {
													const v = e.target.value;
													const def =
														effectiveProvider(
															a
														) === defaultProviderSlug
															? defaultModel ||
															  providerBySlug(
																	effectiveProvider(
																		a
																	)
															  )
																	?.default_model ||
															  ''
															: providerBySlug(
																	effectiveProvider(
																		a
																	)
															  )
																	?.default_model ||
															  '';
													// Selecting the default model for this provider clears override.
													updateAgent( a.slug, {
														override_model:
															v === def
																? ''
																: v,
													} );
												} }
											>
												{ modelOptionsForAgent(
													a
												).map( ( o ) => (
													<option
														key={ o.value }
														value={ o.value }
													>
														{ o.label }
													</option>
												) ) }
											</select>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Section>
			<div
				style={ {
					marginTop: 24,
					padding: 16,
					borderRadius: 8,
					border: data.disable_all_agents
						? '1px solid #d63638'
						: '1px solid #dcdcde',
					background: data.disable_all_agents ? '#fcf0f1' : '#f6f7f7',
				} }
			>
				<ToggleControl
					label={ __( 'Disable All Agents', 'agent-builder' ) }
					help={
						<span className="agentic-emergency-stop-hint">
							<strong className="agentic-emergency-stop-text">
								{ __( 'Emergency stop', 'agent-builder' ) }
							</strong>
							{ __(
								': deactivate and log agent states, cancels all jobs and disconnects providers.',
								'agent-builder'
							) }
						</span>
					}
					checked={ !! data.disable_all_agents }
					onChange={ ( v ) => {
						const msg = v
							? __(
									'EMERGENCY STOP: deactivate and log agent states, cancel all jobs, and disconnect providers. Continue?',
									'agent-builder'
							  )
							: __(
									'Turn off emergency stop?',
									'agent-builder'
							  );
						if ( ! window.confirm( msg ) ) {
							return;
						}
						setData( { ...data, disable_all_agents: v } );
					} }
					__nextHasNoMarginBottom
				/>
			</div>
		</Panel>
	);
}

function InstructionsTab( { data, setData, onSave, saving, error, saved, clearSaved } ) {
	const agents = data.agents || [];
	const [ selected, setSelected ] = useState(
		agents[ 0 ]?.slug || ''
	);
	const current =
		agents.find( ( a ) => a.slug === selected ) || agents[ 0 ] || null;

	const update = ( key, value ) => {
		if ( ! current ) {
			return;
		}
		setData( {
			...data,
			agents: agents.map( ( a ) =>
				a.slug === current.slug ? { ...a, [ key ]: value } : a
			),
		} );
	};

	return (
		<Panel
			title={ __( 'Instructions', 'agent-builder' ) }
			footer={ <SaveBar onSave={ onSave } saving={ saving } /> }
		>
			<StatusNotice
				error={ error }
				saved={ saved }
				onDismissSaved={ clearSaved }
			/>
			<p className="agentic-react-lead">
				{ __(
					'Customise greetings and personality notes per agent.',
					'agent-builder'
				) }
			</p>
			{ ! agents.length ? (
				<p>{ __( 'No agents installed.', 'agent-builder' ) }</p>
			) : (
				<>
					<SelectControl
						label={ __( 'Select agent', 'agent-builder' ) }
						value={ current?.slug || '' }
						options={ agents.map( ( a ) => ( {
							label: a.name,
							value: a.slug,
						} ) ) }
						onChange={ setSelected }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ current && (
						<div style={ { marginTop: 16 } }>
							<TextareaControl
								label={ __(
									'Welcome message',
									'agent-builder'
								) }
								value={ current.welcome_message || '' }
								onChange={ ( v ) =>
									update( 'welcome_message', v )
								}
								rows={ 3 }
								__nextHasNoMarginBottom
							/>
							<div style={ { height: 12 } } />
							<TextareaControl
								label={ __(
									'Persona notes',
									'agent-builder'
								) }
								value={ current.notes || '' }
								onChange={ ( v ) => update( 'notes', v ) }
								rows={ 4 }
								__nextHasNoMarginBottom
							/>
							<div style={ { height: 12 } } />
							<TextControl
								label={ __(
									'Response style',
									'agent-builder'
								) }
								value={ current.response_style || '' }
								onChange={ ( v ) =>
									update( 'response_style', v )
								}
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</div>
					) }
				</>
			) }
		</Panel>
	);
}

function ApisTab( { data } ) {
	const services = data.services || [];
	return (
		<Panel title={ __( 'APIs', 'agent-builder' ) }>
			<p className="agentic-react-lead">
				{ __(
					'Third-party API keys used by agent tools.',
					'agent-builder'
				) }
			</p>
			<div className="agentic-react-table-wrap">
				<table className="agentic-react-table">
					<thead>
						<tr>
							<th>{ __( 'Service', 'agent-builder' ) }</th>
							<th>{ __( 'Status', 'agent-builder' ) }</th>
							<th>{ __( 'Key', 'agent-builder' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ services.map( ( s ) => (
							<tr key={ s.slug }>
								<td>
									<strong>{ s.name }</strong>
									{ s.key_url && (
										<div>
											<ExternalLink href={ s.key_url }>
												{ __(
													'Get a key',
													'agent-builder'
												) }
											</ExternalLink>
										</div>
									) }
								</td>
								<td>
									<span
										className={
											'agentic-react-led' +
											( s.configured ? ' is-on' : '' )
										}
									/>{ ' ' }
									{ s.configured
										? __( 'Configured', 'agent-builder' )
										: __(
												'Not configured',
												'agent-builder'
										  ) }
								</td>
								<td>
									<code>{ s.hint || '—' }</code>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</Panel>
	);
}

function EndpointsTab( { data, setData, onSave, saving, error, saved, clearSaved } ) {
	const services = data.services || [];
	const [ tests, setTests ] = useState( {} );

	const updateServiceUrl = ( slug, url ) => {
		setData( {
			...data,
			services: services.map( ( s ) =>
				s.slug === slug ? { ...s, url: String( url ) } : s
			),
		} );
	};

	const resetService = ( slug ) => {
		const svc = services.find( ( s ) => s.slug === slug );
		updateServiceUrl( slug, svc ? svc.default_url : '' );
	};

	const testService = ( slug ) => {
		setTests( ( prev ) => ( {
			...prev,
			[ slug ]: { testing: true, ok: null, message: '' },
		} ) );
		apiFetch( {
			path: REST + '/test-service',
			method: 'POST',
			data: { slug },
		} )
			.then( ( res ) => {
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: !! res.ok,
						message: res.message || '',
					},
				} ) );
			} )
			.catch( ( err ) => {
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: false,
						message:
							err.message ||
							__( 'Test failed.', 'agent-builder' ),
					},
				} ) );
			} );
	};

	return (
		<>
			<Panel title={ __( 'Endpoints', 'agent-builder' ) }>
				<p className="agentic-react-lead">
					{ data.note ||
						__(
							'REST endpoints for integrations.',
							'agent-builder'
						) }
				</p>
				<p>
					<strong>{ __( 'Namespace', 'agent-builder' ) }:</strong>{ ' ' }
					<code>{ data.rest_namespace }</code>
				</p>
				<p>
					<strong>{ __( 'Base URL', 'agent-builder' ) }:</strong>{ ' ' }
					<code>{ data.rest_url }</code>
				</p>
			</Panel>
			<Panel
				title={ __( 'Agentic Services', 'agent-builder' ) }
				footer={
					<SaveBar onSave={ () => onSave() } saving={ saving } />
				}
			>
				<StatusNotice
					error={ error }
					saved={ saved }
					onDismissSaved={ clearSaved }
				/>
				<p className="agentic-react-lead">
					{ __(
						'Base URLs the plugin calls for AI chat, knowledge search, and media generation. Leave these on their defaults unless you’re self-hosting a proxy or support asked you to change one.',
						'agent-builder'
					) }
				</p>
				{ services.map( ( svc ) => {
					const t = tests[ svc.slug ] || {};
					return (
						<div
							key={ svc.slug }
							style={ {
								marginBottom: 16,
								paddingBottom: 16,
								borderBottom: '1px solid #e0e0e0',
							} }
						>
							<div
								style={ {
									display: 'flex',
									alignItems: 'flex-end',
									gap: 8,
								} }
							>
								<div style={ { flexGrow: 1 } }>
									<TextControl
										label={ svc.name }
										help={ svc.description }
										value={ svc.url }
										onChange={ ( v ) =>
											updateServiceUrl( svc.slug, v )
										}
										__nextHasNoMarginBottom
										__next40pxDefaultSize
									/>
								</div>
								<Button
									variant="secondary"
									isBusy={ t.testing }
									disabled={ t.testing }
									onClick={ () => testService( svc.slug ) }
								>
									{ t.testing
										? __( 'Testing…', 'agent-builder' )
										: __( 'Test', 'agent-builder' ) }
								</Button>
							</div>
							{ null !== ( t.ok ?? null ) && (
								<p style={ { margin: '6px 0 0' } }>
									<span
										style={ {
											color: t.ok ? '#008a20' : '#cc1818',
										} }
									>
										{ t.ok ? '✓' : '✗' } { t.message }
									</span>
								</p>
							) }
							{ svc.is_custom && (
								<p style={ { margin: '6px 0 0' } }>
									<span className="agentic-react-lead">
										{ __( 'Default:', 'agent-builder' ) }{ ' ' }
										<code>{ svc.default_url }</code>
									</span>{ ' ' }
									<Button
										variant="link"
										onClick={ () =>
											resetService( svc.slug )
										}
									>
										{ __(
											'Reset to default',
											'agent-builder'
										) }
									</Button>
								</p>
							) }
						</div>
					);
				} ) }
			</Panel>
		</>
	);
}

function MCPTab( { data } ) {
	const agents = data.agents || [];
	const connectors = data.connectors || [];
	const unattendedWrites = data.unattended_writes || [];

	const [ tests, setTests ] = useState( {} );
	const [ copied, setCopied ] = useState( {} );
	const [ credentials, setCredentials ] = useState( data.credentials || [] );
	const [ agentOverrides, setAgentOverrides ] = useState( {} );
	const [ toggling, setToggling ] = useState( {} );

	// data.credentials can still be [] on MCPTab's first render (bootstrap
	// arrives one render before SettingsApp's tabData sync effect catches
	// up), so re-sync once the real prop value shows up.
	useEffect( () => {
		setCredentials( data.credentials || [] );
	}, [ data.credentials ] );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState( '' );
	const [ newCredential, setNewCredential ] = useState( null );
	const [ revoking, setRevoking ] = useState( '' );

	const testAgent = ( slug ) => {
		setTests( ( prev ) => ( {
			...prev,
			[ slug ]: { testing: true, ok: null, message: '' },
		} ) );
		apiFetch( {
			path: REST + '/mcp-test',
			method: 'POST',
			data: { slug },
		} )
			.then( ( res ) => {
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: !! res.ok,
						message: res.message || '',
					},
				} ) );
			} )
			.catch( ( err ) => {
				setTests( ( prev ) => ( {
					...prev,
					[ slug ]: {
						testing: false,
						ok: false,
						message:
							err.message ||
							__( 'Test failed.', 'agent-builder' ),
					},
				} ) );
			} );
	};

	const toggleAgentMcp = ( slug, enabled ) => {
		setToggling( ( prev ) => ( { ...prev, [ slug ]: true } ) );
		apiFetch( {
			path: REST + '/mcp-toggle-agent',
			method: 'POST',
			data: { slug, enabled },
		} )
			.then( ( res ) => {
				setAgentOverrides( ( prev ) => ( {
					...prev,
					[ slug ]: {
						enabled: res.enabled,
						ready: res.ready,
						reason: res.reason,
					},
				} ) );
			} )
			.finally( () => {
				setToggling( ( prev ) => ( { ...prev, [ slug ]: false } ) );
			} );
	};

	const copyUrl = ( slug, url ) => {
		navigator.clipboard.writeText( url ).then( () => {
			setCopied( ( prev ) => ( { ...prev, [ slug ]: true } ) );
			setTimeout( () => {
				setCopied( ( prev ) => ( { ...prev, [ slug ]: false } ) );
			}, 2000 );
		} );
	};

	const createCredential = () => {
		setCreating( true );
		setCreateError( '' );
		apiFetch( { path: REST + '/mcp-create-credential', method: 'POST' } )
			.then( ( res ) => {
				setCreating( false );
				setNewCredential( res );
				setCredentials( ( prev ) => [
					...prev,
					{
						user_id: res.user_id,
						user_login: res.user_login,
						uuid: res.uuid,
						created: res.created,
						last_used: __( 'Never', 'agent-builder' ),
					},
				] );
			} )
			.catch( ( err ) => {
				setCreating( false );
				setCreateError(
					err.message ||
						__( 'Could not create credential.', 'agent-builder' )
				);
			} );
	};

	const revokeCredential = ( cred ) => {
		if (
			! window.confirm(
				__(
					'Revoke this Application Password? Any client using it will stop working immediately.',
					'agent-builder'
				)
			)
		) {
			return;
		}
		setRevoking( cred.uuid );
		apiFetch( {
			path: REST + '/mcp-revoke-credential',
			method: 'POST',
			data: { user_id: cred.user_id, uuid: cred.uuid },
		} )
			.then( () => {
				setRevoking( '' );
				setCredentials( ( prev ) =>
					prev.filter( ( c ) => c.uuid !== cred.uuid )
				);
			} )
			.catch( () => {
				setRevoking( '' );
			} );
	};

	return (
		<>
			<Panel title={ __( 'MCP', 'agent-builder' ) }>
				<p className="agentic-react-lead">
					{ __(
						'The Model Context Protocol (MCP) lets external AI apps — Claude Desktop, Cursor, and others — use your agents directly, tool calls and all. Free on every WordPress version this plugin supports — no Pro or connector required.',
						'agent-builder'
					) }
				</p>
				<p>
					<span className="agentic-react-led is-on" />{ ' ' }
					<strong>{ __( 'Available', 'agent-builder' ) }</strong>
				</p>
			</Panel>

			<Panel title={ __( 'Agent endpoints', 'agent-builder' ) }>
				<p className="agentic-react-lead">
					{ __(
						'Each agent has its own MCP URL, scoped to only the tools that agent is allowed to use.',
						'agent-builder'
					) }
				</p>
				<div className="agentic-react-table-wrap">
					<table className="agentic-react-table">
						<thead>
							<tr>
								<th>{ __( 'Agent', 'agent-builder' ) }</th>
								<th>{ __( 'MCP URL', 'agent-builder' ) }</th>
								<th>{ __( 'Enabled', 'agent-builder' ) }</th>
								<th>{ __( 'Status', 'agent-builder' ) }</th>
								<th>{ __( 'Connected', 'agent-builder' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ agents.map( ( raw ) => {
								const a = { ...raw, ...( agentOverrides[ raw.slug ] || {} ) };
								const t = tests[ a.slug ] || {};
								return (
									<tr key={ a.slug }>
										<td>
											<strong>{ a.name }</strong>
										</td>
										<td>
											<code>{ a.url }</code>{ ' ' }
											<Button
												variant="link"
												onClick={ () =>
													copyUrl( a.slug, a.url )
												}
											>
												{ copied[ a.slug ]
													? __(
															'Copied!',
															'agent-builder'
													  )
													: __(
															'Copy',
															'agent-builder'
													  ) }
											</Button>
										</td>
										<td>
											<ToggleControl
												__nextHasNoMarginBottom
												label={
													<span className="screen-reader-text">
														{ sprintf(
															/* translators: %s: agent name */
															__(
																'Enable MCP: %s',
																'agent-builder'
															),
															a.name
														) }
													</span>
												}
												checked={ !! a.enabled }
												disabled={ !! toggling[ a.slug ] }
												onChange={ ( enabled ) =>
													toggleAgentMcp( a.slug, enabled )
												}
											/>
										</td>
										<td>
											<span
												className={
													'agentic-react-led' +
													( a.ready ? ' is-on' : '' )
												}
											/>{ ' ' }
											{ a.ready
												? __(
														'Ready',
														'agent-builder'
												  )
												: a.reason }
										</td>
										<td>
											{ a.last_connected ||
												__( 'Never connected', 'agent-builder' ) }
										</td>
										<td>
											<Button
												variant="secondary"
												isBusy={ t.testing }
												disabled={
													t.testing || ! a.ready
												}
												onClick={ () =>
													testAgent( a.slug )
												}
											>
												{ t.testing
													? __(
															'Testing…',
															'agent-builder'
													  )
													: __(
															'Test',
															'agent-builder'
													  ) }
											</Button>
											{ null !== ( t.ok ?? null ) && (
												<p
													style={ {
														margin: '6px 0 0',
													} }
												>
													<span
														style={ {
															color: t.ok
																? '#008a20'
																: '#cc1818',
														} }
													>
														{ t.ok ? '✓' : '✗' }{ ' ' }
														{ t.message }
													</span>
												</p>
											) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			</Panel>

			<Panel title={ __( 'Connected clients', 'agent-builder' ) }>
				{ connectors.length ? (
					<p>
						{ connectors.map( ( c ) => (
							<span
								key={ c.slug }
								className="agentic-react-badge"
								style={ { marginRight: 6 } }
							>
								{ c.label }
							</span>
						) ) }
					</p>
				) : (
					<p className="agentic-react-lead">
						{ __(
							'No clients have connected via the approval flow yet.',
							'agent-builder'
						) }
					</p>
				) }
			</Panel>

			<Panel title={ __( 'Application Passwords', 'agent-builder' ) }>
				<p className="agentic-react-lead">
					{ __(
						'Credentials for manually configuring an MCP client (e.g. Cursor) that needs a username and password rather than the browser-driven approval flow.',
						'agent-builder'
					) }
				</p>
				{ createError && (
					<Notice
						status="error"
						isDismissible={ false }
						style={ { marginBottom: 16 } }
					>
						{ createError }
					</Notice>
				) }
				{ newCredential && (
					<Notice
						status="success"
						isDismissible
						onRemove={ () => setNewCredential( null ) }
						style={ { marginBottom: 16 } }
					>
						<p>
							{ __(
								'Password created — copy it now, it will not be shown again:',
								'agent-builder'
							) }
						</p>
						<p>
							<code>{ newCredential.password }</code>{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									navigator.clipboard.writeText(
										newCredential.password
									)
								}
							>
								{ __( 'Copy', 'agent-builder' ) }
							</Button>
						</p>
					</Notice>
				) }
				<div className="agentic-react-table-wrap">
					<table className="agentic-react-table">
						<thead>
							<tr>
								<th>{ __( 'User', 'agent-builder' ) }</th>
								<th>{ __( 'Created', 'agent-builder' ) }</th>
								<th>{ __( 'Last used', 'agent-builder' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ credentials.map( ( c ) => (
								<tr key={ c.uuid }>
									<td>{ c.user_login }</td>
									<td>{ c.created }</td>
									<td>
										{ c.last_used ||
											__( 'Never', 'agent-builder' ) }
									</td>
									<td>
										<Button
											variant="secondary"
											isDestructive
											isBusy={
												revoking === c.uuid
											}
											disabled={
												revoking === c.uuid
											}
											onClick={ () =>
												revokeCredential( c )
											}
										>
											{ __( 'Revoke', 'agent-builder' ) }
										</Button>
									</td>
								</tr>
							) ) }
							{ ! credentials.length && (
								<tr>
									<td colSpan={ 4 }>
										<em className="agentic-react-lead">
											{ __(
												'No Application Passwords yet.',
												'agent-builder'
											) }
										</em>
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</div>
				{ unattendedWrites.length > 0 && (
					<Notice status="warning" isDismissible={ false } style={ { marginBottom: 16 } }>
						<p>
							<strong>
								{ __(
									'Unlike chat, MCP has no per-action confirmation step — any client using this password can immediately perform the following without asking you first each time:',
									'agent-builder'
								) }
							</strong>
						</p>
						<ul style={ { marginTop: 4, marginBottom: 0 } }>
							{ unattendedWrites.map( ( w ) => (
								<li key={ `${ w.agent }:${ w.tool }` }>
									<code>{ w.tool }</code> ({ w.agent }) — { w.description }
								</li>
							) ) }
						</ul>
					</Notice>
				) }
				<p>
					<Button
						variant="primary"
						isBusy={ creating }
						disabled={ creating }
						onClick={ createCredential }
					>
						{ __( 'Create Application Password', 'agent-builder' ) }
					</Button>
				</p>
			</Panel>
		</>
	);
}

function PlaceholderTab( { title, message } ) {
	return (
		<Panel title={ title }>
			<p className="agentic-react-lead">{ message }</p>
		</Panel>
	);
}

/**
 * Load Pro/classic PHP tab HTML into the React Settings shell.
 * Re-runs inline <script> tags (browser ignores them on innerHTML alone).
 */
function ClassicHtmlTab( { tab, title } ) {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ html, setHtml ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		setHtml( '' );
		apiFetch( {
			path: REST + '/classic-tab?tab=' + encodeURIComponent( tab ),
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setHtml( ( res && res.html ) || '' );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setLoading( false );
				setError(
					err.message ||
						__(
							'Could not load this settings panel.',
							'agent-builder'
						)
				);
			} );
		return () => {
			cancelled = true;
		};
	}, [ tab ] );

	useEffect( () => {
		if ( ! html ) {
			return;
		}
		const node = document.getElementById( 'agentic-classic-tab-host' );
		if ( ! node ) {
			return;
		}
		node.innerHTML = html;
		node.querySelectorAll( 'script' ).forEach( ( old ) => {
			const s = document.createElement( 'script' );
			if ( old.src ) {
				s.src = old.src;
			} else {
				s.textContent = old.textContent;
			}
			old.parentNode.replaceChild( s, old );
		} );
		if ( typeof window.agenticInitClassicSettingsTab === 'function' ) {
			window.agenticInitClassicSettingsTab( tab, node );
		}
	}, [ html, tab ] );

	return (
		<Panel title={ title || tab }>
			{ loading && (
				<p>
					<Spinner />{ ' ' }
					{ __( 'Loading…', 'agent-builder' ) }
				</p>
			) }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div
				id="agentic-classic-tab-host"
				className="agentic-classic-settings-tab"
				hidden={ loading || !! error }
			/>
		</Panel>
	);
}

function SettingsApp() {
	const [ boot, setBoot ] = useSettingsBootstrap();
	const embed =
		typeof window !== 'undefined' &&
		window.agenticSettingsBoot &&
		window.agenticSettingsBoot.knowledgeEmbed;
	const forceTab =
		( typeof window !== 'undefined' &&
			window.agenticSettingsBoot &&
			window.agenticSettingsBoot.forceTab ) ||
		'';
	const initialTab = ( () => {
		if ( forceTab ) {
			return forceTab;
		}
		if ( typeof window === 'undefined' ) {
			return 'interface';
		}
		const t =
			new URLSearchParams( window.location.search ).get( 'tab' ) ||
			'interface';
		// Removed tabs → current homes.
		if ( t === 'general' ) {
			return 'interface';
		}
		if ( t === 'global' ) {
			return 'agents';
		}
		// Instructions / Memory live under Knowledge now.
		if ( t === 'instructions' || t === 'memory' ) {
			return 'interface';
		}
		return t;
	} )();
	const [ tab, setTab ] = useState( initialTab );
	const [ filter, setFilter ] = useState( '' );
	const [ tabData, setTabData ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		if ( boot.bootstrap?.data ) {
			setTabData( boot.bootstrap.data );
		}
	}, [ boot.bootstrap ] );

	useEffect( () => {
		if ( embed ) {
			return;
		}
		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', tab );
		window.history.replaceState( {}, '', url.toString() );
	}, [ tab, embed ] );

	const data = tabData[ tab ] || {};

	const setData = ( next ) => {
		setTabData( ( prev ) => ( { ...prev, [ tab ]: next } ) );
		setSaved( false );
	};

	const onSave = ( overrideData ) => {
		setSaving( true );
		setError( '' );
		setSaved( false );
		const payload = { ...( overrideData || data ) };
		if ( tab === 'interface' ) {
			payload.use_theme_accent = ! payload.global_accent;
		}
		if ( tab === 'users' ) {
			// Ensure role matrix is always included from latest privilege state.
			if ( ! payload.role_settings && payload.plugin_privileges ) {
				const plugin = {};
				const agentsMap = {};
				( payload.plugin_privileges || [] ).forEach( ( p ) => {
					plugin[ p.key ] = Array.from(
						new Set( [ ...( p.roles || [] ), 'administrator' ] )
					);
				} );
				( payload.agent_privileges || [] ).forEach( ( p ) => {
					agentsMap[ p.key ] = Array.from(
						new Set( [ ...( p.roles || [] ), 'administrator' ] )
					);
				} );
				payload.role_settings = { plugin, agents: agentsMap };
			}
		}
		saveTab( tab, payload )
			.then( ( res ) => {
				const warnings = Array.isArray( res?.warnings )
					? res.warnings
					: [];
				if ( warnings.length ) {
					window.alert(
						__(
							'Settings saved with warnings:',
							'agent-builder'
						) +
							'\n\n' +
							warnings.join( '\n' )
					);
				}
				if ( res.data ) {
					setTabData( ( prev ) => ( {
						...prev,
						[ tab ]: res.data,
					} ) );
				}
				// Refresh full bootstrap when interface mode changes advanced nav.
				if ( tab === 'interface' ) {
					return apiFetch( { path: REST } ).then( ( bootstrap ) => {
						setBoot( {
							loading: false,
							error: '',
							bootstrap,
						} );
						setTabData( bootstrap.data || {} );
					} );
				}
			} )
			.then( () => {
				setSaving( false );
				setSaved( true );
			} )
			.catch( ( err ) => {
				setSaving( false );
				setError(
					err.message ||
						__( 'Could not save settings.', 'agent-builder' )
				);
			} );
	};

	if ( boot.loading ) {
		return (
			<div className="wrap">
				<p>
					<Spinner />{ ' ' }
					{ __( 'Loading settings…', 'agent-builder' ) }
				</p>
			</div>
		);
	}

	if ( boot.error || ! boot.bootstrap ) {
		return (
			<div className="wrap">
				<Notice status="error" isDismissible={ false }>
					{ boot.error ||
						__( 'Settings unavailable.', 'agent-builder' ) }
				</Notice>
			</div>
		);
	}

	// Full bootstrap refetch — used after a per-tab Basic/Advanced mode
	// change (Users, Interface, Security) or the Settings-page Advanced
	// settings hatch, so is_advanced / is_settings_advanced / assistant
	// reflect the new mode immediately.
	const reloadBootstrap = () =>
		apiFetch( { path: REST } ).then( ( bootstrap ) => {
			setBoot( { loading: false, error: '', bootstrap } );
			setTabData( bootstrap.data || {} );
		} );

	const common = {
		data,
		setData,
		onSave,
		saving,
		error,
		saved,
		clearSaved: () => setSaved( false ),
		reloadBootstrap,
	};

	const classicTabs = Array.isArray( boot.bootstrap?.classic_tabs )
		? boot.bootstrap.classic_tabs
		: [];
	const tabTitles = boot.bootstrap?.tabs || {};

	let body;
	// Pro (and other add-ons) can ship classic PHP tab bodies into the React shell.
	if ( classicTabs.includes( tab ) ) {
		body = (
			<ClassicHtmlTab
				tab={ tab }
				title={ tabTitles[ tab ] || tab }
			/>
		);
	} else {
		switch ( tab ) {
			case 'interface':
			case 'general': // legacy URL → Interface
				body = <InterfaceTab { ...common } />;
				break;
			case 'providers':
				body = <ProvidersTab data={ data } />;
				break;
			case 'security':
				body = <SecurityTab { ...common } />;
				break;
			case 'users':
				body = <UsersTab { ...common } />;
				break;
			case 'memory':
				body = <MemoryTab { ...common } />;
				break;
			case 'agents':
			case 'global': // legacy URL → Agents (chat features moved here)
				body = <AgentsTab { ...common } />;
				break;
			case 'instructions':
				body = <InstructionsTab { ...common } />;
				break;
			case 'apis':
				body = <ApisTab data={ data } />;
				break;
			case 'endpoints':
				body = <EndpointsTab { ...common } />;
				break;
			case 'mcp':
				body = <MCPTab data={ data } />;
				break;
			default:
				body = (
					<PlaceholderTab
						title={ tab }
						message={ __(
							'This tab is available via the classic view or an add-on.',
							'agent-builder'
						) }
					/>
				);
		}
	}

	// Embedded on Knowledge page (Instructions / Memory tabs).
	if ( embed ) {
		return (
			<div className="agentic-admin agentic-settings-embed">
				{ body }
				<SettingsPageFooter tab={ tab } />
			</div>
		);
	}

	return (
		<div className="wrap agentic-admin">
			<AdminPage title={ __( 'Settings', 'agent-builder' ) }>
				<div className="agentic-settings-app">
					<SettingsNav
						bootstrap={ boot.bootstrap }
						active={ tab }
						filter={ filter }
						onChange={ ( action ) => {
							if ( action.type === 'tab' ) {
								setTab( action.value );
								setError( '' );
								setSaved( false );
							}
							if ( action.type === 'filter' ) {
								setFilter( action.value );
							}
							if ( action.type === 'settings-mode' ) {
								const advancedOnly = Array.isArray(
									boot.bootstrap?.advanced_only_tabs
								)
									? boot.bootstrap.advanced_only_tabs
									: [ 'apis', 'endpoints', 'mcp' ];
								reloadBootstrap().then( () => {
									if (
										action.value === 'advanced' &&
										! advancedOnly.includes( tab )
									) {
										setTab( 'apis' );
									}
									if (
										action.value === 'basic' &&
										advancedOnly.includes( tab )
									) {
										setTab( 'interface' );
									}
									setError( '' );
									setSaved( false );
								} );
							}
						} }
					/>
					<div className="agentic-settings-app__main">{ body }</div>
				</div>
			</AdminPage>
			<SettingsPageFooter tab={ tab } />
		</div>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agentic-settings-app-root' );
	if ( root ) {
		createRoot( root ).render( <SettingsApp /> );
	}
} );
