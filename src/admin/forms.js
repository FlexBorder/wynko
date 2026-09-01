/**
 * Wynko signup form editor behaviour.
 */
import './forms.scss';
import { delegateHelp } from '../shared/help-toggle';

const { i18n = {} } = window.wynkoAdmin || {};

delegateHelp( '.wynko-hint__toggle' );

/**
 * Whether the editor holds edits that have not been saved.
 *
 * A save writes only the submitted tab's meta, so leaving a tab with edits on it
 * discards them. Switching tabs saves first, and this flag warns about every
 * other way of leaving the page.
 *
 * @type {boolean}
 */
let dirty = false;

/**
 * The list the picker named before the current change, so a cancelled swap can
 * put it back rather than leave it naming a list whose fields are not shown.
 *
 * @type {string}
 */
let previousList = '';

/**
 * Marks the editor as holding unsaved work.
 */
function markDirty() {
	dirty = true;
}

document.addEventListener( 'input', ( event ) => {
	if ( event.target.closest( '#wynko-form-edit' ) ) {
		markDirty();
	}
} );

document.addEventListener( 'change', ( event ) => {
	if ( event.target.closest( '#wynko-form-edit' ) ) {
		markDirty();
	}
} );

// Only the edit form clears the flag. The delete form is a second form on the
// page, and its submit is not a save of anything.
document.addEventListener( 'submit', ( event ) => {
	if ( event.target.id === 'wynko-form-edit' ) {
		dirty = false;
	}
} );

/**
 * Saves the tab being left, then goes on to the one that was clicked.
 *
 * The tabs are links and a save writes only the tab it was submitted from, so
 * the destination rides along as a hidden field and the server redirects there
 * once the save is done. requestSubmit() rather than submit(), because only the
 * former fires the submit event that clears the dirty flag.
 *
 * @param {MouseEvent} event The click event.
 */
function saveThenSwitchTab( event ) {
	const tab = event.target.closest( '.nav-tab-wrapper .nav-tab[data-tab]' );
	const form = document.getElementById( 'wynko-form-edit' );
	const destination = document.getElementById( 'wynko-goto-tab' );

	// A clean tab stays a plain link, and so does a click the browser is
	// already treating as "open this somewhere else".
	if (
		! tab ||
		! dirty ||
		! form ||
		! destination ||
		! form.requestSubmit ||
		event.defaultPrevented ||
		event.metaKey ||
		event.ctrlKey ||
		event.shiftKey ||
		event.button !== 0
	) {
		return;
	}

	event.preventDefault();
	destination.value = tab.dataset.tab;
	form.requestSubmit();
}

document.addEventListener( 'click', saveThenSwitchTab );

window.addEventListener( 'beforeunload', ( event ) => {
	if ( ! dirty ) {
		return undefined;
	}

	// The browser's own dialog, whose wording is not ours to choose. Both the
	// assignment and the return value are needed to satisfy every engine.
	event.preventDefault();
	event.returnValue = '';
	return '';
} );

/**
 * Asks before an action that replaces the field table with fresh markup.
 *
 * beforeunload never fires for these: they destroy edits without navigating.
 *
 * @return {boolean} Whether to go ahead.
 */
function confirmDiscard() {
	if ( ! dirty ) {
		return true;
	}

	// eslint-disable-next-line no-alert
	return window.confirm( i18n.discardWork );
}

/**
 * Copies a button's data-copy value and confirms it in the button's label.
 *
 * @param {HTMLButtonElement} button The clicked copy button.
 */
async function copyShortcode( button ) {
	const original = button.textContent;

	try {
		await window.navigator.clipboard.writeText( button.dataset.copy || '' );
	} catch ( error ) {
		// A denied clipboard permission is not worth interrupting anyone over:
		// the field beside the button selects on focus, which is the fallback.
		return;
	}

	button.textContent = i18n.copied || original;
	window.setTimeout( () => {
		button.textContent = original;
	}, 1500 );
}

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '.wynko-copy' );
	if ( button ) {
		copyShortcode( button );
	}
} );

