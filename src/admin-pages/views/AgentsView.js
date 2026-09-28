import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Modal,
	Notice,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

// The agent's chat screen. Bundled assistant-trainer keeps its legacy
// dashboard page, everything else opens the shared chat screen.
function chatUrl( slug ) {
	const page = 'assistant-trainer' === slug ? 'agent-builder' : 'agentic-chat';
	return `admin.php?page=${ page }&agent=${ encodeURIComponent( slug ) }`;
}

function assignUrl( slug ) {
	return `admin.php?page=agentic-tasks&agent=${ encodeURIComponent( slug ) }`;
}

function postAction( actionName, extra = {} ) {
	return apiFetch( {
		path: 'agentic/v1/admin-page',
		method: 'POST',
		data: { action_name: actionName, ...extra },
	} );
}

// Shared async helper: run an action, refresh the roster silently, and report
// failures through the passed error setter. Returns the promise so callers can
// chain success handling (e.g. export's download redirect).
function runAction( actionName, body, { reload, onError } ) {
	return postAction( actionName, body )
		.then( ( res ) => {
			if ( typeof reload === 'function' ) {
				reload( { silent: true } );
			}
			return res;
		} )
		.catch( ( e ) =>
			onError(
				e.message || __( 'Something went wrong.', 'agent-builder' )
			)
		);
}

function AgentAvatar( { agent, size } ) {
	const cls = `agentic-agents-card__avatar agentic-agents-card__avatar--${ size || 'md' }`;
	if ( agent.avatar_url ) {
		return (
			<img
				className={ cls }
				src={ agent.avatar_url }
				alt=""
				aria-hidden="true"
			/>
		);
	}
	return (
		<span className={ cls } aria-hidden="true">
			{ agent.avatar_emoji || agent.icon || '🤖' }
		</span>
	);
}

function AgentCard( {
	agent,
	isAdmin,
	busy,
	onEdit,
	onChat,
	onAssign,
	onToggle,
	onDuplicate,
	onExport,
	onDelete,
} ) {
	const desc = ( agent.description || '' ).trim() ||
		__( 'An AI agent for this site.', 'agent-builder' );
	const busyHere = busy === agent.slug;

	return (
		<article
			className={
				'agentic-agents-card' +
				( agent.active ? ' is-active' : '' ) +
				( agent.pinned ? ' is-pinned' : '' )
			}
		>
			<div className="agentic-agents-card__head">
				<AgentAvatar agent={ agent } />
				<div className="agentic-agents-card__identity">
					<h3 className="agentic-agents-card__name">
						{ agent.display_name || agent.slug }
					</h3>
					{ agent.title && (
						<p className="agentic-agents-card__title">
							{ agent.title }
						</p>
					) }
					<p className="agentic-agents-card__slug">
						<code>{ agent.slug }</code>
					</p>
				</div>
				<div className="agentic-agents-card__badges">
					{ agent.pinned && (
						<span className="agentic-agents-card__badge agentic-agents-card__badge--pinned">
							{ __( 'Pinned', 'agent-builder' ) }
						</span>
					) }
					{ agent.active ? (
						<span className="agentic-agents-card__badge agentic-agents-card__badge--active">
							{ __( 'Active', 'agent-builder' ) }
						</span>
					) : (
						<span className="agentic-agents-card__badge">
							{ __( 'Inactive', 'agent-builder' ) }
						</span>
					) }
				</div>
			</div>

			<p className="agentic-agents-card__desc">{ desc }</p>

			<div className="agentic-agents-card__actions">
				{ agent.active ? (
					<Button variant="primary" isBusy={ busyHere } onClick={ onChat }>
						{ __( 'Chat', 'agent-builder' ) }
					</Button>
				) : (
					<Button
						variant="primary"
						isBusy={ busyHere }
						onClick={ onToggle }
					>
						{ __( 'Activate', 'agent-builder' ) }
					</Button>
				) }
				<Button variant="secondary" onClick={ onAssign }>
					{ __( 'Assign task', 'agent-builder' ) }
				</Button>
			</div>

			<div className="agentic-agents-card__row-actions">
				<Button variant="link" isSmall disabled={ !! busy } onClick={ onEdit }>
					{ __( 'Edit', 'agent-builder' ) }
				</Button>
				<Button
					variant="link"
					isSmall
					disabled={ !! busy }
					onClick={ onDuplicate }
				>
					{ __( 'Duplicate', 'agent-builder' ) }
				</Button>
				<Button variant="link" isSmall disabled={ !! busy } onClick={ onExport }>
					{ __( 'Export', 'agent-builder' ) }
				</Button>
				<Button
					variant="link"
					isSmall
					disabled={ !! busy }
					onClick={ onToggle }
				>
					{ agent.active
						? __( 'Deactivate', 'agent-builder' )
						: __( 'Activate', 'agent-builder' ) }
				</Button>
				{ isAdmin && (
					<Button
						variant="link"
						isSmall
						isDestructive
						disabled={ !! busy }
						onClick={ onDelete }
					>
						{ __( 'Delete', 'agent-builder' ) }
					</Button>
				) }
			</div>
		</article>
	);
}

