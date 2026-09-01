/**
 * Submits an Wynko signup form in place.
 *
 * Everything the visitor reads is rendered by the server and returned whole, so
 * this file words nothing and validates nothing. Without it, or when the request
 * fails, the form posts to admin-post.php and comes back through a redirect.
 */
import './form.scss';
import { delegateHelp } from '../shared/help-toggle';

const config = window.wynkoForm || {};

// Mirrors FormSubmitHandler::STATUS_NOT_FOUND — a bad nonce and an unknown
// form deliberately answer alike, so this string covers both.
const STATUS_NOT_FOUND = 'not_found';

/**
 * The header that stops core from downgrading a cookie-authenticated request
 * to user 0. Every REST call this file makes must send it: the submit nonce
 * is user-scoped, so if the nonce-refresh request and the retried submit
 * resolve to different users, a freshly minted nonce still fails to verify.
 *
 * @return {Object} Headers for a same-origin REST call.
 */
function restHeaders() {
	return config.restNonce ? { 'X-WP-Nonce': config.restNonce } : {};
}

/**
 * Posts one submission.
 *
 * @param {HTMLFormElement} form   The form element.
 * @param {string}          formId The submitted form's id.
 * @return {Promise<Object>} The parsed JSON payload.
 */
async function postForm( form, formId ) {
	const response = await window.fetch(
		`${ config.restRoot }forms/${ formId }/submit`,
		{
			method: 'POST',
			body: new window.FormData( form ),
			credentials: 'same-origin',
			headers: restHeaders(),
		}
	);
	return response.json();
}

/**
 * Refetches a live nonce for one form and writes it into its hidden field.
 *
 * The page's own copy may be stale — baked into HTML a caching plugin served
 * older than the nonce's own lifetime — but this route runs live on every
 * request, so its answer never is.
 *
 * @param {HTMLFormElement} form   The form element.
 * @param {string}          formId The form's id.
 * @return {Promise<boolean>} Whether a fresh nonce was written.
 */
async function refreshNonce( form, formId ) {
	const field = form.querySelector( '[name="wynko_nonce"]' );
	if ( ! field ) {
		return false;
	}

	try {
		const response = await window.fetch(
			`${ config.restRoot }forms/${ formId }/nonce`,
			{ credentials: 'same-origin', headers: restHeaders() }
		);
		const payload = await response.json();
		if ( ! payload.nonce ) {
			return false;
		}
		field.value = payload.nonce;
		return true;
	} catch ( error ) {
		return false;
	}
}

/**
 * Handles one submission.
 *
 * @param {SubmitEvent} event The submit event.
 */
async function submit( event ) {
	const form = event.target.closest( '.wynko-form__form' );
	if ( ! form || ! config.restRoot ) {
		return;
	}

	// The form's own container, never the page's first one: a page may hold
	// several forms and each must answer only for itself.
	const container = form.closest( '.wynko-form' );
	const formId = form.querySelector( '[name="wynko_form_id"]' )?.value;
	if ( ! container || ! formId ) {
		return;
	}

	event.preventDefault();

	const button = form.querySelector( '.wynko-form__submit' );
	if ( button ) {
		button.disabled = true;
	}
	container.setAttribute( 'aria-busy', 'true' );

	try {
		let payload = await postForm( form, formId );

		// The page's embedded nonce may simply be older than a page-caching
		// plugin kept the page around — indistinguishable, by design, from a
		// form that no longer exists (see FormSubmitHandler::handle()'s
		// prober-oracle note). Since this page just rendered the form, the
		// safer read for the one real visitor is "stale", so a fresh nonce is
		// worth one retry before treating it as gone for good.
		if (
			STATUS_NOT_FOUND === payload.status &&
			( await refreshNonce( form, formId ) )
		) {
			payload = await postForm( form, formId );
		}

		if ( payload.redirect ) {
			window.location.assign( payload.redirect );
			return;
		}

		if ( ! payload.html ) {
			throw new Error( 'wynko: empty response' );
		}

		// Replace through a fragment so the inserted node is in hand.
		// Re-querying the document by form id would miss the
		// hide_after_submit case, whose markup carries no per-form class.
		const fragment = document
			.createRange()
			.createContextualFragment( payload.html );
		const inserted = fragment.firstElementChild;
		container.replaceWith( fragment );

		// An invalid submission renders no notice, so the first field that
		// failed is what the visitor needs to be taken to instead.
		const target =
			inserted?.querySelector( '.wynko-form__notice' ) ||
			inserted?.querySelector( '[aria-invalid="true"]' );
		if ( target ) {
			if ( ! target.hasAttribute( 'tabindex' ) ) {
				target.setAttribute( 'tabindex', '-1' );
			}
			// Keeping the viewport where the visitor left it is the whole
			// point of not reloading — but only while the thing being focused
			// is still on screen. Errors render in the flow, so each one above
			// the target pushes it further down than it was when the visitor
			// pressed submit; preventing the scroll then would put the cursor
			// somewhere they cannot see.
			const box = target.getBoundingClientRect();
			const onScreen = box.top >= 0 && box.bottom <= window.innerHeight;
			target.focus( { preventScroll: onScreen } );
		}
	} catch ( error ) {
		// A failed request must not lose the submission: fall back to the
		// ordinary post, which the server handles the same way.
		container.removeAttribute( 'aria-busy' );
		form.submit();
	}
}

/**
 * Puts the number a slider is on beside it.
 *
 * The server renders the starting number, so this only follows the thumb from
 * there — and it is delegated on the document because a submission replaces
 * the whole form with fresh markup, which anything bound per element would not
 * survive.
 *
 * @param {HTMLInputElement} input The range input that moved.
 */
function showRangeValue( input ) {
	const output = input
		.closest( '.wynko-form' )
		?.querySelector( `output[for="${ input.id }"]` );
	if ( output ) {
		output.textContent = input.value;
	}
}

document.addEventListener( 'input', ( event ) => {
	const input = event.target;
	if ( input.matches?.( '.wynko-form input[type="range"]' ) ) {
		showRangeValue( input );
	}
} );

document.addEventListener( 'submit', submit );
delegateHelp( '.wynko-form__help-toggle' );
