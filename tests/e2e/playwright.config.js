/**
 * Extends @wordpress/scripts' default Playwright config (testDir './specs'
 * relative to this file, its own globalSetup calling RequestUtils.setupRest(),
 * webServer.command 'npm run wp-env start', baseURL from WP_BASE_URL) with
 * this suite's own global setup/teardown (live-key injection, WP Super
 * Cache install), its own testDir/outputDir, and a Markdown run-report
 * reporter appended to the inherited list — kept alongside this file
 * rather than at the repo root.
 */

const defaultConfig = require( '@wordpress/scripts/config/playwright.config.js' );
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	...defaultConfig,
	testDir: './specs',
	globalSetup: require.resolve( './global-setup.js' ),
	globalTeardown: require.resolve( './global-teardown.js' ),
	// Append, never replace: the inherited list/github reporter still runs;
	// this one adds the Markdown run summary (written outside the repo).
	reporter: [
		...( defaultConfig.reporter || [ [ 'list' ] ] ),
		[ require.resolve( './reporters/markdown-report.js' ) ],
	],
} );