/**
 * Rebuilds a third-party-plugin bridge's combined-snippet textarea and its
 * Copy button from whichever field checkboxes are currently checked (the
 * opt-in checkbox is one of them, always checked and disabled, last in the
 * list). Shared by every bridge's own settings screen (Contact Form 7, HTML
 * Forms, …), one at a time. Runs entirely from data already in the page —
 * every snippet's text sits in a data-tag attribute, in the same order the
 * server rendered them — so no request is made.
 *
 * Queries the whole document rather than a shared wrapper: the checkboxes
 * (step 2) and the textarea (step 3) live in separate `<li>` steps on that
 * screen, not a common container, and only one such pair is ever on a page
 * at once.
 */
function syncBridgeCombinedTags() {
	const textarea = document.querySelector( '.wynko-bridge-combined' );
	const box = textarea && textarea.closest( '.wynko-shortcode-box' );
	const button = box && box.querySelector( '.wynko-copy' );
	if ( ! textarea || ! button ) {
		return;
	}

	const tags = [];
	document
		.querySelectorAll( '.wynko-bridge-field:checked' )
		.forEach( ( checkbox ) => tags.push( checkbox.dataset.tag || '' ) );

	const combined = tags.join( '\n' );
	textarea.value = combined;
	button.dataset.copy = combined;
}

document.addEventListener( 'change', ( event ) => {
	if ( event.target.closest( '.wynko-bridge-field' ) ) {
		syncBridgeCombinedTags();
	}
} );

/**
 * The Integrations screen's "select all" checkbox: checks or unchecks every
 * row so a bulk action can be applied to all of them in one go, the same
 * behaviour the Plugins screen's own header checkbox has.
 */
document.addEventListener( 'change', ( event ) => {
	if ( event.target.id !== 'wynko-integrations-select-all' ) {
		return;
	}

	document
		.querySelectorAll( '.wynko-integration-row-checkbox' )
		.forEach( ( checkbox ) => {
			checkbox.checked = event.target.checked;
		} );
} );

/**
 * Confirms before applying a bulk "Deactivate" on the Integrations screen —
 * the checked rows can span several different integrations, so this stays
 * generic rather than naming any one of their consequences (the per-row
 * Deactivate link's own confirm() does that instead). Bulk "Activate" and an
 * unselected action are left alone.
 */
document.addEventListener( 'submit', ( event ) => {
	const bulkAction = event.target.querySelector( '#wynko-bulk-action' );
	if ( ! bulkAction || 'deactivate' !== bulkAction.value ) {
		return;
	}

	// eslint-disable-next-line no-alert
	if ( ! window.confirm( i18n.bulkDeactivate ) ) {
		event.preventDefault();
	}
} );

/**
 * Opens or closes one row's rename box on the forms list.
 *
 * The box is server-rendered inside the row and hidden, so only the showing is
 * done here. A browser with no JavaScript never sees the control at all, which
 * is why "Quick edit" carries hide-if-no-js.
 *
 * @param {HTMLElement} row  The row being renamed.
 * @param {boolean}     open Whether to show the box.
 */
function toggleRename( row, open ) {
	const box = row.querySelector( '.wynko-rename' );
	if ( ! box ) {
		return;
	}

	const name = row.querySelector( 'strong' );
	box.classList.toggle( 'wynko-hidden', ! open );
	if ( name ) {
		name.classList.toggle( 'wynko-hidden', open );
	}
	if ( open ) {
		box.querySelector( 'input' )?.focus();
	}
}

document.addEventListener( 'click', ( event ) => {
	const open = event.target.closest( '.wynko-rename-open' );
	if ( open ) {
		toggleRename( open.closest( 'tr' ), true );
		return;
	}

	const cancel = event.target.closest( '.wynko-rename-cancel' );
	if ( cancel ) {
		toggleRename( cancel.closest( 'tr' ), false );
	}
} );

/**
 * Rewrites every row's hidden order input to its position in the table, which is
 * what clean_fields() sorts by server-side. Also disables the two arrows that
 * would move a row past an end.
 *
 * @param {HTMLElement} body The field table's tbody.
 */
/**
 * Puts every options panel back immediately after the row it belongs to.
 *
 * A field occupies two rows but only the first is draggable, so any reorder
 * leaves the panels where they were. Both reorder paths move the row alone and
 * leave the panels to this.
 *
 * @param {HTMLElement} body The field table's tbody.
 */
