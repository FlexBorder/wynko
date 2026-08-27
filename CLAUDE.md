# CLAUDE.md

Guidance for anyone (human or AI) working on this plugin. Read
[`CONTRIBUTING.md`](CONTRIBUTING.md) for the full workflow,
[`SECURITY.md`](SECURITY.md) for the security spec, and
[`HANDBOOK_COMPLIANCE.md`](HANDBOOK_COMPLIANCE.md) for how Wynko follows the
rest of the WordPress Plugin Developer Handbook. User-facing behaviour is
documented per topic in [`docs/`](docs/README.md) — that is where a description
of what a screen does belongs, not in `README.md`, which is the project's
landing page and stays short.

## What this is

A WordPress plugin that shows recently sent Laposta campaigns in a Gutenberg
block, with a settings page (API key, cache, sync, activity log). Roadmap:
signup forms that write to Laposta, form-field auto-import, and webhooks — so
the architecture leaves seams for a write-capable API client and a REST/webhook
controller (no third-party plugin integrations).

Before naming or guideline-sensitive decisions (plugin/slug/text-domain
naming, trademark use, licensing, trialware/upsell patterns, external
service disclosure), load the project-scoped skills at
`.claude/skills/wp-plugin-directory-guidelines` and `.claude/skills/wp-phpstan`
(from [`WordPress/agent-skills`](https://github.com/WordPress/agent-skills),
excluded from the release ZIP via `.distignore`) — an early submission was
rejected on naming grounds because this checklist wasn't in anyone's context,
human or AI, when the original name was chosen.

## Toolchain — Docker only

There is **no local PHP or Composer**. Run everything through Docker:

```bash
# Composer (always pass -u to avoid root-owned vendor/ files)
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 <cmd>
# PHP tools (phpunit, phpcs, phpstan)
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli <cmd>
# Live WordPress (block/admin verification, incl. multisite)
npx @wordpress/env start
```

Composer scripts: `composer test | lint | lint:fix | lint:security | analyse`,
plus `sbom | sbom:check | audit:deps` (dependency inventory; see `sbom/README.md`).
JS lint runs on Node, not Docker: `npm run lint:js` (`lint:js:fix` to correct).
Translations regenerate with `npm run i18n` (WP-CLI inside `wp-env`; start it
first). It rewrites the `.pot`, merges into `languages/*.po` without discarding
translations, and compiles the `.mo` and editor-script JSON. Filling in new
`msgstr` values is a hand edit between merge and compile.
`bin/package.sh [ref]` builds the installable ZIP (default `main`) into
`dist/`; it needs Docker and Node, so run it on the host.

## Architecture

- `includes/` — PSR-4 (`Wynko\`), autoloaded.
  - `Api/` — Laposta transport (`Client`) + resources (`Campaigns`; add
    `Subscribers`/`Lists`/`Fields` here — they reuse `Client::request`).
  - `Support/` — **pure, WordPress-free** logic; unit-tested. Never add
    `get_option`/`__()`/WP calls here — `SupportIsWordPressFreeTest` fails the
    build if you do. User-facing prose is a WordPress concern: `Support/`
    classifies (`Sanitizer::classify_status()` → a `STATUS_*` constant) and the
    caller words it (`Api\Client::status_message()`).
  - `Admin/`, `Blocks/` — WordPress-facing surfaces.
  - `ApiKey.php` — resolves the key (`WYNKO_API_KEY_{blog_id}` → `WYNKO_API_KEY`
    → option). It calls `get_current_blog_id()`/`Config::get()` (which wraps
    `get_option()`), so it lives here rather than in `Support/`. Consumed by
    `Api\Client`, `Admin\SettingsPage`, and `KeyStatus`.
  - `KeyStatus.php` — caches the Connected/Not connected verdict, fingerprinted
    by SHA-256 of the key so rotation invalidates it.
  - `Throttle.php` — meters the public signup endpoint per IP and per form.
    `Support\RateLimiter` does the sliding-window arithmetic; this holds the
    transients, keys the IP through `wp_hash()`, and clears every counter by
    bumping an epoch the keys carry. Window and caps are settings.
  - `Notifier.php` — mails an alert when `Log::add()` records an error, throttled
    to one per site per hour; recipients are the addresses stored by the
    settings screen's Notifications tab.
  - `Migrations.php` — per-site stored-data upgrades, gated on the
    `wynko_schema` option.
  - `Plugin.php` — hook registration (admin hooks gated behind `is_admin()`).
- `Config.php` + `config/settings.php` — the only place option keys, defaults,
  and bounds live. Don't hard-code them elsewhere.
- `Urls.php` + `config/urls.php` — the only place external URLs and their link
  targets live (`rel` is derived from the target, not registered).
  `NoHardcodedUrlsTest` fails the build on a URL literal in `includes/`.
- `src/block/` (JS source) → `build/block/` (compiled; `npm run build`).
- `uninstall.php` — multisite-aware; deletes options incl. the API key.
- `readme.txt` — the WordPress.org plugin page. Its `Stable tag` must match
  `WYNKO_VERSION`; `CONTRIBUTING.md`'s Releasing section lists all three places
  the version lives.

## Hard rules

- **Security and coding standards are gates, every commit.** The pre-commit
  hook (`bin/write-report.sh`) runs ten checks — security scan, PHP/CSS/JS
  coding standards, unit tests, static analysis, Semgrep, SBOM freshness,
  WP.org readiness, and Plugin Check — don't `--no-verify`. New
  endpoints/handlers/forms must satisfy their OWASP row in `SECURITY.md`
  (capability check + nonce + sanitize + escape; webhooks verify a
  signature); a change touching Hooks, Admin Menus, Settings, Metadata,
  Custom Post Types, Taxonomies, Users, the HTTP API, JS/Ajax, Cron, i18n,
  or Privacy should keep the matching row in `HANDBOOK_COMPLIANCE.md`
  accurate. Suppress a finding only with
  `// phpcs:ignore <sniff> -- <reason>` / `// nosemgrep: <rule> -- <reason>`.
- **WordPress Coding Standards.** PHP follows
  [WPCS](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
  (`WordPress` + `PHPCompatibilityWP`, enforced over `includes/`, `config/`, and
  `tests/`); JS follows the WordPress JS standard via `wp-scripts lint-js`
  (`plugin:@wordpress/recommended`); CSS/SCSS follows it via `wp-scripts
  lint-style` (`@wordpress/stylelint-config`). HTML has no automated
  equivalent — see `TECHNICAL_DEBT.md` TD-061.
  Every file, class, and function carries a docblock (summary + `@param`/
  `@return`). Deliberate departures live in `phpcs.xml.dist` with a reason.
- **Write down every deferral.** Any decision to defer, cap, or work around
  something — a check that runs in one place but not another, a tool pinned
  below its current major, a guard postponed to "later" — gets an entry in
  `TECHNICAL_DEBT.md` **in the same commit as the decision**, with a concrete
  `Revisit when` trigger. Taking the shortcut is fine; leaving it unwritten is
  not. Copy the template at the top of that file and take the next ID.
  That register is **maintainer-side and not published** — it is excluded from
  the public repository, so nothing public may link to it or cite a TD number.
  An outside contributor puts the same note in their pull request instead.
- **Runtime dependencies are inventoried.** `sbom/*.cdx.json` (CycloneDX 1.6)
  lists what the plugin *uses* — the packages that ship in the archive, dev
  tooling excluded. Both are currently empty of components (no third-party
  runtime dependencies), which is the point: adding the first one must show up
  there. Generated by `composer sbom` and regenerated whenever a runtime
  dependency changes; the pre-commit hook (`bin/sbom-check.sh`) blocks the
  mismatch. Never hand-edit them, and never add a prose copy of the package list
  that could drift. Before a release, run `composer audit:deps`.
- **Self-documenting code.** Minimize comments beyond those docblocks — clear
  names and small functions over prose. Keep only non-obvious *why* notes,
  type-shape annotations, and required suppression justifications.
- **Git flow.** Trunk is `main` and stays releasable. Branch → commit (every
  commit runs the full pre-commit suite) → `/security-review` →
  `bin/merge-to-main.sh` (a full local mirror of CI, PHP 8.0–8.5 matrix
  included) → push. Never commit straight to `main`; never merge a red
  branch.
- **Multisite.** Options/transients are per-site; keep it that way and keep
  `uninstall.php`'s per-site loop intact.
- **Compatibility.** PHP 8.0+, WordPress 6.4+.
