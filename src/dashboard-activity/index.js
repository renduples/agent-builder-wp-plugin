/**
 * Dashboard Activity card — React island.
 *
 * Live metrics from `agentic/v1/dashboard-stats` (last 7 days). Header links to
 * the full Activity / audit log page. The rest of the dashboard remains server-rendered.
 */
import { createRoot, useEffect, useState, Fragment } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const REFRESH_MS = 30000;
/** Fixed window for dashboard summary metrics. */
const STATS_PERIOD = 'week';

function getConfig() {
	return window.agenticDashboard || {};
}

function buildUrl( page, extra ) {
	const cfg = getConfig();
	const base = cfg.adminUrl || '';
	let url = `${ base }admin.php?page=${ page }`;
	if ( extra ) {
		url += extra;
	}
	return url;
}

function formatInt( value ) {
	return Number( value || 0 ).toLocaleString();
}

function Metric( { value, label, href } ) {
	return (
		<div className="agentic-metric">
			<div className="agentic-metric-label">
				{ href ? <a href={ href }>{ label }</a> : label }
			</div>
			<div className="agentic-metric-value">{ value }</div>
		</div>
	);
}

function ActivityCard() {
	const cfg = getConfig();
	const isPro = !! cfg.isPro;
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( false );
	const activityLogUrl = buildUrl( 'agentic-audit-log' );

	useEffect( () => {
		let cancelled = false;

		const load = () => {
			apiFetch( {
				path: `agentic/v1/dashboard-stats?period=${ STATS_PERIOD }`,
			} )
				.then( ( res ) => {
					if ( ! cancelled ) {
						setData( res );
						setError( false );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setError( true );
					}
				} );
		};

		load();
		const timer = setInterval( load, REFRESH_MS );
		return () => {
			cancelled = true;
			clearInterval( timer );
		};
	}, [] );

	const activity = data?.activity || {};
	const agents = data?.agents || {};
	const usageLink = isPro
		? buildUrl( 'agentic-costs' )
		: activityLogUrl;

	return (
		<Fragment>
			<div className="agentic-card-header agentic-flex-between">
				<h2>{ __( 'Activity', 'siteloom' ) }</h2>
				<a
					className="agentic-card-header-link"
					href={ activityLogUrl }
				>
					{ __( 'View Activity →', 'siteloom' ) }
				</a>
			</div>

			{ error && (
				<p className="agentic-status-error">
					{ __( 'Could not load activity.', 'siteloom' ) }
				</p>
			) }

			<div className="agentic-metrics-grid">
				<Metric
					value={ formatInt( activity.actions ) }
					label={ __( 'Total Actions', 'siteloom' ) }
					href={ activityLogUrl }
				/>
				<Metric
					value={ formatInt( activity.tokens ) }
					label={ __( 'Tokens Used', 'siteloom' ) }
					href={ usageLink }
				/>
				{ Number( activity.cost ) > 0 && (
					<Metric
						value={ '$' + Number( activity.cost ).toFixed( 4 ) }
						label={
							isPro
								? __( 'Est. Cost', 'siteloom' )
								: __( 'Estimated Usage', 'siteloom' )
						}
						href={ usageLink }
					/>
				) }
				<Metric
					value={ formatInt( agents.active ) }
					label={ __( 'Active Agents', 'siteloom' ) }
					href={ buildUrl( 'agentic-agents' ) }
				/>
				<Metric
					value={ formatInt( agents.uploaded ) }
					label={ __( 'Uploaded Agents', 'siteloom' ) }
					href={ buildUrl( 'agentic-agents' ) }
				/>
				<Metric
					value={ formatInt( agents.user_created ) }
					label={ __( 'User-Created Agents', 'siteloom' ) }
					href={ buildUrl( 'agentic-agents' ) }
				/>
				<Metric
					value={ formatInt( agents.community ) }
					label={ __( 'Community Agents', 'siteloom' ) }
					href="https://agentic-plugin.com/community-agents/"
				/>
			</div>
		</Fragment>
	);
}

document.addEventListener( 'DOMContentLoaded', () => {
	const mount = document.getElementById( 'agentic-dashboard-activity-root' );
	if ( mount ) {
		createRoot( mount ).render( <ActivityCard /> );
	}
} );