function reattachPanels( body ) {
	body.querySelectorAll( '.wynko-row' ).forEach( ( row ) => {
		const button = row.querySelector( '.wynko-panel-toggle' );
		if ( ! button ) {
			return;
		}

		const panel = document.getElementById(
			button.getAttribute( 'aria-controls' )
		);
		if ( panel && row.nextElementSibling !== panel ) {
			body.insertBefore( panel, row.nextElementSibling );
		}
	} );
}

function renumber( body ) {
	reattachPanels( body );

	const rows = body.querySelectorAll( '.wynko-row' );

	rows.forEach( ( row, index ) => {
		const order = row.querySelector( '.wynko-order' );
		if ( order ) {
			order.value = index;
		}

		row.querySelectorAll( '.wynko-move' ).forEach( ( button ) => {
			button.disabled =
				button.dataset.direction === 'up'
					? index === 0
					: index === rows.length - 1;
		} );
	} );
}

/**
 * Makes the field table drag-sortable. jQuery UI's sortable ships with
 * WordPress core, so this adds no dependency and nothing to the SBOM.
 *
 * @param {HTMLElement} body The field table's tbody.
 */
function makeSortable( body ) {
	const { jQuery } = window;
	if ( ! jQuery || ! jQuery.fn.sortable ) {
		return;
	}

	jQuery( body ).sortable( {
		items: '> .wynko-row',
		handle: '.wynko-handle',
		axis: 'y',
		cursor: 'move',
		placeholder: 'wynko-row-placeholder',
		helper: ( event, row ) => {
			// Without explicit widths the dragged row collapses out of the table.
			const widths = row.children().map( function measure() {
				return jQuery( this ).width();
			} );
			row.children().each( function apply( index ) {
				jQuery( this ).width( widths[ index ] );
			} );
			return row;
		},
		// renumber() rewrites the hidden order inputs by assigning .value, which
		// fires neither input nor change — so a reorder has to say so itself or
		// the delegated listeners would call a rearranged form clean.
		update: () => {
			renumber( body );
			markDirty();
		},
	} );
}

/**
 * Wires drag and keyboard reordering on the field table. Called again after
 * the rows are replaced, so a freshly loaded list is sortable too.
 */
export function initFieldTable() {
	const body = document.querySelector( '.wynko-fields__body' );
	if ( ! body ) {
		return;
	}
	makeSortable( body );
	renumber( body );
	// The rows the server just sent were built from the *stored* label mode, so
	// a picker changed but not yet saved has to be applied over them again.
	const mode = document.querySelector( '.wynko-label-mode' );
	if ( mode ) {
		syncPlaceholders( mode.value );
		syncLabels( mode.value );
	}
}

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '.wynko-move' );
	if ( ! button ) {
		return;
	}

	const row = button.closest( '.wynko-row' );
	const body = row.parentElement;
	const up = button.dataset.direction === 'up';

	// Step over the panel rows: the neighbour that matters is the next field,
	// not the folded options belonging to this one.
	let sibling = up ? row.previousElementSibling : row.nextElementSibling;
	while ( sibling && ! sibling.classList.contains( 'wynko-row' ) ) {
		sibling = up
			? sibling.previousElementSibling
			: sibling.nextElementSibling;
	}

	if ( ! sibling ) {
		return;
	}

	if ( up ) {
		body.insertBefore( row, sibling );
	} else {
		body.insertBefore( sibling, row );
	}

	renumber( body );
	markDirty();
	// Keep focus on the button that moved, or a keyboard user loses their
	// place after every press.
	button.focus();
} );

/**
 * Replaces the field table's rows with the chosen list's, so an admin sees a
 * list's fields without saving first. The markup comes from the server —
 * FieldRows is the only place the editor's table is written, and rebuilding it
 * here would mean two field tables to keep in step.
 *
 * @param {HTMLSelectElement} select  The list picker.
 * @param {boolean}           refresh Ask Laposta again rather than using the cache.
 */
