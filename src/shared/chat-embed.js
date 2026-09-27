/**
 * Reusable Basic-mode chat embed for React admin screens.
 *
 * Same "small dedicated component hitting agentic/v1/chat directly" pattern
 * assets/js/editor-sidebar.js proved out for the Gutenberg sidebar, adapted
 * to this codebase's real React/JSX toolchain and generalized so any screen
 * can embed a chat with one of its bundled agents instead of a raw form/list
 * — first used by Skills (Skills Assistant), now shared by other screens.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

// Human labels for the key-argument summary rows on a Proposed Change card.
// Mirrors the summary labels in assets/js/chat.js and assets/js/chat-overlay.js.
const PROPOSAL_SUMMARY_LABELS = {
	title: __( 'Title', 'agent-builder' ),
	status: __( 'Status', 'agent-builder' ),
	post_type: __( 'Post type', 'agent-builder' ),
	post_id: __( 'Post', 'agent-builder' ),
};

function generateChatSessionId() {
	return Math.random().toString( 36 ).slice( 2, 10 );
}

function escapeHtml( text ) {
	return ( text || '' )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#039;' );
}

// Validate a link URL against a safe-scheme allowlist and make it safe to
// interpolate into href="…". Returns the attribute-safe URL, or null when the
// scheme is disallowed (javascript:, data:, vbscript:, etc.). Scheme detection
// mirrors the URL Standard: strip ASCII tab/newline/CR and surrounding C0
// control + space first, so a "java\tscript:"-style obfuscation can't
// masquerade as a relative URL.
function safeLinkHref( url ) {
	const stripped = url
		.replace( /[\t\n\r]/g, '' )
		.replace( /^[\x00-\x20]+|[\x00-\x20]+$/g, '' );
	const scheme = stripped.match( /^([a-z][a-z0-9+.\-]*):/i );
	if ( scheme ) {
		const name = scheme[ 1 ].toLowerCase();
		if ( name !== 'http' && name !== 'https' && name !== 'mailto' ) {
			return null;
		}
	}
	// escapeHtml() has already escaped &, <, >, " and ' in the URL; these two
	// replacements keep the three renderers behaving identically (no-op here).
	return url.replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
}

// Mirrors assets/js/chat.js's renderMarkdown(): escape first, then apply a
// small set of safe substitutions on the already-escaped text. Kept in lockstep
// with chat.js/chat-overlay.js — see the note in the PR about the one deliberate
// divergence (this React embed renders code blocks without a Copy button).
function renderChatMarkdown( text ) {
	if ( ! text ) {
		return '';
	}
	let h = escapeHtml( text );

	// Extract fenced code blocks into placeholders FIRST — before any other
	// substitution (headers, lists, bold, links, tables) — so the raw code is
	// never rewritten into markdown markup, and the single-newline pass below
	// can't turn the code's own line breaks into <br>. Restored at the very end.
	const codeNonce =
		'agentic_code_' +
		Math.random().toString( 36 ).slice( 2 ) +
		Date.now().toString( 36 ) +
		'_';
	const codeBlocks = [];
	h = h.replace( /```(\w*)\n([\s\S]*?)```/g, ( block, lang, code ) => {
		codeBlocks.push( { lang, code } );
		return codeNonce + ( codeBlocks.length - 1 );
	} );

	h = h.replace( /`([^`]+)`/g, '<code>$1</code>' );

	// Tables — process before headers/lists to avoid conflicts.
	h = h.replace( /(^\|.+\|$\n?)+/gm, ( tableBlock ) => {
		const rows = tableBlock.trim().split( '\n' );
		if ( rows.length < 2 ) {
			return tableBlock;
		}
		const sepIndex = rows.findIndex( ( r ) =>
			/^\|[\s:]*-{2,}[\s:]*\|/.test( r )
		);
		if ( sepIndex === -1 ) {
			return tableBlock;
		}
		let tableHtml = '<table>';
		tableHtml += '<thead>';
		for ( let i = 0; i < sepIndex; i++ ) {
			const cells = rows[ i ].split( '|' ).slice( 1, -1 );
			tableHtml +=
				'<tr>' +
				cells
					.map( ( c ) => '<th>' + c.trim() + '</th>' )
					.join( '' ) +
				'</tr>';
		}
		tableHtml += '</thead>';
		if ( sepIndex + 1 < rows.length ) {
			tableHtml += '<tbody>';
			for ( let i = sepIndex + 1; i < rows.length; i++ ) {
				if ( ! rows[ i ].trim() ) {
					continue;
				}
				const cells = rows[ i ].split( '|' ).slice( 1, -1 );
				tableHtml +=
					'<tr>' +
					cells
						.map( ( c ) => '<td>' + c.trim() + '</td>' )
						.join( '' ) +
					'</tr>';
			}
			tableHtml += '</tbody>';
		}
		tableHtml += '</table>';
		return tableHtml;
	} );

	h = h.replace( /^-{3,}$/gm, '<hr>' );

	h = h.replace( /^#### (.*$)/gm, '<h4>$1</h4>' );
	h = h.replace( /^### (.*$)/gm, '<h3>$1</h3>' );
	h = h.replace( /^## (.*$)/gm, '<h2>$1</h2>' );
	h = h.replace( /^# (.*$)/gm, '<h1>$1</h1>' );

	h = h.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
	h = h.replace( /\*([^*]+)\*/g, '<em>$1</em>' );

	h = h.replace( /\[([^\]]+)\]\(([^)]+)\)/g, ( match, label, url ) => {
		const href = safeLinkHref( url );
		if ( href === null ) {
			return label;
		}
		return (
			'<a href="' +
			href +
			'" target="_blank" rel="noopener">' +
			label +
			'</a>'
		);
	} );

	h = h.replace( /^\s*[-*]\s+(.*)$/gm, '<li>$1</li>' );
	h = h.replace( /(<li>.*<\/li>\n?)+/g, '<ul>$&</ul>' );
	h = h.replace( /<\/li>\n<li>/g, '</li><li>' );
	h = h.replace( /(<\/li>)\n(<\/ul>)/g, '$1$2' );

	h = h.replace( /(?:^[ \t]*\d+\.\s+.*(?:\n|$))+/gm, ( block ) => {
		const items = block
			.split( '\n' )
			.filter( ( line ) => /^[ \t]*\d+\.\s+/.test( line ) );
		return (
			'<ol>' +
			items
				.map(
					( line ) =>
						'<li>' +
						line.replace( /^[ \t]*\d+\.\s+/, '' ) +
						'</li>'
				)
				.join( '' ) +
			'</ol>'
		);
	} );

	h = h.replace( /^>\s+(.*)$/gm, '<blockquote>$1</blockquote>' );

	h = h.replace( /\n\n/g, '</p><p>' );
	h = '<p>' + h + '</p>';
	h = h.replace( /<p><\/p>/g, '' );
	h = h.replace( /<p>(<h[1-6]>)/g, '$1' );
	h = h.replace( /(<\/h[1-6]>)<\/p>/g, '$1' );
	h = h.replace( /<p>(<ul>)/g, '$1' );
	h = h.replace( /(<\/ul>)<\/p>/g, '$1' );
	h = h.replace( /<p>(<ol>)/g, '$1' );
	h = h.replace( /(<\/ol>)<\/p>/g, '$1' );
	h = h.replace( /<p>(<blockquote>)/g, '$1' );
	h = h.replace( /(<\/blockquote>)<\/p>/g, '$1' );
	h = h.replace( /<p>(<table>)/g, '$1' );
	h = h.replace( /(<\/table>)<\/p>/g, '$1' );
	h = h.replace( /<p>(<hr>)/g, '$1' );
	h = h.replace( /(<hr>)<\/p>/g, '$1' );
	// Unwrap the code placeholder from its paragraph — the real <pre> block is
	// restored after the newline pass below, so the token, not the block, must
	// escape <p>.
	h = h.replace(
		new RegExp( '<p>(' + codeNonce + '\\d+)</p>', 'g' ),
		'$1'
	);

	// Preserve single line breaks inside a paragraph. Code blocks are held aside
	// as placeholders, so their inner newlines survive.
	h = h.replace( /\n/g, '<br>' );

	// Restore the code blocks now that every substitution and the newline pass
	// have run, so none of them ever saw the raw code content.
	h = h.replace( new RegExp( codeNonce + '(\\d+)', 'g' ), ( m, i ) => {
		const b = codeBlocks[ parseInt( i, 10 ) ];
		return b === undefined
			? m
			: '<pre><code>' + b.code + '</code></pre>';
	} );

	return h;
}

