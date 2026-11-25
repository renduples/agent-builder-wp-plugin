/**
 * Agentic Editor Sidebar
 *
 * Registers a Gutenberg block editor sidebar plugin that provides a
 * context-aware AI assistant panel during post/page editing sessions.
 *
 * No build step required — uses the global wp.* APIs provided by the
 * block editor. Enqueued via enqueue_block_editor_assets.
 *
 * @package Agent_Builder
 * @since   2.3.0
 */
( function () {
	'use strict';

	var config = window.agenticEditorSidebar || {};

	// Bail if not enabled or missing agent config.
	if ( ! config.enabled || ! config.agentId ) {
		return;
	}

	var registerPlugin            = wp.plugins.registerPlugin;
	// wp.editPost.PluginSidebar deprecated since WP 6.6 — prefer wp.editor.
	var PluginSidebar             = ( wp.editor && wp.editor.PluginSidebar )             || wp.editPost.PluginSidebar;
	var PluginSidebarMoreMenuItem = ( wp.editor && wp.editor.PluginSidebarMoreMenuItem ) || wp.editPost.PluginSidebarMoreMenuItem;
	var el                          = wp.element.createElement;
	var useState                    = wp.element.useState;
	var useEffect                   = wp.element.useEffect;
	var useRef                      = wp.element.useRef;
	var Fragment                    = wp.element.Fragment;
	var useSelect                   = wp.data.useSelect;
	var Button                      = wp.components.Button;
	var Spinner                     = wp.components.Spinner;

	var SIDEBAR_NAME = 'agentic-sidebar';

	// Quick-action definitions for the Content Writer toolbar.
	var QUICK_ACTIONS = [
		{ id: 'improve',  label: '✨ Improve',   prompt: 'Improve the writing of the following text. Make it clearer, more engaging, and professional. Return ONLY the improved text — no explanation, no preamble, no quotes.' },
		{ id: 'grammar',  label: '🔤 Grammar',   prompt: 'Fix any grammar, spelling, and punctuation issues in the following text. Return ONLY the corrected text — no explanation.' },
		{ id: 'shorter',  label: '📏 Shorter',   prompt: 'Make the following text shorter and more concise while keeping the essential meaning. Return ONLY the shortened text — no explanation.' },
		{ id: 'longer',   label: '📐 Longer',    prompt: 'Expand the following text with richer detail and depth while keeping the same tone. Return ONLY the expanded text — no explanation.' },
		{ id: 'simplify', label: '💡 Simplify',  prompt: 'Rewrite the following text in simpler, clearer language so anyone can understand it. Return ONLY the simplified text — no explanation.' },
		{ id: 'summarize',label: '📝 Summarise', prompt: 'Summarise the following text in one or two sentences. Return ONLY the summary — no explanation.' },
	];

	/**
	 * Per-agent configuration overrides.
	 *
	 * emptyLabel     {string}   Replaces the default empty-state message.
	 * quickActions   {Array}    Always-visible prompt shortcuts shown below
	 *                           the message area (no text selection needed).
	 *                           Each entry: { id, label, prompt }
	 */
	var AGENT_CONFIG = {
		'forms-builder': {
			emptyLabel:   'Describe the form you want.\n\nor\n\nSimply click on a Quick-Action.',
			quickActions: [
				{ id: 'feedback',    label: '📋 Feedback Form',    prompt: 'Design a feedback form that collects the user\'s name, email address, a rating from 1 to 5, and a comments field.' },
				{ id: 'reservation', label: '📅 Reservation Form', prompt: 'Design a reservation form with fields for full name, email, phone number, preferred date, number of guests, and any special requests.' },
			],
		},
	};

	/**
	 * Generate an 8-character random session ID.
	 *
	 * @return {string} Session identifier.
	 */
	function generateSessionId() {
		return Math.random().toString( 36 ).substr( 2, 8 );
	}

	/**
	 * Strip HTML tags and Gutenberg block comments from a string.
	 *
	 * @param {string} html Raw HTML / serialised block content.
	 * @return {string}     Plain text.
	 */
	function stripMarkup( html ) {
		return ( html || '' )
			.replace( /<!--[\s\S]*?-->/g, '' )   // block comments.
			.replace( /<[^>]*>/g, ' ' )           // HTML tags.
			.replace( /\s+/g, ' ' )               // collapse whitespace.
			.trim();
	}

	/**
	 * Insert a paragraph block with the given text at the end of the post.
	 * Production-grade helper for the Content Writer in sidebar.
	 */
	function insertParagraphAtEnd( text ) {
		if ( ! wp.blocks || ! wp.data || ! text ) {
			alert( 'Cannot insert — block editor APIs not available.' );
			return false;
		}
		try {
			var block = wp.blocks.createBlock( 'core/paragraph', {
				content: text.trim()
			} );
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			// Optional: scroll to the new block or give feedback.
			return true;
		} catch ( e ) {
			console.error( 'insertParagraphAtEnd failed', e );
			alert( 'Insert failed. You can copy the text and paste it manually.' );
			return false;
		}
	}

	/**
	 * Replace the current block selection (if any) with the text.
	 * Falls back to insert at end.
	 */
	function applyAsReplacementOrInsert( text, replCtx ) {
		if ( replCtx && wp.richText && wp.data ) {
			// Reuse existing replace logic if context available.
			try {
				var blockStore = wp.data.select( 'core/block-editor' );
				var block = blockStore.getBlock( replCtx.clientId );
				if ( block ) {
					var rawHtml = block.attributes[ replCtx.attributeKey ] || '';
					var richVal = wp.richText.create( { html: rawHtml } );
					var updated = wp.richText.insert( richVal, text, replCtx.from, replCtx.to );
					var newHtml = wp.richText.toHTMLString( { value: updated } );
					wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes(
						replCtx.clientId,
						{ [ replCtx.attributeKey ]: newHtml }
					);
					return true;
				}
			} catch ( e ) {}
		}
		// Fallback to simple insert at end.
		return insertParagraphAtEnd( text );
	}

	/**
	 * Insert a heading block.
	 */
	function insertHeading( text, level ) {
		level = level || 2;
		if ( ! wp.blocks || ! wp.data ) return insertParagraphAtEnd( text );
		try {
			var block = wp.blocks.createBlock( 'core/heading', {
				content: text.trim(),
				level: level
			} );
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			return true;
		} catch ( e ) {
			return insertParagraphAtEnd( text );
		}
	}

	/**
	 * Insert a quote / pullquote.
	 */
	function insertQuote( text ) {
		if ( ! wp.blocks || ! wp.data ) return insertParagraphAtEnd( text );
		try {
			var block = wp.blocks.createBlock( 'core/quote', {
				value: wp.richText ? wp.richText.create( { text: text.trim() } ) : text.trim(),
				citation: ''
			} );
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			return true;
		} catch ( e ) {
			return insertParagraphAtEnd( '> ' + text );
		}
	}

	/**
	 * Insert a bulleted list from lines.
	 */
	function insertList( lines ) {
		if ( ! wp.blocks || ! wp.data ) return insertParagraphAtEnd( lines.join('\n') );
		try {
			var items = lines.split(/\n/).filter(Boolean).map(function(line) {
				return wp.blocks.createBlock( 'core/list-item', { content: line.trim() } );
			});
			var list = wp.blocks.createBlock( 'core/list', { ordered: false }, items );
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( list );
			return true;
		} catch ( e ) {
			return insertParagraphAtEnd( lines );
		}
	}

	/**
	 * Basic image block insert (for when agent suggests media; real media search can enhance later).
	 */
	function insertImageSuggestion( caption, alt ) {
		if ( ! wp.blocks || ! wp.data ) return false;
		try {
			var block = wp.blocks.createBlock( 'core/image', {
				url: '',
				alt: alt || caption || '',
				caption: caption || ''
			});
			wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			return true;
		} catch ( e ) { return false; }
	}

	/**
	 * Create a structured suggestion from assistant text (Phase 3).
	 * Simple heuristics for newsroom use; can be made smarter with agent structured output later.
	 *
	 * @param {string}   text          Assistant response text to turn into a suggestion.
	 * @param {Function} setSuggestions State setter from EditorSidebarInner, passed in because
	 *                                  this is a sibling function and cannot close over it.
	 */
	function addSuggestionFromText( text, setSuggestions ) {
		if ( ! text || ! text.trim() ) return;
		var clean = text.trim();
		var type = 'paragraph';
		var loc = 'end';

		if ( clean.length < 120 && !/[.!?]$/.test(clean) ) type = 'heading';
		else if ( clean.indexOf('"') === 0 || clean.toLowerCase().indexOf('said') > -1 ) type = 'quote';
		else if ( clean.indexOf('\n-') > -1 || clean.indexOf('\n*') > -1 ) type = 'list';

		var suggestion = {
			id: Date.now() + Math.random().toString(36).slice(2),
			text: clean,
			type: type,
			location: loc
		};
		setSuggestions( function ( prev ) { return prev.concat( suggestion ); } );
	}

	/**
	 * Render a single chat message bubble.
	 *
	 * @param {Object}   msg          Message object { role, content, replaceContext? }.
	 * @param {number}   idx          Array index (used as key).
	 * @param {Function} setMessages State setter from EditorSidebarInner, passed in because
	 *                               MessageBubble is a sibling function and cannot close over it.
	 * @return {*}          React element.
	 */
	function MessageBubble( msg, idx, setMessages ) {
		var isUser  = msg.role === 'user';
		var replCtx = ( ! isUser && msg.replaceContext ) ? msg.replaceContext : null;

		// ── Rich renderer hook ────────────────────────────────────────────
		// Any agent can push a renderer onto window.AGENTIC_RENDERERS.
		// Each entry: { id, detect( text ) → data|null, render( data ) → el }
		// The first match wins; renderer is responsible for the full bubble.
		if ( ! isUser ) {
			var renderers = window.AGENTIC_RENDERERS || [];
			for ( var ri = 0; ri < renderers.length; ri++ ) {
				var richData = renderers[ ri ].detect( msg.content );
				if ( richData !== null ) {
					return el( 'div', { key: idx, style: { marginBottom: '8px' } },
						renderers[ ri ].render( richData )
					);
				}
			}
		}
		// ─────────────────────────────────────────────────────────────────

		function handleReplace() {
			if ( ! replCtx || ! wp.richText || ! wp.data ) { return; }
			var blockStore = wp.data.select( 'core/block-editor' );
			var block = blockStore.getBlock( replCtx.clientId );
			if ( ! block ) { return; }
			var rawHtml = block.attributes[ replCtx.attributeKey ] || '';
			var richVal = wp.richText.create( { html: rawHtml } );
			var updated = wp.richText.insert( richVal, msg.content, replCtx.from, replCtx.to );
			var newHtml = wp.richText.toHTMLString( { value: updated } );
			wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes(
				replCtx.clientId,
				{ [ replCtx.attributeKey ]: newHtml }
			);
			// Move the caret to just after the inserted text to clear the selection highlight.
			var newPos = replCtx.from + msg.content.length;
			try {
				wp.data.dispatch( 'core/block-editor' ).selectionChange(
					replCtx.clientId, replCtx.attributeKey, newPos, newPos
				);
			} catch ( e ) {
				// Fall back to clearing the block selection entirely.
				try { wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock(); } catch ( e2 ) {}
			}
		}

		// Production UX for Content Writer in sidebar: always offer direct apply buttons
		// on assistant responses when we have post context. This prevents the agent from
		// falling back to backend "approve action" flows that the sidebar cannot render.
		var applyButtons = null;
		if ( ! isUser && config.injectContext && ! msg.isError ) {
			var suggestionText = ( msg.content || '' ).trim();
			applyButtons = el(
				'div',
				{
					style: {
						display: 'flex',
						flexWrap: 'wrap',
						gap: '4px',
						marginTop: '4px',
						alignSelf: 'flex-start',
					},
				},
				el(
					'button',
					{
						onClick: function () {
							if ( insertParagraphAtEnd( suggestionText ) ) {
								// Give nice feedback in the chat.
								setMessages( function ( prev ) {
									return prev.concat( {
										role: 'assistant',
										content: '✅ Paragraph inserted at end of post. You can now edit it directly in the editor or ask me to refine it further.'
									} );
								} );
							}
						},
						style: {
							background: '#2271b1',
							color: '#fff',
							border: 'none',
							borderRadius: '10px',
							padding: '3px 10px',
							fontSize: '11px',
							cursor: 'pointer',
							whiteSpace: 'nowrap',
						},
						title: 'Insert this response as a new paragraph block at the end of the post'
					},
					'📝 Insert paragraph at end'
				),
				// If this message was generated against a selection, offer replace too.
				replCtx && el(
					'button',
					{
						onClick: function () {
							applyAsReplacementOrInsert( suggestionText, replCtx );
						},
						style: {
							background: 'none',
							border: '1px solid #c3c4c7',
							borderRadius: '10px',
							padding: '3px 10px',
							fontSize: '11px',
							cursor: 'pointer',
							color: '#2271b1',
							whiteSpace: 'nowrap',
						},
						title: 'Replace the currently selected text in the editor with this response'
					},
					'↩ Replace selection'
				),
				el(
					'button',
					{
						onClick: function () {
							// Simple copy fallback / advanced use.
							if ( navigator.clipboard ) {
								navigator.clipboard.writeText( suggestionText ).then( function () {
									alert( 'Copied to clipboard. You can paste it anywhere in the post.' );
								} );
							}
						},
						style: {
							background: 'none',
							border: '1px solid #c3c4c7',
							borderRadius: '10px',
							padding: '3px 8px',
							fontSize: '11px',
							cursor: 'pointer',
							color: '#646970',
						}
					},
					'Copy'
				)
			);
		}

		return el(
			'div',
			{
				key: idx,
				style: {
					display:        'flex',
					flexDirection:  'column',
					alignItems:     isUser ? 'flex-end' : 'flex-start',
					marginBottom:   '8px',
				},
			},
			el(
				'div',
				{
					style: {
						maxWidth:    '88%',
						padding:     '8px 12px',
						borderRadius: '12px',
						fontSize:    '13px',
						lineHeight:  '1.5',
						background:  isUser ? '#2271b1' : '#f0f0f1',
						color:       isUser ? '#ffffff' : '#1d2327',
						whiteSpace:  'pre-wrap',
						wordBreak:   'break-word',
					},
				},
				msg.content
			),
			applyButtons || ( replCtx && el(
				'button',
				{
					onClick: handleReplace,
					title:   'Replace the selected text in the editor with this response',
					style: {
						marginTop:    '4px',
						background:   'none',
						border:       '1px solid #c3c4c7',
						borderRadius: '10px',
						padding:      '2px 8px',
						fontSize:     '11px',
						cursor:       'pointer',
						color:        '#2271b1',
						lineHeight:   '1.5',
						alignSelf:    'flex-start',
					},
				},
				'\u21A9 Replace selection'
			) )
		);
	}

	/**
	 * Inner sidebar component — owns all state.
	 * Receives postTitle, postContent, postType, postId as props.
	 *
	 * @param {Object} props Component props.
	 * @return {*}           React element or null.
	 */
	function EditorSidebarInner( props ) {
		var postTitle   = props.postTitle   || '';
		var postContent = props.postContent || '';
		var postType    = props.postType    || '';
		var postId      = props.postId      || 0;
		var blocks      = props.blocks      || [];
		var outline     = props.outline     || [];
		var stats       = props.stats       || { words: 0, paragraphs: 0, headings: 0, images: 0, links: 0 };

		var messagesState    = useState( [] );
		var messages         = messagesState[ 0 ];
		var setMessages      = messagesState[ 1 ];

		var inputState   = useState( '' );
		var input        = inputState[ 0 ];
		var setInput     = inputState[ 1 ];

		var sendingState = useState( false );
		var isSending    = sendingState[ 0 ];
		var setIsSending = sendingState[ 1 ];

		// Phase 2/3: Suggestions Inbox for newsroom apply workflow.
		// Each: { id, text, type: 'paragraph'|'heading'|'quote'|'list', location: 'end'|'selection' }
		var suggestionsState = useState( [] );
		var suggestions      = suggestionsState[ 0 ];
		var setSuggestions   = suggestionsState[ 1 ];

		// Preflight: detect provider misconfiguration before the user sends anything.
		var providerErrorState = useState( null );
		var providerError      = providerErrorState[ 0 ];
		var setProviderError   = providerErrorState[ 1 ];

		useEffect( function () {
			window.fetch( config.restUrl + 'status', {
				headers: { 'X-WP-Nonce': config.nonce },
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data && data.configured === false ) {
						setProviderError( {
							provider: data.provider || 'unknown',
							message:  'No API key is configured for the "' + ( data.provider || 'selected' ) + '" provider. The AI agent won\'t work until a key is added.',
						} );
					}
				} )
				.catch( function () {
					// Network failure — don't block the UI, let the actual chat request surface the error.
				} );
		}, [] );

		// Active agent — can be switched by the user without leaving the editor.
		var agentIdState   = useState( config.agentId );
		var activeAgentId  = agentIdState[ 0 ];
		var setActiveAgentId = agentIdState[ 1 ];

		var availableAgents = config.availableAgents || [];

		// Derive the active agent's display name.
		var activeAgentName = config.agentName;
		for ( var ai = 0; ai < availableAgents.length; ai++ ) {
			if ( availableAgents[ ai ].id === activeAgentId ) {
				activeAgentName = availableAgents[ ai ].name;
				break;
			}
		}

		var sessionId      = useState( generateSessionId )[ 0 ];
		var contextSentRef = useRef( false );
		var historyRef     = useRef( [] );
		var messagesEndRef = useRef( null );

		// Detect text currently selected inside the block editor canvas.
		var blockSelection = useSelect( function ( select ) {
			var store = select( 'core/block-editor' );
			var start = store.getSelectionStart ? store.getSelectionStart() : null;
			var end   = store.getSelectionEnd   ? store.getSelectionEnd()   : null;
			if ( ! start || ! end || ! start.clientId || start.clientId !== end.clientId ) {
				return { text: '' };
			}
			if ( typeof start.offset === 'undefined' || start.offset === end.offset ) {
				return { text: '' };
			}
			var block   = store.getBlock( start.clientId );
			if ( ! block ) { return { text: '' }; }
			var attrKey = start.attributeKey || end.attributeKey || 'content';
			var rawHtml = block.attributes[ attrKey ] || '';
			// Use wp.richText for accurate plain-text offsets; fall back to tag-strip.
			var plain = ( wp.richText && wp.richText.create )
				? wp.richText.create( { html: rawHtml } ).text
				: rawHtml.replace( /<[^>]+>/g, '' );
			var from = Math.min( start.offset, end.offset );
			var to   = Math.max( start.offset, end.offset );
			return { text: plain.slice( from, to ).trim(), clientId: start.clientId, attributeKey: attrKey, from: from, to: to };
		}, [] );
		var selectedText = blockSelection.text || '';

		// Scroll to bottom on new messages.
		useEffect(
			function () {
				if ( messagesEndRef.current ) {
					messagesEndRef.current.scrollIntoView( { behavior: 'smooth' } );
				}
			},
			[ messages ]
		);

		/**
		 * Build the context prefix appended to the first user message.
		 *
		 * @return {string} Formatted context block.
		 */
		function buildContext() {
			var parts = [];
			if ( postTitle   ) { parts.push( 'Title: '     + postTitle ); }
			if ( postType    ) { parts.push( 'Post type: ' + postType ); }
			if ( postId      ) { parts.push( 'Post ID: '   + postId ); }
			if ( postContent ) {
				var plain   = stripMarkup( postContent );
				// Give the agent a generous excerpt of the current post body so it can
				// meaningfully reason about / edit / extend the content (was 800).
				var excerpt = plain.length > 2500 ? plain.substring( 0, 2500 ) + '\u2026' : plain;
				if ( excerpt ) {
					parts.push( 'Current content:\n' + excerpt );
				}
			}
			return parts.join( '\n' );
		}

		/**
		 * Send a message to the REST API and update UI state.
		 *
		 * @param {string} userMessage  Full message sent to the API.
		 * @param {string} [displayAs]       Optional short label shown in the chat bubble.
		 * @param {Object} [replaceContext]  Selection context for the ↩ Replace button.
		 * @return {void}
		 */
		async function sendMessage( userMessage, displayAs, replaceContext ) {
			var displayMessage = ( displayAs !== undefined ) ? displayAs : userMessage;
			if ( ! userMessage.trim() || isSending ) {
				return;
			}

			setIsSending( true );

			// Append display label to the UI thread; store full message in API history.
			var userEntry = { role: 'user', content: userMessage };
			historyRef.current = historyRef.current.concat( userEntry );
			setMessages( function ( prev ) { return prev.concat( { role: 'user', content: displayMessage } ); } );

			// On first message: prepend context block if injection is enabled.
			var apiMessage = userMessage;
			if ( ! contextSentRef.current && config.injectContext && ( postTitle || postContent ) ) {
				var ctx = buildContext();
				if ( ctx ) {
					apiMessage = '[Context]\n' + ctx + '\n\n[Message]\n' + userMessage;
				}
				contextSentRef.current = true;
			}

			// Send history EXCLUDING the message we just added (it becomes the `message` param).
			var history = historyRef.current.slice( 0, -1 ).slice( -20 );

			try {
				var response = await window.fetch( config.restUrl + 'chat', {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   config.nonce,
					},
					body: JSON.stringify( {
						message:            apiMessage,
						agent_id:           activeAgentId,
						session_id:         sessionId,
						history:            history,
						deployment_context: 'gutenberg_sidebar',
						// Always send fresh post context (via the standard page_context path)
						// so the backend injects [PAGE CONTEXT] into the *system prompt* on every turn.
						// This ensures the agent remains aware of the current post content even on
						// follow-ups and quick-action clicks (fixes cases where context was only
						// one-time prepended to the first message and then lost from history).
						page_context: ( config.injectContext && ( postTitle || postContent ) )
							? buildContext()
							: '',
					} ),
				} );

				var data = await response.json();

				if ( data.error ) {
					var errContent = data.response || 'Unknown error.';
					var isQuota = response.status === 429 || response.status === 402;
					setMessages( function ( prev ) {
						return prev.concat( { role: 'assistant', content: isQuota ? errContent : 'Error: ' + errContent, quota_error: isQuota, isError: true } );
					} );
				} else {
					var assistantEntry = { role: 'assistant', content: data.response, replaceContext: replaceContext || null };
					historyRef.current = historyRef.current.concat( assistantEntry );
					setMessages( function ( prev ) { return prev.concat( assistantEntry ); } );

					// Phase 3: Auto-create suggestion for newsroom apply flow (if it looks like edit content)
					if ( config.injectContext && data.response && (data.response.length > 40) ) {
						// Heuristic: if response contains proposal language or substantial text, offer as suggestion
						if ( /paragraph|headline|lede|quote|list|here is|proposed|suggest/i.test(data.response) || data.response.split(' ').length > 15 ) {
							addSuggestionFromText( data.response, setSuggestions );
						}
					}
				}
			} catch ( err ) {
				setMessages( function ( prev ) {
					return prev.concat( { role: 'assistant', content: 'Connection error. Please try again.', isError: true } );
				} );
			} finally {
				setIsSending( false );
			}
		}

		/** Clear the conversation thread. */
		function clearChat() {
			setMessages( [] );
			historyRef.current     = [];
			contextSentRef.current = false;
			setSuggestions( [] );
			setInput( '' );
		}

		/** Switch to a different agent — clears the current conversation. */
		function handleAgentSwitch( newSlug ) {
			if ( newSlug === activeAgentId ) { return; }
			setMessages( [] );
			historyRef.current     = [];
			contextSentRef.current = false;
			setSuggestions( [] );
			setInput( '' );
			setActiveAgentId( newSlug );
		}

		/** Send on Enter (Shift+Enter for newline). */
		function handleKeyDown( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				var msg = input;
				setInput( '' );
				sendMessage( msg );
			}
		}

		function handleSend() {
			var msg = input;
			setInput( '' );
			sendMessage( msg );
		}

		// Per-agent config (empty-state copy, quick-start actions).
		var agentCfg = AGENT_CONFIG[ activeAgentId ] || {};

		// Phase 2: Newsroom-focused Quick Tools for Content Writer.
		// These send targeted prompts with full context. Results feed into Suggestions Inbox.
		var NEWSROOM_QUICK_TOOLS = [
			{ id: 'lede', label: '✍️ Write/Improve Lede', prompt: 'Write or improve a strong, engaging lede (nut graf) paragraph for this news article. Keep it under 60 words, front-load the most important info, and make it scannable.' },
			{ id: 'headlines', label: '📰 3 Headline Options', prompt: 'Suggest 3 strong, accurate headline options for this story. Vary style (straight news, feature, SEO-optimized). For each, give a one-sentence rationale.' },
			{ id: 'seo', label: '🔍 SEO Pack', prompt: 'Provide: 1) optimized slug, 2) meta description (150-160 chars), 3) 3-5 focus keyword placement suggestions in the existing content, 4) 2 internal link opportunities if relevant.' },
			{ id: 'social', label: '📱 Social Cutdowns', prompt: 'Create 3 social media versions of this story: one for X/Twitter (under 280 chars), one for LinkedIn, one for a newsletter blurb. Include suggested hashtags where appropriate.' },
			{ id: 'structure', label: '📐 Structure Check', prompt: 'Analyze the current structure. Suggest improvements for flow, subheads, and scannability for online news readers. List specific block-level recommendations.' },
			{ id: 'quote', label: '💬 Pull Quote', prompt: 'Extract or suggest a compelling pull quote from this article (with attribution). Format it ready to insert as a quote block.' }
		];

		// Empty state label.
		var emptyLabel = agentCfg.emptyLabel || (
			config.injectContext
				? 'Ask me anything about this ' + ( postType || 'post' ) + '.\n\nor\n\nSimply highlight any text and click on a Quick-Action.'
				: 'Start a conversation with ' + ( activeAgentName || 'your AI agent' ) + '.'
		);

		return el(
			Fragment,
			null,

			// Menu item in the editor "more" (⋮) dropdown.
			el(
				PluginSidebarMoreMenuItem,
				{ target: SIDEBAR_NAME },
				'AgentStructor'
			),

			el(
				PluginSidebar,
				{
					name:  SIDEBAR_NAME,
					title: 'AgentStructor',
					icon:  'format-chat',
				},

// ── Sub-header: agent switcher + context label + clear ───────.
			el(
				'div',
				{
					style: {
							borderBottom: '1px solid #e0e0e0',
							background:   '#fff',
							flexShrink:   0,
					},
				},
					el(
						'div',
						{
							style: {
								padding:        '6px 16px',
								display:        'flex',
								alignItems:     'center',
								justifyContent: 'space-between',
								gap:            '8px',
								minWidth:       0,
							},
						},

				// Left side: agent switcher (dropdown) or static label.
				availableAgents.length > 1
					? el(
						'select',
						{
							value:    activeAgentId,
							onChange: function ( e ) { handleAgentSwitch( e.target.value ); },
							title:    'Switch agent (starts a new conversation)',
							style: {
								flex:        '1',
								minWidth:    '0',
								fontSize:    '13px',
								fontWeight:  '600',
								color:       '#1d2327',
								border:      'none',
								background:  'transparent',
								padding:     '0',
								cursor:      'pointer',
								appearance:  'auto',
							},
						},
						availableAgents.map( function ( agent ) {
							return el( 'option', { key: agent.id, value: agent.id }, agent.name );
						} )
					)
					: el(
						'span',
						{
							style: {
								flex:         '1',
								minWidth:     '0',
								fontSize:     '11px',
								color:        '#646970',
								overflow:     'hidden',
								textOverflow: 'ellipsis',
								whiteSpace:   'nowrap',
							},
						},
						activeAgentName
					),

				// Right side: Clear action only.
				el(
					'div',
					{ style: { display: 'flex', alignItems: 'center', gap: '6px', flexShrink: 0 } },
					el(
						'button',
						{
							onClick: clearChat,
							title:   'New conversation',
							style: {
								background: 'none',
								border:     'none',
								cursor:     'pointer',
								color:      '#646970',
								fontSize:   '11px',
								padding:    '2px 4px',
								lineHeight: '1.4',
							},
						},
						'Clear'
					)
					)
					),
					),

				// ── Article Dashboard (newsroom 5-star essential) ──────────────────
				// Live stats + clickable outline from full block tree (added in Phase 1).
				// Gives editors instant "at a glance" + fast navigation. Combined with
				// the apply buttons on messages, this makes the sidebar feel native and
				// hard to replace vs external AI tools.
				( (outline && outline.length) || (stats && stats.words > 0) ) && el(
					'div',
					{
						style: {
							padding: '6px 12px',
							background: '#f0f6fc',
							borderBottom: '1px solid #c3c4c7',
							fontSize: '11px',
							lineHeight: '1.35',
							color: '#1d2327',
							flexShrink: 0
						}
					},
					el( 'strong', { style: { marginRight: '6px' } }, 'Live:' ),
									'Words: ' + ( stats.words || 0 ) + ' • Headings: ' + ( stats.headings || 0 ) + ' • Images: ' + ( stats.images || 0 ),
									( outline && outline.length > 0 ) ? el(
						'div',
						{ style: { marginTop: '3px', fontSize: '10px', color: '#646970' } },
						'Outline: ',
						outline.slice(0, 3).map( function ( item, i ) {
							return el(
								'span',
								{
									key: i,
									onClick: function () {
										try { wp.data.dispatch( 'core/block-editor' ).selectBlock( item.clientId ); } catch ( e ) {}
									},
									style: { cursor: 'pointer', textDecoration: 'underline', marginRight: '4px' },
									title: 'Jump to this heading in the editor'
								},
								(item.level > 1 ? '›' : '') + (item.text ? item.text.substring(0, 22) + (item.text.length > 22 ? '…' : '') : '')
							);
						})
									) : el(
										'div',
										{ style: { marginTop: '3px', fontSize: '10px', color: '#646970' } },
										'Outline: add heading blocks to see structure'
									)
				),

				// ── Phase 2: Newsroom Quick Tools (compact grid) ─────────────────
				el(
					'div',
					{
						style: {
							borderBottom: '1px solid #e0e0e0',
							background: '#f6f7f7',
							padding: '6px 12px',
							flexShrink: 0
						}
					},
					el( 'div', { style: { fontSize: '10px', color: '#646970', marginBottom: '4px' } }, 'Quick newsroom tools (results appear as applyable suggestions below)' ),
					el(
						'div',
						{ style: { display: 'flex', flexWrap: 'wrap', gap: '3px' } },
						NEWSROOM_QUICK_TOOLS.map( function ( tool ) {
							return el( 'button', {
								key: tool.id,
								disabled: isSending,
								onClick: function () {
									sendMessage( tool.prompt );
								},
								style: {
									background: '#fff',
									border: '1px solid #c3c4c7',
									borderRadius: '8px',
									padding: '2px 7px',
									fontSize: '10px',
									cursor: isSending ? 'default' : 'pointer',
									color: '#1d2327'
								}
							}, tool.label );
						} )
					)
				),

				// ── Phase 3: Suggestions Inbox (the heart of the newsroom apply UX) ──
				// Structured cards with direct Apply that use the block editor APIs.
				// This replaces the old "approve the non-existent action" dead-end.
				suggestions.length > 0 && el(
					'div',
					{
						style: {
							borderBottom: '1px solid #e0e0e0',
							background: '#fffbeb',
							padding: '8px 12px',
							flexShrink: 0
						}
					},
					el( 'div', { style: { fontSize: '11px', fontWeight: 600, marginBottom: '4px', color: '#92400e' } }, 'Suggestions ready to apply' ),
					suggestions.map( function ( sug ) {
						return el(
							'div',
							{
								key: sug.id,
								style: {
									border: '1px solid #fde68a',
									borderRadius: '6px',
									padding: '6px',
									marginBottom: '4px',
									background: '#fefce8',
									fontSize: '12px'
								}
							},
							el( 'div', { style: { fontSize: '10px', color: '#92400e', marginBottom: '2px' } }, sug.type.toUpperCase() + ' • ' + sug.location ),
							el( 'div', { style: { whiteSpace: 'pre-wrap', maxHeight: '80px', overflow: 'auto' } }, sug.text.substring(0, 280) + (sug.text.length > 280 ? '…' : '') ),
							el(
								'div',
								{ style: { marginTop: '4px', display: 'flex', gap: '4px' } },
								el( 'button', {
									onClick: function () {
										var success = false;
										if ( sug.type === 'heading' ) success = insertHeading( sug.text );
										else if ( sug.type === 'quote' ) success = insertQuote( sug.text );
										else if ( sug.type === 'list' ) success = insertList( sug.text );
										else success = insertParagraphAtEnd( sug.text );

										if ( success ) {
											// Remove from suggestions
											setSuggestions( function ( prev ) { return prev.filter( function ( s ) { return s.id !== sug.id; } ); } );
											setMessages( function ( prev ) { return prev.concat( { role: 'assistant', content: '✅ ' + sug.type + ' applied to the post.' } ); } );
										}
									},
									style: { background: '#166534', color: '#fff', border: 'none', borderRadius: '4px', padding: '2px 8px', fontSize: '10px', cursor: 'pointer' }
								}, 'Apply' ),
								el( 'button', {
									onClick: function () {
										setSuggestions( function ( prev ) { return prev.filter( function ( s ) { return s.id !== sug.id; } ); } );
									},
									style: { background: 'none', border: '1px solid #c3c4c7', borderRadius: '4px', padding: '2px 6px', fontSize: '10px', cursor: 'pointer' }
								}, 'Discard' )
							)
						);
					} )
				),

				// ── Messages area ─────────────────────────────────────────.
				el(
					'div',
					{
						style: {
							flex:      '1',
							overflowY: 'auto',
							padding:   '12px 16px',
							minHeight: '160px',
							maxHeight: 'calc(100vh - 290px)',
							background: '#fafafa',
						},
					},

					// Empty state.
					messages.length === 0
						? el(
							'div',
							{
								style: {
									textAlign:  'center',
									color:      '#a7aaad',
									fontSize:   '13px',
									marginTop:  '40px',
									lineHeight: '1.7',
									padding:    '0 12px',
								},
							},
							el( 'div', { style: { fontSize: '22px', marginBottom: '6px' } }, '\uD83D\uDCAC' ),
							emptyLabel
						)
						: messages.map( function ( msg, idx ) { return MessageBubble( msg, idx, setMessages ); } ),

					// Typing indicator.
					isSending
						? el(
							'div',
							{
								style: {
									display:    'flex',
									alignItems: 'center',
									gap:        '8px',
									padding:    '4px 0',
								},
							},
							el( Spinner ),
							el( 'span', { style: { fontSize: '12px', color: '#646970' } }, 'Thinking\u2026' )
						)
						: null,

					// Scroll anchor.
					el( 'div', { ref: messagesEndRef } )
				),
			// ── Quick actions strip (shown when text is selected in the editor) ───.
			selectedText && el(
				'div',
				{
					style: {
						borderTop:    '1px solid #e0e0e0',
						borderBottom: '1px solid #e0e0e0',
						background:   '#f6f7f7',
						padding:      '8px 12px',
						flexShrink:   0,
					},
				},
				el(
					'div',
					{
						style: {
							fontSize:     '11px',
							color:        '#646970',
							marginBottom: '5px',
							overflow:     'hidden',
							textOverflow: 'ellipsis',
							whiteSpace:   'nowrap',
						},
					},
					'\u270f\ufe0f \u201c' + ( selectedText.length > 55 ? selectedText.slice( 0, 55 ) + '\u2026' : selectedText ) + '\u201d'
				),
				el(
					'div',
					{ style: { display: 'flex', flexWrap: 'wrap', gap: '4px' } },
					QUICK_ACTIONS.map( function ( action ) {
						return el( 'button', {
							key:      action.id,
							type:     'button',
							disabled: isSending,
							onClick: function () {
							var selCtx = blockSelection.clientId ? {
								clientId:     blockSelection.clientId,
								attributeKey: blockSelection.attributeKey,
								from:         blockSelection.from,
								to:           blockSelection.to,
							} : null;
							var snippet = selectedText.length > 30
								? selectedText.slice( 0, 30 ) + '\u2026'
								: selectedText;
							sendMessage(
								action.prompt + '\n\n"""\n' + selectedText + '\n"""',
								action.label + ': \u201c' + snippet + '\u201d',
								selCtx
								);
							},
							style: {
								background:   '#fff',
								border:       '1px solid #c3c4c7',
								borderRadius: '12px',
								padding:      '3px 9px',
								fontSize:     '11px',
								cursor:       isSending ? 'default' : 'pointer',
								color:        '#1d2327',
								whiteSpace:   'nowrap',
								lineHeight:   '1.5',
							},
						}, action.label );
					} )
				)
			),

			// ── Agent quick-start strip (always-visible shortcut prompts) ──────.
			// Shown when the active agent has its own quickActions defined and
			// no text is selected (avoids collision with the selection strip).
			! selectedText && agentCfg.quickActions && agentCfg.quickActions.length > 0 && el(
				'div',
				{
					style: {
						borderTop:  '1px solid #e0e0e0',
						background: '#f6f7f7',
						padding:    '8px 12px',
						flexShrink: 0,
					},
				},
				el(
					'div',
					{ style: { display: 'flex', flexWrap: 'wrap', gap: '4px' } },
					agentCfg.quickActions.map( function ( action ) {
						return el( 'button', {
							key:      action.id,
							type:     'button',
							disabled: isSending,
							onClick:  function () { sendMessage( action.prompt, action.label ); },
							style: {
								background:   '#fff',
								border:       '1px solid #c3c4c7',
								borderRadius: '12px',
								padding:      '3px 9px',
								fontSize:     '11px',
								cursor:       isSending ? 'default' : 'pointer',
								color:        '#1d2327',
								whiteSpace:   'nowrap',
								lineHeight:   '1.5',
							},
						}, action.label );
					} )
				)
			),
				// ── Input area ────────────────────────────────────────────.
				el(
					'div',
					{
						style: {
							padding:       '10px 16px',
							borderTop:     '1px solid #e0e0e0',
							background:    '#fff',
							display:       'flex',
							flexDirection: 'column',
							gap:           '8px',
							flexShrink:    0,
						},
					},

					// ── Provider misconfiguration warning ──────────────.
					providerError && el(
						'div',
						{
							style: {
								background:   '#fcf0f1',
								border:       '1px solid #d63638',
								borderRadius: '4px',
								padding:      '10px 12px',
								fontSize:     '12px',
								lineHeight:   '1.5',
								color:        '#3c0d0e',
							},
						},
						el( 'strong', null, '\u26A0\uFE0F AI provider not configured' ),
						el( 'br' ),
						providerError.message,
						el( 'br' ),
						el(
							'a',
							{
								href:   config.adminUrl + 'admin.php?page=agentic-settings&tab=providers',
								target: '_blank',
								style:  { color: '#d63638', fontWeight: 600 },
							},
							'Fix in Agentic \u2192 Providers \u2197'
						)
					),

					el( 'textarea', {
						value:       input,
						onChange:    function ( e ) { setInput( e.target.value ); },
						onKeyDown:   handleKeyDown,
						placeholder: providerError ? 'Configure a provider before chatting\u2026' : 'Type a message\u2026 (Shift+Enter for new line)',
						disabled:    isSending || !! providerError,
						rows:        3,
						style: {
							width:      '100%',
							resize:     'none',
							border:     '1px solid #c3c4c7',
							borderRadius: '4px',
							padding:    '8px',
							fontSize:   '13px',
							lineHeight: '1.4',
							boxSizing:  'border-box',
							fontFamily: 'inherit',
							opacity:    providerError ? '0.5' : '1',
						},
					} ),
					el(
						Button,
						{
							variant:  'primary',
							onClick:  handleSend,
							disabled: ! input.trim() || isSending || !! providerError,
							style:    { width: '100%', justifyContent: 'center' },
						},
						isSending ? 'Sending\u2026' : 'Send'
					)
				)
			)
		);
	}

	/**
	 * Pure helper: Recursively build a lightweight outline from block tree (headings + structure).
	 * Perfect for newsroom "at a glance" view.
	 */
	function computeOutline( blocks, level ) {
		level = level || 0;
		var outline = [];
		if ( ! blocks || ! blocks.length ) return outline;

		blocks.forEach( function ( block ) {
			var name = block.name || '';
			var attrs = block.attributes || {};
			var text = '';

			if ( name === 'core/heading' ) {
				text = ( attrs.content || '' ).toString().replace( /<[^>]+>/g, '' ).trim();
				if ( text ) {
					outline.push( {
						level: attrs.level || (level + 1),
						text: text,
						clientId: block.clientId,
						type: 'heading'
					} );
				}
			} else if ( name === 'core/paragraph' || name === 'core/freeform' ) {
				// Could add lead/nutgraf detection later
			}

			if ( block.innerBlocks && block.innerBlocks.length ) {
				outline = outline.concat( computeOutline( block.innerBlocks, level + 1 ) );
			}
		} );
		return outline;
	}

	/**
	 * Pure helper: Basic newsroom stats from blocks (word count, structure signals).
	 */
	function computeStats( blocks ) {
		var stats = { words: 0, paragraphs: 0, headings: 0, images: 0, links: 0 };
		if ( ! blocks || ! blocks.length ) return stats;

		function walk( blks ) {
			blks.forEach( function ( b ) {
				var name = b.name || '';
				var attrs = b.attributes || {};
				var content = ( attrs.content || '' ).toString();

				if ( name === 'core/paragraph' || name === 'core/freeform' || name.indexOf( 'heading' ) > -1 ) {
					var plain = content.replace( /<[^>]+>/g, ' ' ).trim();
					stats.words += plain.split( /\s+/ ).filter( Boolean ).length;
					if ( name.indexOf( 'paragraph' ) > -1 ) stats.paragraphs++;
					if ( name.indexOf( 'heading' ) > -1 ) stats.headings++;
				}
				if ( name === 'core/image' || name === 'core/cover' ) stats.images++;
				if ( content.indexOf( 'href=' ) > -1 ) stats.links += (content.match( /href=/g ) || []).length;

				if ( b.innerBlocks ) walk( b.innerBlocks );
			} );
		}
		walk( blocks );
		return stats;
	}

	/**
	 * Wrapper component — reads the current post type from the editor store
	 * and guards against rendering the sidebar on unsupported post types.
	 * Now also provides live blocks, outline, and newsroom stats for 5-star experience.
	 *
	 * Hooks called at this level (unconditionally) before any early return.
	 *
	 * @return {*} React element or null.
	 */
	function EditorSidebarWrapper() {
		var editorData = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var blockEditor = select( 'core/block-editor' );

			var base = {
				postTitle:   '',
				postContent: '',
				postType:    '',
				postId:      0,
				blocks:      [],
				outline:     [],
				stats:       { words: 0, paragraphs: 0, headings: 0, images: 0, links: 0 }
			};

			if ( ! editor ) return base;

			var blocks = blockEditor ? (blockEditor.getBlocks() || []) : [];
			var outline = computeOutline( blocks );
			var stats = computeStats( blocks );

			return {
				postTitle:   editor.getEditedPostAttribute( 'title' )   || '',
				postContent: editor.getEditedPostContent()               || '',
				postType:    editor.getCurrentPostType()                 || '',
				postId:      editor.getCurrentPostId()                   || 0,
				blocks:      blocks,
				outline:     outline,
				stats:       stats
			};
		} );

		var allowedTypes = config.postTypes || [];
		if ( allowedTypes.length > 0 && allowedTypes.indexOf( editorData.postType ) === -1 ) {
			return null;
		}

		return el( EditorSidebarInner, editorData );
	}

	registerPlugin( 'agentic-editor-sidebar', {
		render: EditorSidebarWrapper,
		icon:   'format-chat',
	} );

}() );
