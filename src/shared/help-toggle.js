/**
 * Tooltip behaviour, shared by the rendered form and the form editor.
 *
 * A tooltip that opens on hover or focus and closes on the way out is what both
 * surfaces are expected to do. The markup differs, so the caller names its own
 * toggle and only the behaviour is shared.
 */

/**
 * Shows or hides the text one toggle controls.
 *
 * The text is in the DOM either way and referenced through aria, so this only
 * governs whether it is on screen.
 *
 * @param {HTMLElement} button The toggle.
 * @param {boolean}     show   Whether its text should be on screen.
 */
function setOpen( button, show ) {
	const text = document.getElementById(
		button.getAttribute( 'aria-controls' )
	);
	if ( ! text ) {
		return;
	}

	button.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
	text.hidden = ! show;
}

/**
 * Closes every open tooltip except the one being opened.
 *
 * Two on screen at once is what makes them read as overlapping: they are
 * overlays, so the second lands on top of whatever the first covered.
 *
 * @param {string}           selector Toggle selector.
 * @param {HTMLElement|null} keep     The toggle whose text may stay open.
 */
function closeAll( selector, keep ) {
	document
		.querySelectorAll( `${ selector }[aria-expanded="true"]` )
		.forEach( ( button ) => {
			if ( button !== keep ) {
				setOpen( button, false );
			}
		} );
}

/**
 * Wires one surface's tooltips.
 *
 * Delegated on the document rather than bound per button, so the tooltips keep
 * working after markup is swapped in. A tap opens one as well as a hover does,
 * since a touch screen has no hover to give.
 *
 * @param {string} selector Selector matching this surface's toggle buttons.
 */
export function delegateHelp( selector ) {
	const handle = ( event ) => {
		if ( 'keydown' === event.type ) {
			if ( 'Escape' === event.key ) {
				closeAll( selector, null );
			}
			return;
		}

		const button = event.target.closest?.( selector );

		if ( 'pointerout' === event.type || 'focusout' === event.type ) {
			// These bubble, so crossing onto the button's own icon reads as
			// leaving the button; closing on that would shut a tooltip the
			// pointer had just opened. relatedTarget is null when the pointer
			// leaves the window, and contains( null ) is false, so that closes.
			if ( button && ! button.contains( event.relatedTarget ) ) {
				setOpen( button, false );
			}
			return;
		}

		if ( ! button ) {
			// A tap or a focus elsewhere dismisses what a tap opened. A pointer
			// merely passing over other markup dismisses nothing: the toggle it
			// left reports that itself, through pointerout.
			if ( 'pointerover' !== event.type ) {
				closeAll( selector, null );
			}
			return;
		}

		closeAll( selector, button );
		setOpen( button, true );
	};

	[
		'pointerover',
		'pointerout',
		'focusin',
		'focusout',
		'click',
		'keydown',
	].forEach( ( type ) => document.addEventListener( type, handle ) );
}
