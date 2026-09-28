/**
 * Webpack configuration for Agent Builder admin React surfaces.
 *
 * Extends the default @wordpress/scripts config and declares a named entry
 * per admin surface so the generated bundle and its companion .asset.php
 * file are predictably named (e.g. build/interface-settings.js +
 * build/interface-settings.asset.php).
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'interface-settings': './src/interface-settings/index.js',
		'dashboard-activity': './src/dashboard-activity/index.js',
		'dashboard-app': './src/dashboard-app/index.js',
		'agent-wizard': './src/agent-wizard/index.js',
		'knowledge-wizard': './src/knowledge-wizard/index.js',
		'deploy-wizard': './src/deploy-wizard/index.js',
		'settings-app': './src/settings-app/index.js',
		'admin-list': './src/admin-list/index.js',
		'admin-pages': './src/admin-pages/index.js',
		'tasks-app': './src/tasks-app/index.js',
	},
};
