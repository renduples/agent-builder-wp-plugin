/**
 * Multi-page React admin surfaces (tools, skills list, approvals, logs, etc.).
 * Agents list intentionally excluded (WordPress plugins-style UI later).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import { AdminPage, Panel, ScreenModeToggle } from '../shared/components';
import { ChatEmbed } from '../shared/chat-embed';
import { usePageData } from './hooks';
import { SCREENS_WITH_MODE } from './constants';
import { bootConfig } from './util';
import AdminPageFooter from './views/AdminPageFooter';
import ToolsView from './views/ToolsView';
import SkillsView from './views/SkillsView';
import ApprovalsView from './views/ApprovalsView';
import LogsView from './views/LogsView';
import DeploymentView from './views/DeploymentView';
import TrainView from './views/TrainView';
import AgentReadyView from './views/AgentReadyView';
import SafetyCenterView from './views/SafetyCenterView';

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
