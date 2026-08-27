# Wynko

Connect WordPress to your [Laposta](https://www.laposta.nl/) account. The plugin
owns the connection — API key, caching, manual sync, and an activity log — adds
a block for listing your most recently sent campaigns, and builds signup forms
that write new members straight into a Laposta list.

## Features

- **Campaigns block.** Lists your most recently sent campaigns as a plain `<ul>`
  of links, styled entirely by your theme. Filter by list, choose how many, how
  they are ordered, and what each item reads.
  → [docs](docs/campaigns-block.md)
- **Signup forms.** Build a form bound to a Laposta list and place it with a
  block or the `[wynko_form]` shortcode. The field editor is driven by Laposta's
  own field definitions, so it only offers what the list actually accepts.
  → [docs](docs/signup-forms.md)
- **Server-side validation and rate limiting.** Every submission is checked
  against the list's live field definitions whatever the browser was told, and
  metered per visitor and per form. → [docs](docs/signup-forms.md)
- **Activity log.** Key checks, connection checks, syncs, and signups, at the
  level of detail you choose — filterable, and exportable as a `.txt` that never
  contains your API key. → [docs](docs/activity-log.md)
- **Critical email alerts.** Mails you when the plugin records an error, at most
  once an hour. Off until you switch it on. → [docs](docs/email-alerts.md)
- **System report.** WordPress, PHP, database, PHP modules, and server, each
  marked against what the plugin is tested with, downloadable for a support
  request. It warns and never blocks.
- **Safe key handling.** The key is verified against Laposta before it is
  stored, never echoed back into the page, and can live in `wp-config.php`
  instead of the database. → [docs](docs/api-key.md)
- **Deployable configuration.** Every setting, not just the key, can come from
  an environment variable or a constant. → [docs](docs/configuration.md)
- **Multisite.** Settings are per-site, so each site connects to its own Laposta
  account. → [docs](docs/multisite.md)

## Requirements

- WordPress 6.4 or newer
- PHP 8.0 or newer
- A [Laposta](https://www.laposta.nl/) account and API key

## Installation

Download the ZIP from the [releases
page](https://github.com/FlexBorder/wynko/releases) and install
it through **Plugins → Add New → Upload Plugin**, then activate it and go to
**Wynko → Settings** to add your API key.

Installing from a git checkout needs a build step — see
[docs/installation.md](docs/installation.md).

## Documentation

Full documentation lives in [`docs/`](docs/README.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, the architecture, and
the quality checks that gate every commit. Security issues have their own
process: [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

If you build this into something commercial, a link back to
[flex-border.com](https://flex-border.com) is appreciated. That is a request,
not a licence term: the GPL asks only that you keep the copyright notices
intact.

Laposta is a trademark of its respective owner. This plugin is developed
independently by FlexBorder Co., Ltd with Laposta's permission and is not an
official Laposta product.
