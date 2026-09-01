/**
 * The ONLY module in this suite allowed to call api.laposta.nl directly.
 *
 * Specs and fixtures are banned (by the tests/e2e/specs and
 * tests/e2e/fixtures ESLint override in .eslintrc.js, part of the mandatory
 * `npm run lint:js` gate) from writing a literal naming Laposta's own host,
 * so a spec cannot route around the guard below by just not importing this
 * file and calling fetch()/request() against api.laposta.nl itself.
 *
 * The base URL and Basic-auth scheme mirror includes/Api/Client.php exactly
 * (`Authorization: Basic base64(key + ':')` against Config::api_base(),
 * which resolves to https://api.laposta.nl/v2 — see config/urls.php). A
 * literal here is not a NoHardcodedUrlsTest violation: that architecture
 * test scopes to includes/ and config/ only (the PHP the release ZIP
 * ships), not tests/.
 *
 * Path allowlist: only `field` and `member` paths may ever be requested —
 * in particular, never `campaign`. This is a structural guard, not a
 * convention: assertAllowedPath() throws synchronously, before any network
 * call, on anything outside the allowlist. campaign-guard.spec.js is a
 * standing regression test that this guard still throws.
 *
 * Endpoint shapes below are verified against Laposta's published API docs
 * (https://api.laposta.nl/doc/index.en.php):
 *   POST   /v2/field                        list_id, name, datatype, required, in_form, in_list
 *   DELETE /v2/field/{field_id}?list_id=…
 *   GET    /v2/field?list_id=…              -> { data: [ { field: {…} } ] }
 *   GET    /v2/member/{email}?list_id=…     -> { member: {…} }; unknown email -> HTTP 404 or 400 (error code 202)
 *   DELETE /v2/member/{member_id}?list_id=…
 */

const API_BASE = 'https://api.laposta.nl/v2';

/**
 * Path prefixes this client will ever call. Deliberately does not include
 * `campaign` (or `list`, `webhook`, or anything else) — Laposta member/field
 * testing never needs to create or send a campaign, and this suite must
 * structurally be unable to.
 */
const ALLOWED_PATH_PREFIXES = [ 'field', 'member' ];

/**
 * Builds the HTTP Basic authorization header value for a key, identical to
 * Wynko\Api\Client::auth_header().
 *
 * @param {string} apiKey Raw Laposta API key.
 * @return {string} The `Basic ...` header value.
 */
function authHeader( apiKey ) {
	return `Basic ${ Buffer.from( `${ apiKey }:` ).toString( 'base64' ) }`;
}

/**
 * Throws when `path` is not under an allowed prefix. Called before any
 * network request is issued.
 *
 * @param {string} path Path relative to API_BASE, no leading slash.
 */
function assertAllowedPath( path ) {
	const [ prefix ] = path.replace( /^\/+/, '' ).split( /[/?]/ );
	if ( ! ALLOWED_PATH_PREFIXES.includes( prefix ) ) {
		throw new Error(
			`laposta-client: refusing to call disallowed path "${ path }". ` +
				`Only ${ ALLOWED_PATH_PREFIXES.join(
					', '
				) } are permitted — ` +
				'this suite must never create or send a Laposta campaign.'
		);
	}
}

/**
 * One authenticated request to the Laposta API.
 *
 * The request body, when given, is sent form-encoded
 * (`application/x-www-form-urlencoded`) to mirror Wynko\Api\Client exactly —
 * that goes through wp_remote_request(), which serialises an array body with
 * http_build_query(). Laposta's v2 API expects `-d key=value` pairs, not JSON.
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest             Playwright's request fixture.
 * @param {string}                                       apiKey                 Laposta API key.
 * @param {string}                                       method                 HTTP method.
 * @param {string}                                       path                   Path relative to API_BASE, no leading slash.
 * @param {Object}                                       [options]              Request options.
 * @param {Object}                                       [options.form]         Form fields for the request body.
 * @param {boolean}                                      [options.allowMissing] Return null instead of throwing when Laposta says the member does not exist (it answers a lookup for an unknown email with HTTP 404, or HTTP 400 + error code 202 "Invalid member_id").
 * @return {Promise<Object|null>} The parsed JSON response, or null for an allowed "not found".
 */
