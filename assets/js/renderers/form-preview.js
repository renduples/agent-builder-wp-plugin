/**
 * Forms Builder — Sidebar Rich Renderer
 *
 * Pushes a renderer onto window.AGENTIC_RENDERERS so that when the
 * Forms Builder agent returns form JSON the editor sidebar displays a
 * live preview instead of a raw text bubble.
 *
 * Renderer contract (shared with editor-sidebar.js):
 *   detect( text )  → parsed data object, or null if this message is not
 *                     for this renderer.
 *   render( data )  → wp.element.createElement(...) element that replaces
 *                     the default chat bubble for this message.
 *
 * No build step — uses global wp.* APIs.
 *
 * @package    Agent_Builder
 * @since      2.4.0
 */
( function () {
	'use strict';

	window.AGENTIC_RENDERERS = window.AGENTIC_RENDERERS || [];

	var el        = wp.element.createElement;
	var useState  = wp.element.useState;
	var Fragment  = wp.element.Fragment;

	// ── Detection ─────────────────────────────────────────────────────────────

	/**
	 * Try to extract a form definition (object with a non-empty `fields` array)
	 * from an LLM response string.
	 *
	 * Handles three common formats:
	 *   1. ```json\n{...}\n```  — fenced code block
	 *   2. ```\n{...}\n```      — plain code block
	 *   3. Bare JSON starting with `{` (whole message is JSON)
	 *
	 * @param {string} text Raw assistant response text.
	 * @return {{ data: Object, prefix: string }|null}
	 */
	function detectFormJson( text ) {
		// 1 & 2 — markdown fenced block
		var fenceRx = /```(?:json)?\s*\n([\s\S]+?)\n```/;
		var fenceMatch = text.match( fenceRx );
		if ( fenceMatch ) {
			try {
				var obj = JSON.parse( fenceMatch[ 1 ] );
				if ( isFormObject( obj ) ) {
					var fenceStart = text.indexOf( fenceMatch[ 0 ] );
					return {
						data:   obj,
						prefix: text.slice( 0, fenceStart ).trim(),
						suffix: text.slice( fenceStart + fenceMatch[ 0 ].length ).trim(),
					};
				}
			} catch ( e ) {}
		}

		// 3 — whole message is a bare JSON object
		var trimmed = text.trim();
		if ( trimmed.charAt( 0 ) === '{' ) {
			try {
				var obj2 = JSON.parse( trimmed );
				if ( isFormObject( obj2 ) ) {
					return { data: obj2, prefix: '', suffix: '' };
				}
			} catch ( e ) {}
		}

		return null;
	}

	function isFormObject( obj ) {
		return obj && typeof obj === 'object' && Array.isArray( obj.fields ) && obj.fields.length > 0;
	}

	// ── Field renderers ───────────────────────────────────────────────────────

	var fieldInputStyle = {
		width:       '100%',
		fontSize:    '12px',
		padding:     '4px 6px',
		border:      '1px solid #c3c4c7',
		borderRadius: '3px',
		boxSizing:   'border-box',
		background:  '#f6f7f7',
		color:       '#787c82',
	};

	function renderField( field, idx ) {
		var labelText = field.label || field.name || ( 'Field ' + ( idx + 1 ) );
		var required  = field.required || false;

		var labelEl = el(
			'label',
			{
				style: {
					display:      'block',
					fontSize:     '11px',
					fontWeight:   '600',
					color:        '#1d2327',
					marginBottom: '3px',
				},
			},
			labelText,
			required && el( 'span', { style: { color: '#d63638', marginLeft: '2px' } }, '*' )
		);

		var inputEl;
		var type = ( field.type || 'text' ).toLowerCase();

		if ( type === 'textarea' ) {
			inputEl = el( 'textarea', {
				disabled:    true,
				placeholder: field.placeholder || '',
				rows:        2,
				style:       Object.assign( {}, fieldInputStyle, { resize: 'none' } ),
			} );

		} else if ( type === 'select' ) {
			var opts = ( field.options || [] ).map( function ( opt, oi ) {
				return el( 'option', { key: oi }, typeof opt === 'object' ? opt.label : opt );
			} );
			inputEl = el(
				'select',
				{ disabled: true, style: fieldInputStyle },
				el( 'option', null, field.placeholder || '— Select —' ),
				opts
			);

		} else if ( type === 'radio' || type === 'checkbox' ) {
			inputEl = el(
				'div',
				{ style: { display: 'flex', flexDirection: 'column', gap: '4px' } },
				( field.options || [] ).map( function ( opt, oi ) {
					return el(
						'label',
						{ key: oi, style: { display: 'flex', alignItems: 'center', gap: '5px', fontSize: '12px', fontWeight: 'normal', color: '#787c82' } },
						el( 'input', { type: type, disabled: true } ),
						typeof opt === 'object' ? opt.label : opt
					);
				} )
			);

		} else {
			inputEl = el( 'input', {
				type:        type === 'hidden' ? 'text' : type,
				disabled:    true,
				placeholder: type === 'hidden' ? '(hidden)' : ( field.placeholder || '' ),
				style:       fieldInputStyle,
			} );
		}

		return el(
			'div',
			{ key: idx, style: { marginBottom: '9px' } },
			labelEl,
			inputEl
		);
	}

	// ── Main render ───────────────────────────────────────────────────────────

	/**
	 * Stateful form preview component.
	 * Owns the "copy JSON" flash state and the "insert shortcode" async state.
	 *
	 * @param {Object} props.detected  The object returned by detectFormJson().
	 * @return {*} React element.
	 */
	function FormPreviewCard( props ) {
		var form    = props.detected.data;
		var prefix  = props.detected.prefix;
		var suffix  = props.detected.suffix;

		var copiedState   = useState( false );
		var copied        = copiedState[ 0 ];
		var setCopied     = copiedState[ 1 ];

		var insertState   = useState( 'idle' );   // 'idle' | 'saving' | 'done' | 'error'
		var insertStatus  = insertState[ 0 ];
		var setInsert     = insertState[ 1 ];

		function copyJson() {
			var json = JSON.stringify( form, null, 2 );
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( json ).then( flashCopied ).catch( fallbackCopy.bind( null, json ) );
			} else {
				fallbackCopy( json );
			}
		}

		function fallbackCopy( json ) {
			var ta = document.createElement( 'textarea' );
			ta.value = json;
			ta.style.position = 'fixed';
			ta.style.opacity  = '0';
			document.body.appendChild( ta );
			ta.select();
			try { document.execCommand( 'copy' ); } catch ( e ) {}
			document.body.removeChild( ta );
			flashCopied();
		}

		function flashCopied() {
			setCopied( true );
			setTimeout( function () { setCopied( false ); }, 2000 );
		}

		/**
		 * Save the form via the native forms REST endpoint and insert the
		 * resulting shortcode as a new Shortcode block in the editor.
		 */
		function insertShortcode() {
			var cfg = window.agenticEditorSidebar || {};
			if ( ! cfg.restUrl ) {
				return; // restUrl (rest_url()) is passed by PHP; nothing to call without it.
			}
			var restBase = cfg.restUrl.replace( /\/$/, '' );
			var nonce    = cfg.nonce || '';

			setInsert( 'saving' );

			fetch( restBase + '/native-forms', {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce,
				},
				body: JSON.stringify( {
					title:        form.title        || 'My Form',
					fields:       form.fields       || [],
					submit_label: form.submit_label || 'Submit',
				} ),
			} )
			.then( function ( res ) {
				if ( ! res.ok ) {
					return res.json().then( function ( d ) { throw new Error( d.message || 'Save failed' ); } );
				}
				return res.json();
			} )
			.then( function ( data ) {
				var shortcode = data.shortcode || ( '[agentic_form id="' + data.id + '"]' );

				// Insert as a WP Shortcode block in the editor.
				if ( wp && wp.blocks && wp.data ) {
					var block = wp.blocks.createBlock( 'core/shortcode', { text: shortcode } );
					wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
				}

				setInsert( 'done' );
				// Reset after 3 s so the button is usable again if needed.
				setTimeout( function () { setInsert( 'idle' ); }, 3000 );
			} )
			.catch( function () {
				setInsert( 'error' );
				setTimeout( function () { setInsert( 'idle' ); }, 4000 );
			} );
		}

		var fieldCount = form.fields.length;

		return el(
			Fragment,
			null,

			// Text before the JSON block (if any).
			prefix && el(
				'div',
				{
					style: {
						maxWidth:    '88%',
						padding:     '8px 12px',
						borderRadius: '12px',
						fontSize:    '13px',
						lineHeight:  '1.5',
						background:  '#f0f0f1',
						color:       '#1d2327',
						whiteSpace:  'pre-wrap',
						wordBreak:   'break-word',
						marginBottom: '6px',
					},
				},
				prefix
			),

			// Form preview card.
			el(
				'div',
				{
					style: {
						border:       '1px solid #c3c4c7',
						borderRadius: '8px',
						overflow:     'hidden',
						background:   '#fff',
						maxWidth:     '100%',
						fontSize:     '13px',
					},
				},

				// Card header.
				el(
					'div',
					{
						style: {
							background:    '#f0f0f1',
							padding:       '8px 12px',
							borderBottom:  '1px solid #e0e0e0',
							display:       'flex',
							alignItems:    'center',
							gap:           '6px',
						},
					},
					el( 'span', { style: { fontSize: '14px' } }, '\uD83D\uDCCB' ),
					el(
						'strong',
						{ style: { fontSize: '12px', color: '#1d2327', flex: '1', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } },
						form.title || 'Form Preview'
					),
					el(
						'span',
						{ style: { fontSize: '10px', color: '#787c82', whiteSpace: 'nowrap' } },
						fieldCount + ' field' + ( fieldCount !== 1 ? 's' : '' )
					)
				),

				// Fields area.
				el(
					'div',
					{ style: { padding: '10px 12px' } },
					form.fields.map( renderField ),
					// Submit button preview.
					el(
						'div',
						{ style: { marginTop: '4px' } },
						el(
							'button',
							{
								disabled: true,
								style: {
									background:   '#2271b1',
									color:        '#fff',
									border:       'none',
									borderRadius: '3px',
									padding:      '6px 14px',
									fontSize:     '12px',
									cursor:       'not-allowed',
									opacity:      '0.85',
								},
							},
							form.submit_label || 'Submit'
						)
					)
				),

				// Action bar.
				el(
					'div',
					{
						style: {
							padding:      '8px 12px',
							borderTop:    '1px solid #e0e0e0',
							display:      'flex',
							gap:          '6px',
							flexWrap:     'wrap',
							background:   '#fafafa',
						},
					},
					el(
						'button',
						{
							onClick: copyJson,
							title:   'Copy form JSON to clipboard',
							style: {
								fontSize:     '11px',
								padding:      '4px 10px',
								border:       '1px solid #c3c4c7',
								borderRadius: '4px',
								background:   '#fff',
								cursor:       'pointer',
								color:        copied ? '#007017' : '#1d2327',
								transition:   'color 0.2s',
							},
						},
						copied ? '\u2713 Copied!' : '\uD83D\uDCCB Copy JSON'
					),
					el(
						'button',
						{
							disabled: insertStatus === 'saving',
							onClick:  insertStatus === 'idle' || insertStatus === 'error' ? insertShortcode : undefined,
							title:    insertStatus === 'done'
								? 'Shortcode inserted into the editor'
								: insertStatus === 'error'
									? 'Something went wrong \u2014 click to retry'
									: 'Save this form and insert the shortcode into the editor',
							style: {
								fontSize:     '11px',
								padding:      '4px 10px',
								border:       '1px solid ' + ( insertStatus === 'error' ? '#d63638' : '#2271b1' ),
								borderRadius: '4px',
								background:   insertStatus === 'done'  ? '#edfaef' :
											  insertStatus === 'error' ? '#fdf0f0' :
											  insertStatus === 'saving'? '#f0f0f1' : '#2271b1',
								cursor:       insertStatus === 'saving' ? 'wait' : 'pointer',
								color:        insertStatus === 'done'   ? '#007017' :
											  insertStatus === 'error'  ? '#d63638' :
											  insertStatus === 'saving' ? '#a7aaad' : '#fff',
								fontWeight:   '500',
								transition:   'all 0.2s',
							},
						},
						insertStatus === 'saving' ? '\u23F3 Saving\u2026' :
						insertStatus === 'done'   ? '\u2713 Inserted!' :
						insertStatus === 'error'  ? '\u26A0 Failed \u2014 retry' :
						'\u2295 Insert shortcode'
					)
				)
			),

			// Text after the JSON block (if any).
			suffix && el(
				'div',
				{
					style: {
						maxWidth:    '88%',
						padding:     '8px 12px',
						borderRadius: '12px',
						fontSize:    '13px',
						lineHeight:  '1.5',
						background:  '#f0f0f1',
						color:       '#1d2327',
						whiteSpace:  'pre-wrap',
						wordBreak:   'break-word',
						marginTop:   '6px',
					},
				},
				suffix
			)
		);
	}

	// ── Register renderer ─────────────────────────────────────────────────────

	window.AGENTIC_RENDERERS.push( {
		id: 'form-preview',

		detect: detectFormJson,

		render: function ( detected ) {
			return el( FormPreviewCard, { detected: detected } );
		},
	} );

}() );