async function loadFields( select, refresh = false ) {
	const slot = document.querySelector( '.wynko-fields__slot' );

	if ( ! slot ) {
		// The one state the server draws no container in: a bound list whose
		// fields could not be read. It says so on the page already.
		return;
	}

	if ( ! select.value ) {
		// Back to "— Choose a list —": there is nothing to arrange, so the
		// table goes rather than lingering as a layout for no list.
		slot.innerHTML = '';
		return;
	}

	slot.setAttribute( 'aria-busy', 'true' );

	const url = new URL(
		`${ window.wynkoAdmin.restRoot }forms/${ select.dataset.form }/fields`
	);
	url.searchParams.set( 'list_id', select.value );
	if ( refresh ) {
		url.searchParams.set( 'refresh', '1' );
	}

	try {
		const response = await window.fetch( url, {
			headers: { 'X-WP-Nonce': window.wynkoAdmin.nonce },
			credentials: 'same-origin',
		} );
		const payload = await response.json();

		if ( ! response.ok || payload.error || ! payload.html ) {
			throw new Error( 'wynko: could not load fields' );
		}

		slot.innerHTML = payload.html;
		initFieldTable();
	} catch ( error ) {
		// eslint-disable-next-line no-alert
		window.alert( i18n.loadFail );
	} finally {
		slot.removeAttribute( 'aria-busy' );
	}
}

/**
 * Opens or closes one field's options panel.
 *
 * Delegated on the document rather than bound per row, so the panels keep
 * working after the REST route swaps the table body for a newly bound list.
 *
 * @param {MouseEvent} event The click event.
 */
function togglePanel( event ) {
	const button = event.target.closest( '.wynko-panel-toggle' );
	if ( ! button ) {
		return;
	}

	const panel = document.getElementById(
		button.getAttribute( 'aria-controls' )
	);
	if ( ! panel ) {
		return;
	}

	const open = 'true' === button.getAttribute( 'aria-expanded' );
	button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
	panel.hidden = open;
}

/**
 * Shows or hides the settings nested under the terms checkbox.
 *
 * The server renders them already hidden when it is off, so this only follows
 * the checkbox from there — nothing flashes on load, and a screen without
 * JavaScript still sees whichever state was saved.
 *
 * @param {HTMLInputElement} checkbox The terms-required checkbox.
 */
function toggleTermsDetail( checkbox ) {
	const detail = document.getElementById( 'wynko-terms-detail' );
	if ( detail ) {
		detail.hidden = ! checkbox.checked;
	}
}

document.addEventListener( 'click', togglePanel );

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '.wynko-refresh-fields' );
	if ( ! button ) {
		return;
	}

	const select = document.querySelector( '.wynko-list-select' );
	if ( select && select.value && confirmDiscard() ) {
		loadFields( select, true );
	}
} );

document.addEventListener( 'change', ( event ) => {
	const select = event.target.closest( '.wynko-list-select' );
	if ( select ) {
		// Only a swap between two real lists can lose anything. Coming from
		// "Select a list" there are no rows on screen yet, and choosing the
		// first one marked the editor dirty a moment ago — asking there is the
		// question answering itself.
		if ( previousList !== '' && ! confirmDiscard() ) {
			// Put the picker back where it was, or it would name a list whose
			// fields are not the ones still on screen.
			select.value = previousList;
			return;
		}

		previousList = select.value;

		const refresh = document.querySelector( '.wynko-refresh-fields' );
		if ( refresh ) {
			refresh.disabled = ! select.value;
		}
		loadFields( select );
	}

	const terms = event.target.closest( '#wynko-terms-required' );
	if ( terms ) {
		toggleTermsDetail( terms );
	}

	const mode = event.target.closest( '.wynko-label-mode' );
	if ( mode ) {
		syncPlaceholders( mode.value );
		syncLabels( mode.value );
	}

	const notify = event.target.closest( '#wynko-notify-enabled' );
	if ( notify ) {
		syncNotifyEmails( notify );
	}

	const throttle = event.target.closest( '#wynko-throttle-enabled' );
	if ( throttle ) {
		syncThrottleFields( throttle );
	}
} );

/**
 * Enables or disables the placeholder column for a label mode.
 *
 * Read-only rather than disabled: a disabled input posts nothing, and the save
 * would then read every placeholder as emptied. The server renders the same
 * state on load, so this only follows the picker from there.
 *
 * @param {string} mode The chosen label mode.
 */