async function callApi( apiRequest, apiKey, method, path, options = {} ) {
	assertAllowedPath( path );

	const requestOptions = {
		method,
		headers: {
			Authorization: authHeader( apiKey ),
			Accept: 'application/json',
		},
	};
	if ( options.form ) {
		requestOptions.form = options.form;
	}

	// Laposta's load balancer drops idle keep-alive connections, which
	// surfaces here as a one-off "socket hang up". Retry a transport error
	// once before giving up.
	let response;
	try {
		response = await apiRequest.fetch(
			`${ API_BASE }/${ path }`,
			requestOptions
		);
	} catch ( error ) {
		response = await apiRequest.fetch(
			`${ API_BASE }/${ path }`,
			requestOptions
		);
	}

	const text = await response.text();
	let json = {};
	try {
		json = text ? JSON.parse( text ) : {};
	} catch ( error ) {
		json = {};
	}

	if ( ! response.ok() ) {
		const notFound =
			response.status() === 404 ||
			( response.status() === 400 && json?.error?.code === 202 );
		if ( options.allowMissing && notFound ) {
			return null;
		}
		throw new Error(
			`laposta-client: ${ method } ${ path } failed with HTTP ${ response.status() }: ${ text }`
		);
	}

	return json;
}

/**
 * Wynko's four plugin field types mapped to Laposta's five datatypes. The
 * specs pass `type` in plugin terms; Laposta's POST /field wants `datatype`.
 *
 * @type {Object<string,string>}
 */
const DATATYPE_FOR_TYPE = {
	text: 'text',
	number: 'numeric',
	date: 'date',
	choice: 'select_single',
};

/**
 * Adds a field to a list.
 *
 * @param {import('@playwright/test').APIRequestContext}      apiRequest Playwright's request fixture.
 * @param {string}                                            apiKey     Laposta API key.
 * @param {string}                                            listId     Laposta list id.
 * @param {{name: string, type?: string, required?: boolean}} def        Field definition. `name` becomes the field's label; Laposta assigns the field_id and derives custom_name from the label.
 * @return {Promise<Object>} Laposta's created field object — includes `field_id` and the `custom_name` FormRenderer's own input names are built from (see includes/Support/Fields.php's normalize()).
 */
async function addField( apiRequest, apiKey, listId, def ) {
	const payload = await callApi( apiRequest, apiKey, 'POST', 'field', {
		form: {
			list_id: listId,
			name: def.name,
			datatype: DATATYPE_FOR_TYPE[ def.type ] || 'text',
			required: !! def.required,
			// A field Laposta does not put "in the form" never reaches
			// FormRenderer, which would make the drift specs pass without
			// exercising anything. Both flags are required by POST /field.
			in_form: true,
			in_list: true,
		},
	} );

	const field = payload?.field || {};
	if ( ! field.custom_name ) {
		throw new Error(
			`laposta-client: POST /field returned no custom_name (${ JSON.stringify(
				payload
			) }) — Support\\Fields::normalize() would silently drop this field.`
		);
	}
	return field;
}

/**
 * Deletes a field from a list.
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest Playwright's request fixture.
 * @param {string}                                       apiKey     Laposta API key.
 * @param {string}                                       listId     Laposta list id.
 * @param {string}                                       fieldId    Field id to delete.
 * @return {Promise<void>}
 */
async function deleteField( apiRequest, apiKey, listId, fieldId ) {
	await callApi(
		apiRequest,
		apiKey,
		'DELETE',
		`field/${ encodeURIComponent( fieldId ) }?list_id=${ encodeURIComponent(
			listId
		) }`
	);
}

