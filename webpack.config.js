/**
 * Non-block bundles. The two blocks under src/block* are discovered by
 * @wordpress/scripts from their block.json; admin and front-end assets are not
 * blocks, so they need explicit entries.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		'admin/forms': './src/admin/forms.js',
		'frontend/form': './src/frontend/form.js',
	},
};
