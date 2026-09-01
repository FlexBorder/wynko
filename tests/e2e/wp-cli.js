/**
 * Thin wrapper around `npx @wordpress/env run tests-cli wp ...`.
 *
 * `tests-cli`, not `cli`: the Playwright suite drives the wp-env *tests*
 * instance (port 8889), so every option, `wp eval`, and mu-plugin state this
 * helper touches must land in that same install, not the development one.
 */

const { spawn } = require( 'child_process' );

/**
 * Runs one WP-CLI command inside the wp-env `tests-cli` container.
 *
 * @param {string[]} args WP-CLI arguments, without the leading `wp`.
 * @return {Promise<string>} Trimmed stdout.
 */
function wpCli( args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn(
			'npx',
			[ '@wordpress/env', 'run', 'tests-cli', 'wp', ...args ],
			{ shell: process.platform === 'win32' }
		);

		let stdout = '';
		let stderr = '';
		child.stdout.on( 'data', ( chunk ) => {
			stdout += chunk.toString();
		} );
		child.stderr.on( 'data', ( chunk ) => {
			stderr += chunk.toString();
		} );

		child.on( 'close', ( code ) => {
			if ( code !== 0 ) {
				reject(
					new Error(
						`wp-cli: "wp ${ args.join( ' ' ) }" exited ${ code }: ${
							stderr || stdout
						}`
					)
				);
				return;
			}
			resolve( stdout.trim() );
		} );
	} );
}

/**
 * Sets or updates one WP option.
 *
 * @param {string} name  Option name.
 * @param {string} value Option value.
 * @return {Promise<string>} WP-CLI's output.
 */
function setOption( name, value ) {
	return wpCli( [ 'option', 'update', name, value ] );
}

/**
 * Deletes one WP option, ignoring the case where it does not exist.
 *
 * @param {string} name Option name.
 * @return {Promise<void>}
 */
async function deleteOption( name ) {
	try {
		await wpCli( [ 'option', 'delete', name ] );
	} catch ( error ) {
		// Already absent — nothing to clean up.
	}
}

/**
 * Reads one WP option as a raw string, '' when it does not exist.
 *
 * @param {string} name Option name.
 * @return {Promise<string>} The option's JSON-encoded value, '' when absent.
 */
async function getOption( name ) {
	try {
		return await wpCli( [ 'option', 'get', name, '--format=json' ] );
	} catch ( error ) {
		return '';
	}
}

/**
 * Evaluates one PHP expression inside the wp-env container, in full WP
 * context (so Wynko's own namespaced classes are autoloaded and reachable).
 *
 * @param {string} php PHP code, no wrapping `<?php` tag.
 * @return {Promise<string>} Whatever the PHP echoed.
 */
function evalPhp( php ) {
	return wpCli( [ 'eval', php ] );
}

module.exports = {
	wpCli,
	setOption,
	deleteOption,
	getOption,
	evalPhp,
};
