/**
 * Project ESLint config. Presence of this file replaces wp-scripts' own
 * default (see node_modules/@wordpress/scripts/scripts/lint-js.js), so it
 * re-extends the same base wp-scripts would otherwise supply and adds one
 * project-specific override: tests/e2e/laposta-client.js is the only module
 * allowed to talk to the Laposta API directly (see its own allowlist guard).
 * Specs and fixtures may still legitimately use `fetch`/Playwright's
 * `request` fixture against WordPress itself (e.g. a raw admin-post.php
 * POST in nonce-no-js.spec.js), so rather than banning those identifiers
 * outright, the guard below targets the one thing that would actually route
 * around laposta-client.js's allowlist: a call carrying a literal or
 * template string that names Laposta's own host.
 */

module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	overrides: [
		{
			// Unit test files and their helpers only.
			files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
			extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
		},
		{
			// Specs and fixtures only — laposta-client.js itself, and the
			// suite's own global-setup/teardown/wp-cli infrastructure, are
			// deliberately outside this override: they either *are* the
			// allowed caller or use Playwright's `request` fixture only to
			// authenticate against wp-env, never against Laposta.
			files: [ 'tests/e2e/specs/**/*.js', 'tests/e2e/fixtures/**/*.js' ],
			rules: {
				'no-restricted-syntax': [
					'error',
					{
						selector:
							'CallExpression > Literal[value=/laposta\\.nl/i]',
						message:
							'Only tests/e2e/laposta-client.js may call the Laposta API directly. Use its exported helpers instead.',
					},
					{
						selector:
							'CallExpression > TemplateLiteral > TemplateElement[value.raw=/laposta\\.nl/i]',
						message:
							'Only tests/e2e/laposta-client.js may call the Laposta API directly. Use its exported helpers instead.',
					},
				],
			},
		},
	],
};