/**
 * Renders a pending_proposal/proposal card the same way the vanilla chat
 * surfaces (chat.js, chat-overlay.js) do — once/session/always/deny for a
 * medium-risk "proposal", approve/reject for a high-risk "approval" — POSTing
 * the decision to the same REST endpoints they use.
 */
export function ProposalCard( { proposal, sessionId } ) {
	const [ busy, setBusy ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ isError, setIsError ] = useState( false );
	const [ showDiff, setShowDiff ] = useState( false );
	const isApproval = proposal.kind === 'approval';

	const decide = async ( action ) => {
		setBusy( true );
		try {
			if ( isApproval ) {
				await apiFetch( {
					path: 'agentic/v1/approvals/' + proposal.id,
					method: 'POST',
					data: { action: 'once' === action ? 'approve' : 'reject' },
				} );
			} else {
				await apiFetch( {
					path: 'agentic/v1/proposals/' + proposal.id,
					method: 'POST',
					data: { action, session_id: sessionId },
				} );
			}
			setIsError( false );
			setStatus(
				'reject' === action
					? __( 'Denied — no change made.', 'agent-builder' )
					: __( 'Done — change applied.', 'agent-builder' )
			);
		} catch ( err ) {
			setIsError( true );
			setStatus(
				( err && err.message ) ||
					__( 'Something went wrong.', 'agent-builder' )
			);
			setBusy( false );
		}
	};

	return (
		<div className="agentic-proposal-card">
			<div className="agentic-proposal-header">
				{ isApproval
					? __( 'Needs Your Approval', 'agent-builder' )
					: __( 'Proposed Change', 'agent-builder' ) }
			</div>
			<div className="agentic-proposal-desc">
				{ proposal.description ||
					__(
						'The agent wants to make a change.',
						'agent-builder'
					) }
			</div>
			{ proposal.summary &&
				typeof proposal.summary === 'object' &&
				Object.keys( proposal.summary ).length > 0 && (
					<div className="agentic-proposal-summary">
						{ Object.entries( proposal.summary )
							.filter(
								( [ , value ] ) =>
									value !== null &&
									value !== undefined &&
									value !== ''
							)
							.map( ( [ key, value ] ) => (
								<div
									className="agentic-proposal-summary-row"
									key={ key }
								>
									<strong>
										{ PROPOSAL_SUMMARY_LABELS[ key ] ||
											key.replace( /_/g, ' ' ) }
										:{ ' ' }
									</strong>
									{ String( value ) }
								</div>
							) ) }
					</div>
				) }
			{ proposal.diff && (
				<>
					<button
						type="button"
						className="agentic-proposal-toggle"
						onClick={ () => setShowDiff( ! showDiff ) }
					>
						{ showDiff
							? '▼ ' + __( 'Hide Diff', 'agent-builder' )
							: '▶ ' + __( 'Show Diff', 'agent-builder' ) }
					</button>
					{ showDiff && (
						<pre className="agentic-proposal-diff">
							{ proposal.diff }
						</pre>
					) }
				</>
			) }
			<div className="agentic-proposal-actions">
				{ status ? (
					<div
						className={
							'agentic-proposal-status' +
							( isError ? ' agentic-proposal-error' : '' )
						}
					>
						{ ( isError ? '⚠️ ' : '✅ ' ) + status }
					</div>
				) : isApproval ? (
					<>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-approve"
							onClick={ () => decide( 'once' ) }
						>
							{ __( 'Approve', 'agent-builder' ) }
						</button>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-reject"
							onClick={ () => decide( 'reject' ) }
						>
							{ __( 'Reject', 'agent-builder' ) }
						</button>
					</>
				) : (
					<>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-approve"
							onClick={ () => decide( 'once' ) }
						>
							{ __( 'Allow Once', 'agent-builder' ) }
						</button>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-session"
							onClick={ () => decide( 'session' ) }
						>
							{ __( 'Allow this Session', 'agent-builder' ) }
						</button>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-always"
							onClick={ () => decide( 'always' ) }
						>
							{ __( 'Always Allow', 'agent-builder' ) }
						</button>
						<button
							type="button"
							disabled={ busy }
							className="agentic-proposal-btn agentic-proposal-reject"
							onClick={ () => decide( 'reject' ) }
						>
							{ __( 'Deny', 'agent-builder' ) }
						</button>
					</>
				) }
			</div>
		</div>
	);
}

