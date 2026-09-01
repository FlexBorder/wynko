/**
 * Fills and submits a rendered Wynko signup form.
 *
 * The test Laposta list ("lijst om te testen") carries its own custom
 * fields, some required, and those cannot be hidden from a form (Wynko
 * forces a required field visible on every render). Rather than hard-code
 * that schema, this fills every control the form actually shows — email
 * plus whatever else is there — so the submission is valid on Wynko's side
 * regardless of the list's current shape. A field the drift specs add after
 * the page was cached is, by definition, not on the stale page, so it is
 * never filled here — which is exactly what makes the drift fire.
 *
 * The honeypot input (`wynko_website`) is deliberately skipped: filling it
 * is what a bot does, and Wynko would reject the submission.
 */

/**
 * Fills every visible control on the form and clicks submit.
 *
 * @param {import('@playwright/test').Page} page       The page holding the form.
 * @param {Object}                          data       Field values.
 * @param {string}                          data.email Address for the email input.
 * @return {Promise<void>}
 */
async function fillAndSubmit( page, { email } ) {
	const form = page.locator( '.wynko-form__form' );

	await form.locator( 'input[name="wynko_email"]' ).fill( email );

	const textLike = form.locator(
		'input[type="text"], input[type="number"], input[type="tel"], input[type="url"], textarea'
	);
	for ( let i = 0; i < ( await textLike.count() ); i++ ) {
		const el = textLike.nth( i );
		if ( ( await el.getAttribute( 'name' ) ) === 'wynko_website' ) {
			continue;
		}
		const isNumber = ( await el.getAttribute( 'type' ) ) === 'number';
		await el.fill( isNumber ? '1' : 'e2e' );
	}

	const dates = form.locator( 'input[type="date"]' );
	for ( let i = 0; i < ( await dates.count() ); i++ ) {
		await dates.nth( i ).fill( '2030-01-01' );
	}

	const selects = form.locator( 'select' );
	for ( let i = 0; i < ( await selects.count() ); i++ ) {
		await selects
			.nth( i )
			.selectOption( { index: 1 } )
			.catch( () => {} );
	}

	// One radio / checkbox per group name.
	for ( const type of [ 'radio', 'checkbox' ] ) {
		const boxes = form.locator( `input[type="${ type }"]` );
		const seen = new Set();
		for ( let i = 0; i < ( await boxes.count() ); i++ ) {
			const el = boxes.nth( i );
			const name = await el.getAttribute( 'name' );
			if ( seen.has( name ) ) {
				continue;
			}
			seen.add( name );
			await el.check();
		}
	}

	await form.locator( '.wynko-form__submit' ).click();
}

module.exports = { fillAndSubmit };
