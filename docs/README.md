# Documentation

Everything the plugin does, one topic per page.

## Getting started

- [Installation](installation.md) — putting the plugin on a site.
- [Configuring the API key](api-key.md) — saving a key, or defining it as a
  constant in `wp-config.php` so it never touches the database.

## Using it

- [The campaigns block](campaigns-block.md) — listing your most recently sent
  campaigns in any post or page.
- [Signup forms](signup-forms.md) — building a form bound to a Laposta list and
  placing it with a block or a shortcode.

## Running it

- [Cache duration and manual sync](cache-and-sync.md) — how long campaign data
  is held, and how to refresh it now.
- [Activity log](activity-log.md) — what the plugin records, how much of it, and
  how to filter and export it.
- [Critical email alerts](email-alerts.md) — getting told when the plugin logs
  an error, without going to look.
- [Configuration from the environment](configuration.md) — supplying any setting
  through an environment variable or a `wp-config.php` constant.
- [Multisite](multisite.md) — what is per-site, and what uninstalling removes.

## Extending it

- [Hooks and filters](hooks.md) — the actions and filters other code can use to
  customize or react to what the plugin does.
- [Roadmap](roadmap.md) — what's built, what's planned, and the open questions
  behind each extensibility feature.

## For contributors

- [CONTRIBUTING.md](../CONTRIBUTING.md) — workflow, architecture, quality checks.
- [SECURITY.md](../SECURITY.md) — the OWASP threat model the automated checks
  enforce, and how to report a vulnerability.
- [Testing signup forms and caching](testing/signup-form-caching.md) — the
  automated Playwright suite that exercises nonce, validation, and
  field-drift scenarios against a real Laposta test account.

---

Back to the [README](../README.md)
