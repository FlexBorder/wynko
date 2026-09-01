/**
 * The page-caching plugins `optional-field-drift.spec.js` runs behind, once
 * each — the point being to prove Wynko's field-set fingerprint detection
 * works under real full-page caching, not just one plugin's.
 * (`required-field-drift.spec.js` imports one adapter directly rather than
 * looping; its trigger is Wynko's own cache, not the page cache.)
 *
 * LiteSpeed Cache and WP Rocket are deliberately absent: LiteSpeed needs a
 * LiteSpeed/OpenLiteSpeed server to do page caching (wp-env runs Apache) and
 * WP Rocket is premium, so `wp plugin install` cannot fetch it. See
 * TECHNICAL_DEBT.md.
 */

const { renderCount } = require( './helpers' );

const pageCaches = [
	require( './cache-enabler' ),
	require( './wp-super-cache' ),
	require( './w3-total-cache' ),
	require( './wp-fastest-cache' ),
];

module.exports = { pageCaches, renderCount };
