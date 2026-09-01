/**
 * Reads Wynko's activity log via WP-CLI `wp eval`, in full WP context so
 * Wynko\Log::all() and Wynko\Config are reachable directly rather than
 * guessing at the option name the log is stored under.
 */

const { evalPhp } = require( './wp-cli' );

/**
 * Counts activity-log entries at a given level whose message contains a
 * substring.
 *
 * @param {string} substring Text the entry's message must contain.
 * @param {string} [level]   One of Wynko's log levels ('info', 'warning',
 *                           'error'); omitted to match any level.
 * @return {Promise<number>} How many matching entries exist.
 */
async function countEntries( substring, level ) {
	const needle = JSON.stringify( substring );
	const levelCheck = level ? `'${ level }' === $e['level'] && ` : '';
	const php =
		'echo count( array_filter( \\Wynko\\Log::all(), function( $e ) { ' +
		`return ${ levelCheck }false !== strpos( $e['message'], ${ needle } ); ` +
		'} ) );';
	const out = await evalPhp( php );
	return parseInt( out, 10 ) || 0;
}

/**
 * Clears the whole activity log, so a spec can assert against a known-empty
 * starting state.
 *
 * @return {Promise<void>}
 */
async function clear() {
	await evalPhp(
		"update_option( \\Wynko\\Config::option_key( 'log' ), array(), false );"
	);
}

module.exports = {
	countEntries,
	clear,
};