function syncPlaceholders( mode ) {
	document.querySelectorAll( '.wynko-placeholder' ).forEach( ( input ) => {
		input.readOnly = mode === 'label';
		input.title = input.readOnly ? i18n.placeholderOff || '' : '';
	} );
}

/**
 * Enables or disables the label column for a label mode.
 *
 * The mirror of syncPlaceholders(), and read-only for the same reason: a
 * disabled input posts nothing, so the save would blank every label. A type that
 * takes no placeholder keeps an editable label, and the row says which it is.
 *
 * @param {string} mode The chosen label mode.
 */
function syncLabels( mode ) {
	document.querySelectorAll( '.wynko-label' ).forEach( ( input ) => {
		const row = input.closest( 'tr' );
		input.readOnly =
			mode === 'placeholder' && row?.dataset.wynkoPlaceholderable === '1';
		input.title = input.readOnly ? i18n.labelOff || '' : '';
	} );
}

/**
 * Shows the alert recipients only while alerts are switched on.
 *
 * Hidden rather than disabled: options.php writes every option registered to
 * the submitted group, so a field that does not post would blank the stored
 * addresses on the next save. The server renders the same state on load.
 *
 * @param {HTMLInputElement} box The enable checkbox.
 */
function syncNotifyEmails( box ) {
	const emails = document.querySelector( '.wynko-notify-emails' );
	if ( emails ) {
		emails.classList.toggle( 'wynko-hidden', ! box.checked );
	}
}

/**
 * Hides the window/per-visitor/per-form caps and their counts table while
 * rate limiting is off — a cap that no longer applies is not worth leaving
 * on screen. Same pattern as syncNotifyEmails(): one nested block, one class
 * to toggle.
 *
 * @param {HTMLInputElement} box The rate-limiting on/off checkbox.
 */
function syncThrottleFields( box ) {
	const fields = document.querySelector( '.wynko-throttle-fields' );
	if ( fields ) {
		fields.classList.toggle( 'wynko-hidden', ! box.checked );
	}
}

/**
 * Copies a number field's bounds onto its own default-value box, so a default
 * typed against the old maximum stops being accepted the moment the maximum
 * changes. FormEditPage refuses the same value on save, where it counts.
 *
 * @param {HTMLInputElement} input The min, max, or step control that changed.
 */
function syncDefaultBounds( input ) {
	const panel = input.closest( '.wynko-panel' );
	const value = panel?.querySelector(
		'input[type="number"][name$="[value]"]'
	);
	if ( ! value ) {
		return;
	}

	const bound = input.name.replace( /^.*\[attrs\]\[(\w+)\]$/, '$1' );
	if ( '' === input.value ) {
		value.removeAttribute( bound );
		return;
	}
	value.setAttribute( bound, input.value );
}

document.addEventListener( 'input', ( event ) => {
	const bound = event.target.closest?.(
		'.wynko-panel input[name$="[attrs][min]"], .wynko-panel input[name$="[attrs][max]"], .wynko-panel input[name$="[attrs][step]"]'
	);
	if ( bound ) {
		syncDefaultBounds( bound );
	}
} );

// A control inside a folded panel cannot be pointed at: the browser refuses to
// submit and, having nothing to focus, says so only in the console. Opening the
// panel while the browser is still deciding is what lets it report the value
// itself, in the row the admin has to fix.
document.addEventListener(
	'invalid',
	( event ) => {
		const panel = event.target.closest?.( '.wynko-row__panel' );
		const toggle =
			panel &&
			document.querySelector(
				`.wynko-panel-toggle[aria-controls="${ panel.id }"]`
			);
		if ( toggle && panel.hidden ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
			panel.hidden = false;
		}
	},
	true
);

document.addEventListener( 'DOMContentLoaded', () => {
	initFieldTable();

	const notify = document.querySelector( '#wynko-notify-enabled' );
	if ( notify ) {
		syncNotifyEmails( notify );
	}

	const throttle = document.querySelector( '#wynko-throttle-enabled' );
	if ( throttle ) {
		syncThrottleFields( throttle );
	}

	const select = document.querySelector( '.wynko-list-select' );
	if ( select ) {
		previousList = select.value;
	}
} );
