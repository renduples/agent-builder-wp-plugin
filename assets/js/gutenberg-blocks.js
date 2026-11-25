/**
 * Agentic Gutenberg Blocks
 *
 * Dynamically registers a Gutenberg block for each agent enabled on the
 * Deploy Agents > Gutenberg Blocks tab. Each block renders a server-side
 * chat interface via ServerSideRender and provides sidebar controls for
 * height, theme, display style, LLM provider/model, floating, etc.
 *
 * @package Agent_Builder
 * @since   2.8.0
 */
( function () {
	'use strict';

	var registerBlockType  = wp.blocks.registerBlockType;
	var createElement      = wp.element.createElement;
	var Fragment           = wp.element.Fragment;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var useBlockProps       = wp.blockEditor.useBlockProps;
	var PanelBody          = wp.components.PanelBody;
	var TextControl        = wp.components.TextControl;
	var SelectControl      = wp.components.SelectControl;
	var ToggleControl      = wp.components.ToggleControl;
	var Placeholder        = wp.components.Placeholder;
	var __                 = wp.i18n.__;
	var ServerSideRender   = wp.serverSideRender || ( wp.components && wp.components.ServerSideRender );

	var config = window.agenticBlocks || {};
	var agents = config.agents || [];
	var themes = config.themes || {};
	var providers = config.providers || {};
	var providerModels = config.providerModels || {};

	// Chat bubble SVG icon for the block inserter.
	var chatIcon = createElement( 'svg', {
		xmlns: 'http://www.w3.org/2000/svg',
		width: 24,
		height: 24,
		viewBox: '0 0 24 24',
		fill: 'none',
		stroke: 'currentColor',
		strokeWidth: 2,
		strokeLinecap: 'round',
		strokeLinejoin: 'round',
	},
		createElement( 'path', { d: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z' } ),
		createElement( 'path', { d: 'M8 10h.01' } ),
		createElement( 'path', { d: 'M12 10h.01' } ),
		createElement( 'path', { d: 'M16 10h.01' } )
	);

	// Build theme options for the SelectControl.
	var themeOptions = Object.keys( themes ).map( function ( key ) {
		return { label: themes[ key ], value: key };
	} );

	// Build provider options.
	var providerOptions = [ { label: __( 'Default (from Settings)', 'agentstructor' ), value: '' } ];
	Object.keys( providers ).forEach( function ( key ) {
		providerOptions.push( { label: providers[ key ], value: key } );
	} );

	// Build model options for a given provider.
	function getModelOptions( providerSlug ) {
		var options = [ { label: __( 'Default', 'agentstructor' ), value: '' } ];
		if ( providerSlug && providerModels[ providerSlug ] ) {
			var models = providerModels[ providerSlug ];
			if ( Array.isArray( models ) ) {
				models.forEach( function ( m ) {
					options.push( { label: m, value: m } );
				} );
			}
		}
		return options;
	}

	// Style options.
	var styleOptions = [
		{ label: __( 'Inline', 'agentstructor' ), value: 'inline' },
		{ label: __( 'Popup', 'agentstructor' ), value: 'popup' },
		{ label: __( 'Sidebar', 'agentstructor' ), value: 'sidebar' },
	];

	agents.forEach( function ( agent ) {
		var blockName = 'agentic/' + agent.slug;

		registerBlockType( blockName, {
			title: agent.name,
			description: agent.description,
			category: 'agentic-ai',
			icon: chatIcon,
			keywords: [ 'chat', 'ai', 'gpt', 'bot', 'agent', 'assistant', agent.name.toLowerCase() ],
			supports: {
				html: false,
				align: [ 'wide', 'full' ],
				multiple: true,
			},
			attributes: {
				height: { type: 'string', default: '500px' },
				style: { type: 'string', default: 'inline' },
				theme: { type: 'string', default: '' },
				provider: { type: 'string', default: '' },
				model: { type: 'string', default: '' },
				showHeader: { type: 'boolean', default: true },
				placeholder: { type: 'string', default: '' },
				floating: { type: 'boolean', default: false },
			},

			edit: function ( props ) {
				var attributes    = props.attributes;
				var setAttributes = props.setAttributes;
				var blockProps    = useBlockProps();

				// Preview placeholder for the editor.
				var preview = createElement(
					'div',
					Object.assign( {}, blockProps, {
						style: { padding: 0 },
					} ),
					createElement(
						'div',
						{
							style: {
								border: '1px solid #ddd',
								borderRadius: '8px',
								overflow: 'hidden',
								background: attributes.theme === 'light' ? '#ffffff' : ( attributes.theme === 'ocean' ? '#0d1b2a' : ( attributes.theme === 'midnight' ? '#0f0f1a' : '#1a1a2e' ) ),
								color: attributes.theme === 'light' ? '#1e1e1e' : '#e0e0e0',
								fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
								height: attributes.height || '500px',
								maxHeight: '500px',
								display: 'flex',
								flexDirection: 'column',
							},
						},
						// Header.
						attributes.showHeader ? createElement(
							'div',
							{
								style: {
									padding: '12px 16px',
									borderBottom: '1px solid ' + ( attributes.theme === 'light' ? '#e0e0e0' : 'rgba(255,255,255,0.1)' ),
									display: 'flex',
									alignItems: 'center',
									gap: '10px',
									flexShrink: 0,
								},
							},
							createElement( 'span', { style: { fontSize: '20px' } }, agent.icon ),
							createElement( 'strong', { style: { fontSize: '14px' } }, agent.name ),
							createElement( 'span', {
								style: {
									marginLeft: 'auto',
									fontSize: '10px',
									padding: '2px 8px',
									borderRadius: '10px',
									background: attributes.theme === 'light' ? '#e8f5e9' : 'rgba(76,175,80,0.2)',
									color: '#4caf50',
								},
							}, __( 'Online', 'agentstructor' ) )
						) : null,
						// Messages area.
						createElement(
							'div',
							{
								style: {
									flex: 1,
									padding: '16px',
									display: 'flex',
									flexDirection: 'column',
									gap: '12px',
									overflow: 'hidden',
								},
							},
							// Welcome message bubble.
							createElement(
								'div',
								{
									style: {
										display: 'flex',
										gap: '8px',
										alignItems: 'flex-start',
									},
								},
								createElement( 'span', {
									style: {
										width: '28px',
										height: '28px',
										borderRadius: '50%',
										background: attributes.theme === 'light' ? '#e3f2fd' : 'rgba(100,100,255,0.2)',
										display: 'flex',
										alignItems: 'center',
										justifyContent: 'center',
										fontSize: '14px',
										flexShrink: 0,
									},
								}, agent.icon ),
								createElement(
									'div',
									{
										style: {
											background: attributes.theme === 'light' ? '#f5f5f5' : 'rgba(255,255,255,0.08)',
											padding: '10px 14px',
											borderRadius: '12px',
											borderTopLeftRadius: '4px',
											fontSize: '13px',
											lineHeight: '1.5',
											maxWidth: '80%',
										},
									},
									__( 'Hello! I\'m ', 'agentstructor' ) + agent.name + '. ' + __( 'How can I help you today?', 'agentstructor' )
								)
							),
							// Floating style badge.
							attributes.floating ? createElement(
								'div',
								{
									style: {
										textAlign: 'center',
										padding: '8px',
										fontSize: '11px',
										opacity: 0.6,
										fontStyle: 'italic',
									},
								},
								__( 'Floating mode — appears as a popup button on the frontend', 'agentstructor' )
							) : null
						),
						// Input area.
						createElement(
							'div',
							{
								style: {
									padding: '12px 16px',
									borderTop: '1px solid ' + ( attributes.theme === 'light' ? '#e0e0e0' : 'rgba(255,255,255,0.1)' ),
									display: 'flex',
									alignItems: 'center',
									gap: '8px',
									flexShrink: 0,
								},
							},
							createElement( 'div', {
								style: {
									flex: 1,
									padding: '8px 12px',
									background: attributes.theme === 'light' ? '#f5f5f5' : 'rgba(255,255,255,0.06)',
									borderRadius: '20px',
									fontSize: '13px',
									opacity: 0.5,
									color: attributes.theme === 'light' ? '#666' : '#aaa',
								},
							}, attributes.placeholder || __( 'Type your message...', 'agentstructor' ) ),
							createElement( 'div', {
								style: {
									width: '32px',
									height: '32px',
									borderRadius: '50%',
									background: '#6366f1',
									display: 'flex',
									alignItems: 'center',
									justifyContent: 'center',
									color: '#fff',
									fontSize: '14px',
									cursor: 'default',
									flexShrink: 0,
								},
							}, '\u2191' )
						)
					)
				);

				// Sidebar inspector controls.
				var sidebar = createElement(
					InspectorControls,
					null,
					// Appearance panel.
					createElement(
						PanelBody,
						{ title: __( 'Appearance', 'agentstructor' ), initialOpen: true },
						createElement( SelectControl, {
							label: __( 'Display Style', 'agentstructor' ),
							value: attributes.style,
							options: styleOptions,
							onChange: function ( val ) { setAttributes( { style: val } ); },
						} ),
						createElement( TextControl, {
							label: __( 'Height', 'agentstructor' ),
							value: attributes.height,
							onChange: function ( val ) { setAttributes( { height: val } ); },
							help: __( 'CSS height value (e.g. 500px, 60vh).', 'agentstructor' ),
						} ),
						createElement( SelectControl, {
							label: __( 'Theme', 'agentstructor' ),
							value: attributes.theme,
							options: themeOptions,
							onChange: function ( val ) { setAttributes( { theme: val } ); },
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Header', 'agentstructor' ),
							checked: attributes.showHeader,
							onChange: function ( val ) { setAttributes( { showHeader: val } ); },
						} ),
						createElement( ToggleControl, {
							label: __( 'Floating Button', 'agentstructor' ),
							checked: attributes.floating,
							onChange: function ( val ) { setAttributes( { floating: val } ); },
							help: __( 'Display as a floating chat button instead of inline.', 'agentstructor' ),
						} ),
						createElement( TextControl, {
							label: __( 'Placeholder Text', 'agentstructor' ),
							value: attributes.placeholder,
							onChange: function ( val ) { setAttributes( { placeholder: val } ); },
						} )
					),
					// LLM Settings panel.
					createElement(
						PanelBody,
						{ title: __( 'LLM Settings', 'agentstructor' ), initialOpen: false },
						createElement( SelectControl, {
							label: __( 'Provider', 'agentstructor' ),
							value: attributes.provider,
							options: providerOptions,
							onChange: function ( val ) {
								setAttributes( { provider: val, model: '' } );
							},
							help: __( 'Override the LLM provider for this block.', 'agentstructor' ),
						} ),
						createElement( SelectControl, {
							label: __( 'Model', 'agentstructor' ),
							value: attributes.model,
							options: getModelOptions( attributes.provider ),
							onChange: function ( val ) { setAttributes( { model: val } ); },
							help: attributes.provider ? '' : __( 'Select a provider first to see available models.', 'agentstructor' ),
						} )
					)
				);

				return createElement( Fragment, null, sidebar, preview );
			},

			save: function () {
				// Server-side rendered — return null.
				return null;
			},
		} );
	} );
} )();
