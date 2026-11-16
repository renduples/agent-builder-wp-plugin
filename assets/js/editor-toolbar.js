/**
 * Agentic Editor Toolbar — Content Writer Toolbar Button
 *
 * Adds a permanent "Content Writer" button to the main Gutenberg block
 * toolbar (the bar that appears above every focused block). Clicking it
 * opens the Siteloom sidebar panel where selection-aware quick-action
 * pills let the user run AI actions on selected text.
 *
 * Relies on window.agenticEditorSidebar config (shared with editor-sidebar.js)
 *
 * No build step — uses global wp.* APIs provided by the block editor.
 *
 * @package Agent_Builder
 * @since   2.3.0
 */
( function () {
	'use strict';

	var config = window.agenticEditorSidebar || {};

	if ( ! config.toolbarEnabled ) {
		return;
	}

	var createElement  = wp.element.createElement;
	var Fragment       = wp.element.Fragment;
	var BlockControls  = wp.blockEditor.BlockControls;
	var ToolbarButton  = wp.components.ToolbarButton;

	// ── AI sparkle icon (SVG) ─────────────────────────────────────────────────
	//
	// Three-sparkle icon — the standard visual shorthand for AI features.

	var aiIcon = createElement(
		'svg',
		{
			xmlns:       'http://www.w3.org/2000/svg',
			viewBox:     '0 0 24 24',
			width:       '20',
			height:      '20',
			fill:        'currentColor',
			'aria-hidden': 'true',
		},
		// Large 4-point star
		createElement( 'path', {
			d: 'M12 2l1.6 5.4L19 9l-5.4 1.6L12 16l-1.6-5.4L5 9l5.4-1.6L12 2z',
		} ),
		// Small star top-right
		createElement( 'path', {
			d: 'M19 4l.8 2.2L22 7l-2.2.8L19 10l-.8-2.2L16 7l2.2-.8L19 4z',
		} ),
		// Small star bottom-left
		createElement( 'path', {
			d: 'M5 14l.7 1.8 1.8.7-1.8.7L5 19l-.7-1.8L2.5 16.5l1.8-.7L5 14z',
		} )
	);

	// ── Content Writer toolbar button ─────────────────────────────────────────
	//
	// Injected into every block's toolbar via the editor.BlockEdit filter.
	// BlockControls renders inside the main block toolbar — always visible
	// when a block is focused, no text selection required.

	function openSidebar() {
		try {
			wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar(
				'agentic-editor-sidebar/agentic-sidebar'
			);
		} catch ( e ) {
			// Sidebar plugin not registered yet — silently ignore.
		}
	}

	function ContentWriterToolbar() {
		return createElement(
			BlockControls,
			{ group: 'other' },
			createElement( ToolbarButton, {
				icon:    aiIcon,
				label:   'Content Writer',
				onClick: openSidebar,
			} )
		);
	}

	wp.hooks.addFilter(
		'editor.BlockEdit',
		'agentic/content-writer-toolbar',
		function ( BlockEdit ) {
			return function ( props ) {
				return createElement(
					Fragment,
					null,
					createElement( BlockEdit, props ),
					createElement( ContentWriterToolbar, null )
				);
			};
		}
	);

}() );
