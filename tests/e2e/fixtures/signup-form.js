/**
 * Creates/deletes a Wynko signup form bound to the test Laposta list
 * ("lijst om te testen"), via WP-CLI against the `wynko_form` custom post
 * type (see includes/Forms/FormData.php and config/settings.php's `forms`
 * section for the post type and meta-key names this mirrors).
 */

const { wpCli } = require( '../wp-cli' );
const { TEST_LIST_ID } = require( '../env' );

/**
 * Creates one published signup form bound to TEST_LIST_ID.
 *
 * @param {string} title Admin-facing form name.
 * @return {Promise<number>} The created form's post id.
 */
async function createSignupForm( title ) {
	if ( '' === TEST_LIST_ID ) {
		throw new Error(
			'fixtures/signup-form: WYNKO_TEST_LIST_ID is not set — cannot bind a form to "lijst om te testen".'
		);
	}

	const id = await wpCli( [
		'post',
		'create',
		'--post_type=wynko_form',
		'--post_status=publish',
		`--post_title=${ title }`,
		'--porcelain',
	] );

	await wpCli( [
		'post',
		'meta',
		'update',
		id,
		'_wynko_list_id',
		TEST_LIST_ID,
	] );

	return parseInt( id, 10 );
}

/**
 * Deletes a form created by createSignupForm(), bypassing trash.
 *
 * @param {number} formId Form post id.
 * @return {Promise<void>}
 */
async function deleteSignupForm( formId ) {
	await wpCli( [ 'post', 'delete', String( formId ), '--force' ] ).catch(
		() => {
			// Already gone — nothing to clean up.
		}
	);
}

/**
 * The shortcode markup for a created form, to place on a test page.
 *
 * @param {number} formId Form post id.
 * @return {string} The `[wynko_form id="..."]` shortcode.
 */
function shortcodeFor( formId ) {
	return `[wynko_form id="${ formId }"]`;
}

/**
 * Publishes a page embedding a form's shortcode, and returns its front-end
 * relative URL.
 *
 * @param {string} title  Page title.
 * @param {number} formId Form post id to embed.
 * @return {Promise<{pageId: number, path: string}>} The created page's id and relative path.
 */
async function createSignupPage( title, formId ) {
	const id = await wpCli( [
		'post',
		'create',
		'--post_type=page',
		'--post_status=publish',
		`--post_title=${ title }`,
		`--post_content=${ shortcodeFor( formId ) }`,
		'--porcelain',
	] );
	const permalink = await wpCli( [
		'eval',
		`echo get_permalink( ${ id } );`,
	] );
	return { pageId: parseInt( id, 10 ), path: new URL( permalink ).pathname };
}

/**
 * Deletes a page created by createSignupPage(), bypassing trash.
 *
 * @param {number} pageId Page post id.
 * @return {Promise<void>}
 */
async function deleteSignupPage( pageId ) {
	await wpCli( [ 'post', 'delete', String( pageId ), '--force' ] ).catch(
		() => {
			// Already gone — nothing to clean up.
		}
	);
}

module.exports = {
	createSignupForm,
	deleteSignupForm,
	createSignupPage,
	deleteSignupPage,
	shortcodeFor,
};
