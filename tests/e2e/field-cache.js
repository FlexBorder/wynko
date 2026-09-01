/**
 * Forces Wynko's own field-definitions cache to refresh, the same call the
 * admin "Refresh fields" button and a signup's own drift-retry trigger
 * (Wynko\Api\Fields::for_list( $list_id, true )), via `wp eval`.
 */

const { evalPhp } = require( './wp-cli' );

/**
 * Forces a fresh fetch of one list's fields into Wynko's own transient
 * cache, independent of whatever a page cache in front of the site is
 * still serving.
 *
 * @param {string} listId Laposta list id.
 * @return {Promise<number>} How many fields Wynko now has cached for the list.
 */
async function forceRefresh( listId ) {
	const out = await evalPhp(
		`$r = \\Wynko\\Api\\Fields::for_list( '${ listId.replace(
			/'/g,
			"\\'"
		) }', true ); echo count( $r['fields'] );`
	);
	return parseInt( out, 10 ) || 0;
}

module.exports = { forceRefresh };