function AgentProfileDrawer( { agent, onClose, onSaved } ) {
	const [ name, setName ] = useState( agent.display_name || '' );
	const [ title, setTitle ] = useState( agent.title || '' );
	const [ standing, setStanding ] = useState( agent.standing_description || '' );
	const [ emoji, setEmoji ] = useState( agent.avatar_emoji || '' );
	const [ avatarId, setAvatarId ] = useState( agent.avatar_id || 0 );
	const [ avatarUrl, setAvatarUrl ] = useState( agent.avatar_url || '' );
	const [ pinned, setPinned ] = useState( !! agent.pinned );
	const [ hidden, setHidden ] = useState( !! agent.hidden );
	const [ saving, setSaving ] = useState( false );
	const [ err, setErr ] = useState( '' );

	const openMedia = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose agent avatar', 'agent-builder' ),
			library: { type: 'image' },
			multiple: false,
			button: { text: __( 'Use this image', 'agent-builder' ) },
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			setAvatarId( att.id );
			setAvatarUrl(
				( att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ) ||
					att.url
			);
		} );
		frame.open();
	};

	const save = () => {
		if ( saving ) {
			return;
		}
		setSaving( true );
		setErr( '' );
		postAction( 'agent_profile_save', {
			slug: agent.slug,
			profile_display_name: name,
			profile_title: title,
			persona_notes: standing,
			profile_avatar_id: avatarId,
			profile_avatar_emoji: emoji,
			profile_pinned: pinned,
			profile_hidden: hidden,
		} )
			.then( () => {
				setSaving( false );
				onSaved();
			} )
			.catch( ( e ) => {
				setSaving( false );
				setErr(
					e.message ||
						__( 'Could not save the profile.', 'agent-builder' )
				);
			} );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: agent display name */
				__( 'Edit profile: %s', 'agent-builder' ),
				agent.display_name || agent.slug
			) }
			onRequestClose={ onClose }
			className="agentic-agents-drawer"
		>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }

			<div className="agentic-agents-drawer__avatar">
				<AgentAvatar
					agent={ {
						avatar_url: avatarUrl,
						avatar_emoji: emoji,
						icon: agent.icon,
					} }
					size="lg"
				/>
				<div className="agentic-agents-drawer__avatar-actions">
					<Button variant="secondary" onClick={ openMedia }>
						{ __( 'Set image', 'agent-builder' ) }
					</Button>
					{ ( avatarId || avatarUrl ) && (
						<Button
							variant="link"
							isDestructive
							onClick={ () => {
								setAvatarId( 0 );
								setAvatarUrl( '' );
							} }
						>
							{ __( 'Remove image', 'agent-builder' ) }
						</Button>
					) }
				</div>
			</div>

			<TextControl
				label={ __( 'Name', 'agent-builder' ) }
				value={ name }
				onChange={ setName }
				help={ __(
					'Leave empty to use the agent’s built-in name.',
					'agent-builder'
				) }
			/>
			<TextControl
				label={ __( 'Title', 'agent-builder' ) }
				value={ title }
				onChange={ setTitle }
			/>
			<TextareaControl
				label={ __( 'Standing description', 'agent-builder' ) }
				value={ standing }
				onChange={ setStanding }
				help={ __(
					'Shown where this agent is introduced to other agents.',
					'agent-builder'
				) }
			/>
			<TextControl
				label={ __( 'Emoji avatar', 'agent-builder' ) }
				value={ emoji }
				onChange={ setEmoji }
				help={ __(
					'Used when no image is set.',
					'agent-builder'
				) }
			/>

			<div className="agentic-agents-drawer__toggles">
				<ToggleControl
					label={ __( 'Pin to top', 'agent-builder' ) }
					checked={ pinned }
					onChange={ setPinned }
				/>
				<ToggleControl
					label={ __( 'Hide agent', 'agent-builder' ) }
					checked={ hidden }
					onChange={ setHidden }
				/>
			</div>

			<div className="agentic-agents-drawer__actions">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'agent-builder' ) }
				</Button>
				<Button variant="primary" isBusy={ saving } disabled={ saving } onClick={ save }>
					{ __( 'Save profile', 'agent-builder' ) }
				</Button>
			</div>
		</Modal>
	);
}