/**
 * Deletes a field and does not resolve until Laposta stops listing it.
 *
 * The drift specs run one after another against the same list; if the next
 * row primes its page while a just-deleted field is still in the list
 * response, its cached form carries an input for a field Laposta no longer
 * has, and the submission fails with an unclassifiable "unknown parameter"
 * (code 203) instead of the field-drift path under test.
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest Playwright's request fixture.
 * @param {string}                                       apiKey     Laposta API key.
 * @param {string}                                       listId     Laposta list id.
 * @param {string}                                       fieldId    Field id to delete.
 * @return {Promise<void>}
 */
async function deleteFieldAndConfirm( apiRequest, apiKey, listId, fieldId ) {
	await deleteField( apiRequest, apiKey, listId, fieldId );

	for ( let attempt = 0; attempt < 10; attempt++ ) {
		const fields = await listFields( apiRequest, apiKey, listId );
		if ( ! fields.some( ( f ) => f.field_id === fieldId ) ) {
			return;
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );
	}
}

/**
 * Lists every field on a list. Exposed mainly for global-teardown.js's
 * best-effort sweep of leftover `wynko_e2e_`-prefixed fields.
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest Playwright's request fixture.
 * @param {string}                                       apiKey     Laposta API key.
 * @param {string}                                       listId     Laposta list id.
 * @return {Promise<Array<Object>>} Field rows.
 */
async function listFields( apiRequest, apiKey, listId ) {
	const payload = await callApi(
		apiRequest,
		apiKey,
		'GET',
		`field?list_id=${ encodeURIComponent( listId ) }`
	);
	if ( Array.isArray( payload?.data ) ) {
		return payload.data.map( ( row ) => row.field || row );
	}
	return [];
}

/**
 * Encodes an email for use in a Laposta `/member/{email}` path. Laposta
 * documents that a `+` must arrive double-encoded as `%252B`.
 *
 * @param {string} email Member email.
 * @return {string} Path-safe email.
 */
function encodeEmailForPath( email ) {
	return encodeURIComponent( email ).replace( /%2B/gi, '%252B' );
}

/**
 * Looks up one member on a list by email.
 *
 * Laposta has no "filter the member list by email" endpoint — a single
 * member is fetched at `GET /v2/member/{email}?list_id=…`, which 404s when
 * no such member exists. The array return shape is kept so callers can
 * assert on `.length` (0 = absent, 1 = present).
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest Playwright's request fixture.
 * @param {string}                                       apiKey     Laposta API key.
 * @param {string}                                       listId     Laposta list id.
 * @param {string}                                       email      Member email to look up.
 * @return {Promise<Array<Object>>} `[member]` when found, `[]` when not.
 */
async function listMembers( apiRequest, apiKey, listId, email ) {
	const payload = await callApi(
		apiRequest,
		apiKey,
		'GET',
		`member/${ encodeEmailForPath( email ) }?list_id=${ encodeURIComponent(
			listId
		) }`,
		{ allowMissing: true }
	);
	return payload?.member ? [ payload.member ] : [];
}

/**
 * Deletes one member from a list.
 *
 * @param {import('@playwright/test').APIRequestContext} apiRequest Playwright's request fixture.
 * @param {string}                                       apiKey     Laposta API key.
 * @param {string}                                       listId     Laposta list id.
 * @param {string}                                       memberId   Member id (or email) to delete.
 * @return {Promise<void>}
 */
async function deleteMember( apiRequest, apiKey, listId, memberId ) {
	await callApi(
		apiRequest,
		apiKey,
		'DELETE',
		`member/${ encodeEmailForPath(
			memberId
		) }?list_id=${ encodeURIComponent( listId ) }`,
		{ allowMissing: true }
	);
}

module.exports = {
	addField,
	deleteField,
	deleteFieldAndConfirm,
	listFields,
	listMembers,
	deleteMember,
	// Exported only for campaign-guard.spec.js's regression test.
	__assertAllowedPathForTest: assertAllowedPath,
};
