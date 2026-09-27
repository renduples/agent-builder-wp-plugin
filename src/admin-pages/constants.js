import { __ } from '@wordpress/i18n';

const RISK_EXPLANATIONS = {
	none: __( 'Safe to run automatically — read-only, no approval needed.', 'agent-builder' ),
	low: __( 'Runs automatically by default; may read data that includes personal information.', 'agent-builder' ),
	medium: __( 'Changes something — agents pause for your in-chat confirmation first.', 'agent-builder' ),
	high: __( 'A significant or bulk change — waits in the Approvals queue for you to allow it.', 'agent-builder' ),
	extreme: __( 'Too risky to allow at all — hidden from agents entirely, cannot be enabled.', 'agent-builder' ),
};

// Per-tool HIGH reasons from docs/m2-safety-center-design.md §3.5.
// Everything else falls back to the HIGH tier sentence in RISK_EXPLANATIONS
// rather than inventing copy for every HIGH tool.

const SCREENS_WITH_MODE = [
	'tools',
	'skills',
	'approvals',
	'logs',
	'agent-ready',
	'safety-center',
];

export { RISK_EXPLANATIONS, SCREENS_WITH_MODE };