function QuickCreateModal( { onClose, onCreated } ) {
	const [ name, setName ] = useState( '' );
	const [ what, setWhat ] = useState( '' );
	const [ rules, setRules ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState( '' );

	const create = () => {
		if ( busy ) {
			return;
		}
		if ( ! name.trim() ) {
			setErr( __( 'A name is required.', 'agent-builder' ) );
			return;
		}
		if ( ! what.trim() ) {
			setErr(
				__(
					'A short description is required.',
					'agent-builder'
				)
			);
			return;
		}
		setBusy( true );
		setErr( '' );
		apiFetch( {
			path: 'agentic/v1/agent-wizard/create',
			method: 'POST',
			data: {
				name: name.trim(),
				description: what.trim(),
				system_prompt: rules.trim(),
			},
		} )
			.then( ( res ) => {
				setBusy( false );
				onCreated( res );
			} )
			.catch( ( e ) => {
				setBusy( false );
				setErr(
					e.message ||
						__( 'Could not create the agent.', 'agent-builder' )
				);
			} );
	};

	return (
		<Modal
			title={ __( 'New agent', 'agent-builder' ) }
			onRequestClose={ onClose }
			className="agentic-agents-create-modal"
		>
			{ err && (
				<Notice status="error" isDismissible={ false }>
					{ err }
				</Notice>
			) }
			<TextControl
				label={ __( 'Name', 'agent-builder' ) }
				value={ name }
				onChange={ setName }
				placeholder={ __( 'Support assistant', 'agent-builder' ) }
			/>
			<TextareaControl
				label={ __( 'What should it do?', 'agent-builder' ) }
				value={ what }
				onChange={ setWhat }
				placeholder={ __(
					'Answers customer questions using our knowledge base.',
					'agent-builder'
				) }
			/>
			<TextareaControl
				label={ __( 'Rules', 'agent-builder' ) }
				value={ rules }
				onChange={ setRules }
				help={ __(
					'Optional. Standing instructions the agent always follows.',
					'agent-builder'
				) }
			/>
			<div className="agentic-agents-drawer__actions">
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'agent-builder' ) }
				</Button>
				<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ create }>
					{ __( 'Create agent', 'agent-builder' ) }
				</Button>
			</div>
		</Modal>
	);
}

