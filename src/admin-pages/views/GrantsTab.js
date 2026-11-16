import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

// Always-allow tool grants for the current admin, exposed by GET/DELETE on the
// /agentic/v1/tool-grants routes in class-rest-api.php.
const GRANTS_PATH = 'agentic/v1/tool-grants';

function GrantsTab() {
	const [ grants, setGrants ] = useState( null ); // null = still loading
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	const [ busyTool, setBusyTool ] = useState( '' );

	const loadGrants = () => {
		apiFetch( { path: GRANTS_PATH } )
			.then( ( res ) => setGrants( res.grants || [] ) )
			.catch( ( e ) => {
				setGrants( [] );
				setErr(
					e.message || __( 'Could not load grants.', 'siteloom' )
				);
			} );
	};

	useEffect( () => {
		loadGrants();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const clear = () => {
		setErr( '' );
		setOk( '' );
	};

	// A grant entry is either a bare tool slug (grants every agent) or a
	// `tool@agent` key (grants only that agent). The `@` is left literal in the
	// request path: WP REST does not URL-decode route segments, so encoding it
	// to %40 would fail to match the route and 404. The literal `@` is what the
	// backend route regex matches.
	const revoke = ( tool ) => {
		if (
			! window.confirm(
				__(
					'Stop always allowing this tool? Agents will ask again before running it.',
					'siteloom'
				)
			)
		) {
			return;
		}
		setBusyTool( tool );
		setErr( '' );
		setOk( '' );
		apiFetch( {
			path: `${ GRANTS_PATH }/${ tool }`,
			method: 'DELETE',
		} )
			.then( () => {
				setOk( __( 'Grant revoked.', 'siteloom' ) );
				loadGrants();
			} )
			.catch( ( e ) =>
				setErr(
					e.message ||
						__( 'Could not revoke the grant.', 'siteloom' )
				)
			)
			.finally( () => setBusyTool( '' ) );
	};

	return (
		<>
			{ err && (
				<Notice status="error" isDismissible onRemove={ clear }>
					{ err }
				</Notice>
			) }
			{ ok && (
				<Notice status="success" isDismissible onRemove={ clear }>
					{ ok }
				</Notice>
			) }

			<p className="agentic-react-muted">
				{ __(
					'Always-allow grants let an agent run a tool without asking first. Revoke one to make that agent pause for approval again.',
					'siteloom'
				) }
			</p>

			{ grants === null ? (
				<p>
					<Spinner /> { __( 'Loading grants…', 'siteloom' ) }
				</p>
			) : ! grants.length ? (
				<div className="agentic-react-approvals-empty">
					<p>
						<strong>{ __( 'No grants yet', 'siteloom' ) }</strong>
					</p>
					<p className="agentic-react-muted">
						{ __(
							'When you choose “Allow always” on an approval, it will show up here.',
							'siteloom'
						) }
					</p>
				</div>
			) : (
				<div className="agentic-react-table-wrap">
					<table className="agentic-react-table agentic-grants-table">
						<thead>
							<tr>
								<th>{ __( 'Grant', 'siteloom' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ grants.map( ( tool ) => {
								const at = tool.indexOf( '@' );
								const slug = at === -1 ? tool : tool.slice( 0, at );
								const agent = at === -1 ? '' : tool.slice( at + 1 );
								const busy = busyTool === tool;
								const label =
									at === -1
										? sprintf(
												/* translators: %s: tool slug */
												__( '%s — all agents', 'siteloom' ),
												slug
										  )
										: sprintf(
												/* translators: 1: tool slug, 2: agent slug */
												__(
													'%1$s — only for %2$s',
													'siteloom'
												),
												slug,
												agent
										  );
								return (
									<tr key={ tool }>
										<td>{ label }</td>
										<td>
											<div className="agentic-agents-card__row-actions">
												<Button
													variant="link"
													isSmall
													isDestructive
													disabled={ busy }
													onClick={ () => revoke( tool ) }
												>
													{ __( 'Revoke', 'siteloom' ) }
												</Button>
											</div>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }
		</>
	);
}

export default GrantsTab;