/**
 * A self-contained chat embed for one bundled agent, POSTing straight to
 * agentic/v1/chat — no agent switcher, matching every other forced-agent
 * Basic-mode embed in the plugin.
 *
 * @param {Object}  props
 * @param {Object}  props.assistant         { active, id, name, icon, welcome_message, suggested_prompts }.
 * @param {string}  props.deploymentContext Sent as deployment_context on every chat request.
 * @param {string}  [props.className]       Extra modifier class on the outer container (e.g. for a screen-specific height override).
 */
export function ChatEmbed( { assistant, deploymentContext, className = '' } ) {
	const [ messages, setMessages ] = useState( [] );
	const [ input, setInput ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const sessionIdRef = useRef( null );
	if ( null === sessionIdRef.current ) {
		sessionIdRef.current = generateChatSessionId();
	}
	const historyRef = useRef( [] );
	const messagesEndRef = useRef( null );

	useEffect( () => {
		if ( messagesEndRef.current ) {
			messagesEndRef.current.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ messages ] );

	if ( ! assistant || ! assistant.active ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ sprintfActivating( assistant?.name ) }{ ' ' }
				<a href="admin.php?page=agentic-agents">
					{ __( 'Check the Agents page', 'agent-builder' ) }
				</a>
			</Notice>
		);
	}

	const sendMessage = async ( text ) => {
		const trimmed = text.trim();
		if ( ! trimmed || sending ) {
			return;
		}
		setSending( true );
		const userEntry = { role: 'user', content: trimmed };
		historyRef.current = historyRef.current.concat( userEntry );
		setMessages( ( prev ) => prev.concat( userEntry ) );
		setInput( '' );

		const history = historyRef.current.slice( 0, -1 ).slice( -20 );

		try {
			const data = await apiFetch( {
				path: 'agentic/v1/chat',
				method: 'POST',
				data: {
					message: trimmed,
					agent_id: assistant.id,
					session_id: sessionIdRef.current,
					history,
					deployment_context: deploymentContext,
				},
			} );

			if ( data.error ) {
				setMessages( ( prev ) =>
					prev.concat( {
						role: 'assistant',
						content:
							data.response ||
							__( 'Something went wrong.', 'agent-builder' ),
						isError: true,
					} )
				);
			} else {
				historyRef.current = historyRef.current.concat( {
					role: 'assistant',
					content: data.response,
				} );
				setMessages( ( prev ) =>
					prev.concat( {
						role: 'assistant',
						content: data.response,
						proposal: data.pending_proposal
							? data.proposal
							: null,
					} )
				);
			}
		} catch ( err ) {
			setMessages( ( prev ) =>
				prev.concat( {
					role: 'assistant',
					content:
						( err && err.message ) ||
						__(
							'Connection error. Please try again.',
							'agent-builder'
						),
					isError: true,
				} )
			);
		} finally {
			setSending( false );
		}
	};

	const handleSubmit = ( e ) => {
		e.preventDefault();
		sendMessage( input );
	};

	return (
		<div
			className={
				'agentic-chat-container' + ( className ? ' ' + className : '' )
			}
		>
			<div className="agentic-chat-header">
				<div className="agentic-agent-info">
					<div className="agentic-agent-avatar">
						{ assistant.icon || '🤖' }
					</div>
					<div className="agentic-agent-details">
						<strong>{ assistant.name }</strong>
					</div>
				</div>
			</div>
			<div className="agentic-chat-messages">
				<div className="agentic-message agentic-message-agent">
					<div
						className="agentic-message-content"
						dangerouslySetInnerHTML={ {
							__html: renderChatMarkdown(
								assistant.welcome_message
							),
						} }
					/>
				</div>
				{ ! messages.length &&
					!! ( assistant.suggested_prompts || [] ).length && (
						<>
							<p className="agentic-starter-label">
								{ __( 'Try asking', 'agent-builder' ) }
							</p>
							<div className="agentic-suggested-prompts">
								{ assistant.suggested_prompts.map(
									( prompt ) => (
										<button
											key={ prompt }
											type="button"
											className="agentic-prompt-btn"
											onClick={ () =>
												sendMessage( prompt )
											}
										>
											{ prompt }
										</button>
									)
								) }
							</div>
						</>
					) }
				{ messages.map( ( m, i ) => (
					<div
						key={ i }
						className={
							'agentic-message agentic-message-' + m.role
						}
					>
						<div
							className="agentic-message-content"
							dangerouslySetInnerHTML={ {
								__html: 'user' === m.role
									? escapeHtml( m.content )
									: renderChatMarkdown( m.content ),
							} }
						/>
						{ m.proposal && (
							<ProposalCard
								proposal={ m.proposal }
								sessionId={ sessionIdRef.current }
							/>
						) }
					</div>
				) ) }
				<div ref={ messagesEndRef } />
			</div>
			<div className="agentic-chat-input-container">
				{ sending && (
					<div className="agentic-typing-indicator">
						<span></span>
						<span></span>
						<span></span>
						<span>
							{ sprintfThinking( assistant.name ) }
						</span>
					</div>
				) }
				<form
					className="agentic-chat-form"
					onSubmit={ handleSubmit }
				>
					<textarea
						className="agentic-chat-input"
						rows={ 1 }
						placeholder={ sprintfAsk( assistant.name ) }
						value={ input }
						disabled={ sending }
						onChange={ ( e ) => setInput( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( 'Enter' === e.key && ! e.shiftKey ) {
								e.preventDefault();
								handleSubmit( e );
							}
						} }
					/>
					<button
						type="submit"
						className="agentic-send-btn"
						disabled={ sending || ! input.trim() }
					>
						{ __( 'Send', 'agent-builder' ) }
					</button>
				</form>
			</div>
		</div>
	);
}

function sprintfActivating( name ) {
	return (
		( name || __( 'This assistant', 'agent-builder' ) ) +
		' ' +
		__( 'is still activating.', 'agent-builder' )
	);
}

function sprintfThinking( name ) {
	return name + ' ' + __( 'is thinking…', 'agent-builder' );
}

function sprintfAsk( name ) {
	return __( 'Ask', 'agent-builder' ) + ' ' + name + '…';
}
