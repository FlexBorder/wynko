# Signup forms

**Wynko → Signup forms** builds forms bound to a Laposta list. A form is placed
with the `wynko/form` block or the `[wynko_form id="N"]` shortcode — both render
identical markup from the same source.

A site can hold as many signup forms as it needs. **Wynko → Signup forms**
lists every form, with an "Add form" button to create another.

## The field editor

The field editor shows what each field actually accepts — its type, a choice
field's options, and which fields Laposta requires — because those come from
Laposta and cannot be changed here.

Each row shows its label; the rest folds behind the gear, so the table stays one
line per field. In there: whether the field shows, its help text (a `?` beside
the field), a default value, a CSS class, a placeholder, and the constraints its
type allows — min/max/step and an optional slider for a number, a date range, a
minimum and maximum length, a pattern with a description, and an autofill hint.

Which of those a field offers comes from the HTML standard's per-type table, so
a control that would save and then do nothing is never shown: a date takes no
placeholder, and a range slider takes neither a placeholder nor a required flag.
A pattern is compile-tested when you save, and a save carrying one that will not
compile is refused whole rather than stored to fail later.

Ordering is per field — drag it, or use the arrow buttons.

## Labels, placeholders, and the button

Whether names show as a label, a placeholder, or both is one setting for the
whole form, above the field table. A new form starts on label and placeholder,
with the caveat that dates and choice fields keep a visible label whatever it is
set to, because their inputs take no placeholder. Set it to labels only and the
placeholder boxes go read-only rather than disappearing, so what you typed
survives the round trip.

The email address is a field row like any other, except that it is always
required. The sign up button is a row too — the last one, and it stays there —
carrying its wording and a CSS class of your own; leave the wording empty and it
reads "Subscribe" in the visitor's language.

Messages shown on success, failure, and duplicate-subscriber are configurable,
as is where a successful signup lands: nowhere, a page you pick, or a URL you
type.

## What a visitor sees

Submitting happens in place, without reloading the page; without JavaScript it
falls back to a redirect.

A field that fails validation is marked `aria-invalid` and points at a message
rendered between its label and its control, in the flow rather than over the
field below it, following the [W3C design
system](https://design-system.w3.org/styles/form-errors.html). The message
announces itself, so an error arriving without a page load is still read out.

The plugin ships a structural stylesheet only — what the error and the help
tooltip cannot work without — and leaves colour, type, and spacing to the theme,
reachable through CSS custom properties.

## Validation and rate limiting

Every submission is validated server-side against the list's live field
definitions, whatever the browser was told — nothing reaches Laposta until every
field has passed. A field refused by its pattern is told in the words you wrote
as its pattern description, rather than a generic "please check this value".

Submissions are metered per visitor and per form; the caps live under
**Wynko → Settings → Security**, alongside the window they apply over and a
button that clears every counter now. What is configured there is repeated in
the About tab's system report.

There is no offline queue, no per-submission store, and no captcha yet — a
submission that Laposta refuses is reported to the visitor and logged, not
retried.

---

Back to the [README](../README.md) · [All documentation](README.md)