function ImportModal( { data, onClose } ) {
	return (
		<Modal
			title={ __( 'Import agent', 'agent-builder' ) }
			onRequestClose={ onClose }
			className="agentic-agents-import-modal"
		>
			<p className="agentic-react-muted">
				{ __(
					'Choose a .zip agent template exported from this plugin.',
					'agent-builder'
				) }
			</p>
			<form
				method="post"
				action={ data.import_url }
				encType="multipart/form-data"
			>
				<input type="hidden" name="action" value="agentic_import_agent" />
				<input
					type="hidden"
					name="agentic_agent_import_nonce"
					value={ data.import_nonce }
				/>
				<input
					type="file"
					name="agentic_agent_file"
					accept=".zip,application/zip"
					required
				/>
				<div className="agentic-agents-drawer__actions">
					<Button variant="secondary" onClick={ onClose }>
						{ __( 'Cancel', 'agent-builder' ) }
					</Button>
					<Button variant="primary" type="submit">
						{ __( 'Import', 'agent-builder' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

function AdvancedTable( {
	agents,
	order,
	setOrder,
	dragIndex,
	setDragIndex,
	onReorder,
	busy,
} ) {
	const bySlug = {};
	agents.forEach( ( a ) => {
		bySlug[ a.slug ] = a;
	} );

	const handleDragStart = ( e, index ) => {
		setDragIndex( index );
		e.dataTransfer.effectAllowed = 'move';
	};
	const handleDragOver = ( e ) => {
		e.preventDefault();
		e.dataTransfer.dropEffect = 'move';
	};
	const handleDrop = ( e, index ) => {
		e.preventDefault();
		const from = dragIndex;
		if ( from === null || from === index ) {
			setDragIndex( null );
			return;
		}
		const next = [ ...order ];
		const [ moved ] = next.splice( from, 1 );
		next.splice( index, 0, moved );
		setOrder( next );
		setDragIndex( null );
		onReorder( next );
	};

	return (
		<div className="agentic-react-table-wrap">
			<table className="agentic-react-table agentic-agents-table">
				<thead>
					<tr>
						<th className="agentic-agents-table__handle" />
						<th>{ __( 'Agent', 'agent-builder' ) }</th>
						<th>{ __( 'Slug', 'agent-builder' ) }</th>
						<th>{ __( 'Version', 'agent-builder' ) }</th>
						<th>{ __( 'Author', 'agent-builder' ) }</th>
						<th>{ __( 'Source', 'agent-builder' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ order.map( ( slug, index ) => {
						const a = bySlug[ slug ];
						if ( ! a ) {
							return null;
						}
						return (
							<tr
								key={ slug }
								draggable
								onDragStart={ ( e ) => handleDragStart( e, index ) }
								onDragOver={ handleDragOver }
								onDrop={ ( e ) => handleDrop( e, index ) }
								onDragEnd={ () => setDragIndex( null ) }
								className={
									( dragIndex === index
										? 'is-dragging'
										: '' ) +
									( a.hidden ? ' is-hidden' : '' )
								}
							>
								<td className="agentic-agents-table__handle">
									<span
										className="agentic-agents-table__grip"
										aria-hidden="true"
									>
										⠿
									</span>
								</td>
								<td>
									<strong>
										{ a.display_name || a.slug }
									</strong>
									{ a.active ? (
										<span className="agentic-agents-table__active">
											{ __( 'Active', 'agent-builder' ) }
										</span>
									) : null }
								</td>
								<td>
									<code>{ a.slug }</code>
								</td>
								<td>{ a.version || '—' }</td>
								<td>{ a.author || '—' }</td>
								<td>{ a.source }</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>
			{ busy && (
				<p className="agentic-react-muted">
					{ __( 'Saving order…', 'agent-builder' ) }
				</p>
			) }
		</div>
	);
}

function AgentsView( { data, reload } ) {
	const agents = data.agents || [];
	const isAdmin = !! data.is_admin;
	const isAdvanced = !! data.is_advanced;

	const [ busy, setBusy ] = useState( '' );
	const [ err, setErr ] = useState( '' );
	const [ ok, setOk ] = useState( '' );
	const [ editing, setEditing ] = useState( null ); // agent slug
	const [ creating, setCreating ] = useState( false );
	const [ importing, setImporting ] = useState( false );
	const [ reordering, setReordering ] = useState( false );
	const [ dragIndex, setDragIndex ] = useState( null );

	const serverKey = agents.map( ( a ) => a.slug ).join( '|' );
	const [ order, setOrder ] = useState( () => agents.map( ( a ) => a.slug ) );
	useEffect( () => {
		setOrder( agents.map( ( a ) => a.slug ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ serverKey ] );

	// Surface the import round-trip result (the admin-post handler redirects
	// back here with ?imported=<slug> or ?import_error=1).
	useEffect( () => {
		const sp = new URLSearchParams( window.location.search );
		if ( sp.get( 'imported' ) ) {
			setOk(
				sprintf(
					/* translators: %s: imported agent slug */
					__( 'Agent “%s” imported.', 'agent-builder' ),
					sp.get( 'imported' )
				)
			);
		} else if ( sp.get( 'import_error' ) ) {
			setErr( __( 'Import failed. Check the .zip file and try again.', 'agent-builder' ) );
		}
	}, [] );

	const clear = () => {
		setErr( '' );
		setOk( '' );
	};

	const visible = agents.filter( ( a ) => ! a.hidden );
	const hidden = agents.filter( ( a ) => a.hidden );

	const onError = ( msg ) => setErr( msg );

	const toggle = ( agent ) => {
		setBusy( agent.slug );
		setErr( '' );
		runAction( 'agent_toggle', { slug: agent.slug, active: ! agent.active }, {
			reload,
			onError,
		} ).finally( () => setBusy( '' ) );
	};

	const duplicate = ( agent ) => {
		setBusy( agent.slug );
		setErr( '' );
		setOk( '' );
		runAction( 'agent_duplicate', { slug: agent.slug }, { reload, onError } )
			.then( ( res ) => {
				if ( res && res.slug ) {
					setOk(
						sprintf(
							/* translators: %s: duplicated agent slug */
							__( 'Duplicated as “%s”.', 'agent-builder' ),
							res.slug
						)
					);
				}
			} )
			.finally( () => setBusy( '' ) );
	};

	const doExport = ( agent ) => {
		setBusy( agent.slug );
		setErr( '' );
		postAction( 'agent_export', { slug: agent.slug } )
			.then( ( res ) => {
				if ( res && res.url ) {
					window.location.href = res.url;
				}
			} )
			.catch( ( e ) => setErr( e.message || __( 'Export failed.', 'agent-builder' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const doDelete = ( agent ) => {
		const name = agent.display_name || agent.slug;
		if (
			! window.confirm(
				sprintf(
					/* translators: %s: agent name */
					__( 'Delete “%s”? This removes the agent and its files.', 'agent-builder' ),
					name
				)
			)
		) {
			return;
		}
		setBusy( agent.slug );
		setErr( '' );
		postAction( 'agent_delete', { slug: agent.slug } )
			.then( () => {
				setOk(
					sprintf(
						/* translators: %s: deleted agent name */
						__( 'Deleted “%s”.', 'agent-builder' ),
						name
					)
				);
				reload( { silent: true } );
			} )
			.catch( ( e ) => setErr( e.message || __( 'Delete failed.', 'agent-builder' ) ) )
			.finally( () => setBusy( '' ) );
	};

	const reorder = ( slugs ) => {
		setReordering( true );
		setErr( '' );
		postAction( 'agent_reorder', { slugs } )
			.then( () => {
				if ( typeof reload === 'function' ) {
					reload( { silent: true } );
				}
			} )
			.catch( ( e ) => setErr( e.message || __( 'Reorder failed.', 'agent-builder' ) ) )
			.finally( () => setReordering( false ) );
	};

	const onCreated = ( res ) => {
		setCreating( false );
		if ( res && res.slug ) {
			setOk(
				sprintf(
					/* translators: %s: new agent name */
					__( 'Created “%s”.', 'agent-builder' ),
					res.name || res.slug
				)
			);
		}
		reload( { silent: true } );
	};

	const editingAgent = editing
		? agents.find( ( a ) => a.slug === editing )
		: null;

	const renderCard = ( agent ) => (
		<AgentCard
			key={ agent.slug }
			agent={ agent }
			isAdmin={ isAdmin }
			busy={ busy }
			onEdit={ () => setEditing( agent.slug ) }
			onChat={ () => {
				window.location.href = chatUrl( agent.slug );
			} }
			onAssign={ () => {
				window.location.href = assignUrl( agent.slug );
			} }
			onToggle={ () => toggle( agent ) }
			onDuplicate={ () => duplicate( agent ) }
			onExport={ () => doExport( agent ) }
			onDelete={ () => doDelete( agent ) }
		/>
	);

	let main;
	if ( ! agents.length ) {
		main = (
			<p className="agentic-react-muted">
				{ __( 'No agents installed yet.', 'agent-builder' ) }
			</p>
		);
	} else if ( isAdvanced ) {
		main = (
			<AdvancedTable
				agents={ agents }
				order={ order }
				setOrder={ setOrder }
				dragIndex={ dragIndex }
				setDragIndex={ setDragIndex }
				onReorder={ reorder }
				busy={ reordering }
			/>
		);
	} else {
		main = (
			<>
				<div className="agentic-agents-grid">
					{ visible.map( renderCard ) }
				</div>
				{ hidden.length ? (
					<details className="agentic-agents-hidden">
						<summary>
							{ sprintf(
								/* translators: %d: hidden agent count */
								__( 'Hidden agents (%d)', 'agent-builder' ),
								hidden.length
							) }
						</summary>
						<div className="agentic-agents-grid">
							{ hidden.map( renderCard ) }
						</div>
					</details>
				) : null }
			</>
		);
	}

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

			<div className="agentic-agents-toolbar">
				{ isAdmin && (
					<Button variant="primary" onClick={ () => setCreating( true ) }>
						{ __( 'New agent', 'agent-builder' ) }
					</Button>
				) }
				{ isAdmin && (
					<Button variant="secondary" onClick={ () => setImporting( true ) }>
						{ __( 'Import', 'agent-builder' ) }
					</Button>
				) }
				<span className="agentic-react-muted">
					{ isAdvanced
						? __(
								'Advanced view: drag rows to set the roster order.',
								'agent-builder'
						  )
						: __(
								'Pinned agents first, hidden agents collapsed below.',
								'agent-builder'
						  ) }
				</span>
			</div>

			{ main }

			{ editingAgent && (
				<AgentProfileDrawer
					agent={ editingAgent }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						setOk( __( 'Profile saved.', 'agent-builder' ) );
						if ( typeof reload === 'function' ) {
							reload( { silent: true } );
						}
					} }
				/>
			) }

			{ creating && (
				<QuickCreateModal
					onClose={ () => setCreating( false ) }
					onCreated={ onCreated }
				/>
			) }

			{ importing && (
				<ImportModal
					data={ data }
					onClose={ () => setImporting( false ) }
				/>
			) }
		</>
	);
}

export default AgentsView;
