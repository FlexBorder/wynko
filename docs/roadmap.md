# Roadmap

What's built, what's planned, and the open questions behind each
extensibility feature. This is a working document, not a promise of dates.

## Hooks and filters — done

The plugin now defines its own `wynko_*` actions and filters throughout the
API transport, campaigns, signup forms, and activity log — see
[Hooks and filters](hooks.md) for the full reference. Previously it consumed
only WordPress core hooks and offered none of its own.

## Integrations framework — bundled integration shipped, companion plugin open

Delivered: a filter-based registry (`wynko_register_integrations`, see
[Hooks and filters](hooks.md#integrations)), the `Wynko\Integrations\Integration`
contract any plugin or theme implements, an enable/disable admin screen (Wynko
→ Integrations), and two real bundled integrations. The first, a Contact
Form 7 bridge (`Wynko\Integrations\ContactForm7\ContactForm7Integration`),
reads a native Contact Form 7 `[checkbox wynko-optin-{list_id} …]` tag,
pasted by the admin into a form's own template, and subscribes accepted
submissions with it checked to whichever Laposta list the tag names. The
bridge renders nothing itself and registers no custom form-tag type — its
settings screen is a builder: for every list the site knows about, it shows
the exact tag to copy (built from the configured checkbox label), so
different CF7 forms can subscribe to different lists just by pasting a
different list's tag, with nothing to select in Wynko's admin beyond that
shared label. Field mapping for a list's other custom fields follows the
same idea, the convention [MC4WP's own Contact Form 7
integration](https://www.mc4wp.com/kb/subscribe-mailchimp-contact-form-7/)
uses: a required field is supplied by a CF7 field literally named
`wynko-{custom_name}`, and the settings screen shows the exact native CF7
tag to paste for it, per list — `text`/`number`/`date` for a plain field,
or CF7's own `checkbox` tag (with `exclusive` for a single-choice field)
carrying that list's own option values.

The second, an [HTML Forms](https://wordpress.org/plugins/html-forms/)
bridge (`Wynko\Integrations\HtmlForms\HtmlFormsIntegration`), follows the
same `wynko-{custom_name}` / `wynko-optin-{list_id}` convention, but HTML
Forms has no typed tag system of its own — an admin pastes raw HTML into
the form's own markup textarea — so the settings screen's builder prints
plain HTML instead: an `<input>`/`<select>`/checkbox group per field, and a
fixed `wynko-email` field name for the submitter's address, since this
bridge has no way to ask HTML Forms which field is email-typed the way CF7
can be asked to scan its own tags. Design record:
[Integrations framework design](superpowers/specs/2026-08-29-integrations-framework-design.md).

Discovery ended up being a filter rather than a directory scan: a directory
scan can only reach files inside Wynko's own plugin folder, and a
third-party integration's code lives in an entirely different plugin or
theme, so there was never a shared directory to scan in the first place.

Three kinds of integration this framework is meant to support:

- **Bundled integrations** that ship with the plugin but stay off until
  switched on, so a site that doesn't need them pays nothing for them. The
  Contact Form 7 and HTML Forms bridges above are the first two.
- **A premium companion plugin** that installs separately and behaves as an
  integration itself — hooking into the same extension points documented in
  [Hooks and filters](hooks.md) rather than requiring its own copy of the
  submit pipeline, key resolution, or API client. **Not started.** Open
  question: what it needs from Wynko's public API surface beyond the
  `Integration` interface and the hooks already documented.
- **Bridges to third-party plugins**, of which Contact Form 7 and HTML
  Forms are the first two. Any other bridge (a different form plugin, for
  example) follows the same pattern: implement `Integration`, register
  through the filter, write to Laposta via `Api\Subscribers::create()` and
  its `wynko_subscriber_data` filter rather than duplicating the Laposta
  call.

**On CLAUDE.md's "no third-party plugin integrations" line:** that line
describes Wynko's *own* architecture — the plugin's core does not require any
third-party plugin to function, and the roadmap it sits under (signup forms,
field auto-import, webhooks) is scoped to Wynko talking to Laposta, not to
other plugins. It does not rule out Wynko offering *optional* bridges to
other plugins, of the CF7 kind above — those are additive integrations a site
chooses to enable, not a dependency Wynko's core takes on. This distinction
is worth keeping explicit, since the original wording reads ambiguously
enough that it was initially misread as a blanket ban during planning for
this feature.

## MCP support — not started

The plan: expose the plugin's data and actions (campaigns, signup forms,
activity log) through WordPress's MCP framework, so a user's WordPress
installation and this plugin become reachable from an MCP-capable client.

**Open constraint:** the plugin currently ships with **zero runtime Composer
dependencies** — `sbom/*.cdx.json` is deliberately empty of components, and
`bin/sbom-check.sh` enforces that the SBOM matches what actually ships. Any
MCP SDK added via Composer would be the plugin's first runtime dependency,
which needs to be either justified as a deliberate first exception (with a
corresponding SBOM entry and a decision record) or avoided by implementing
against WordPress's MCP framework directly, without a third-party SDK. This
needs its own design pass before any implementation work starts — see
`includes/Rest/` for the precedent of how this plugin already exposes a
REST/webhook layer, which an MCP surface would likely sit alongside.

---

Back to the [README](../README.md) · [All documentation](README.md)
