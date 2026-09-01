# Contributing

Thanks for helping improve Wynko. This project keeps a small
footprint and treats **security as part of every change**, not an afterthought.
Read `SECURITY.md` — it is the spec the checks below enforce.

## Development setup

There is no required local PHP toolchain; everything runs through Composer, and
the commands below work either with local PHP or via Docker (`php:8.5-cli`,
matching `wp-env`'s `phpVersion` — see `.wp-env.json`).

```bash
composer install        # installs dev tools and activates the git hooks
npm install && npm run build   # builds the block assets into build/block
```

`composer install` sets `core.hooksPath` to `.githooks/`, which activates the
pre-commit security scan for you.

## Remotes & release identity

This repo pushes to two GitHub remotes — a private full mirror (`origin`) and
a public repo (`public`) that only ever receives one filtered, squashed
commit per release, via `bin/release.sh`. The account, SSH, and GPG setup
behind that publish step is maintainer-only and documented in
`RELEASE_IDENTITY.md`, which stays out of the public repository. If you're an
outside contributor, you won't need it — regular branches only ever push to
`origin`, and releases are cut by a maintainer.

## Branching & commit flow

Trunk is **`main`**; it stays releasable. Work happens on short-lived branches
that open a pull request against `main`, where CI (`.github/workflows/ci.yml`)
runs the tests, the static analysis, and the gating security job.

1. **Branch** off `main`: `git switch main && git switch -c feature/<short-name>`
   (prefixes: `feature/`, `fix/`, `chore/`).
2. **Commit** in small, focused steps. Each `git commit` triggers the pre-commit
   hook (`.githooks/pre-commit` → `bin/write-report.sh`), which runs ten
   checks: the **security scan** (`bin/security-scan.sh`), the
   **PHP coding-standards check** (`bin/coding-standards.sh`), the
   **CSS/SCSS coding-standards check** (`bin/style-lint.sh`), the
   **JS coding-standards check** (`bin/js-lint.sh`), **unit tests**
   (`bin/unit-tests.sh`), **static analysis** (`bin/static-analysis.sh`),
   a **Semgrep scan** (`bin/semgrep-scan.sh` — needs network access to fetch
   its rulesets), the **SBOM freshness check** (`bin/sbom-check.sh`), the
   **WordPress.org readiness guard** (`bin/wp-org-check.sh`), and the
   official **Plugin Check** tool, all categories, strict
   (`bin/plugin-check.sh` — needs `npx @wordpress/env start` already
   running; it fails the commit rather than skipping if wp-env isn't up).
   Any one finding blocks the commit — fix it (`composer lint:fix`/`npm run
   lint:js:fix`/`npm run lint:style:fix` auto-correct most style issues) or
   justify the suppression inline. Do not use `--no-verify`. Every run of
   the hook — pass or fail — writes a timestamped Markdown report (all ten
   checks' full output, including which sniffs ran and every file's
   individual result, not just a pass/fail line) to `../wynko-reports/`, a
   sibling of the repo checkout deliberately kept outside git; it's how you
   read back what a pre-commit run actually found, including after a
   failing commit. The **commit-msg hook** (`.githooks/commit-msg`) also
   requires a [Conventional Commits](https://www.conventionalcommits.org/)
   subject line (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`,
   `ci:`, `build:`, `perf:`, `revert:`, `security:`, optionally scoped and
   suffixed `!` for a breaking change) — `bin/release.sh` depends on this
   to compute version bumps and draft changelogs.
3. **Before merging**, on the branch, run `/security-review` (Claude Code)
   and resolve or justify every finding — the one check nothing else here
   automates.
4. **Merge** to `main` with `bin/merge-to-main.sh <branch>`: it runs a full
   local mirror of every CI job — the PHP 8.0–8.5 matrix (`bin/php-matrix.sh`:
   PHPUnit, PHPCS, PHPStan under each version, with a fresh `composer
   install` per version, exactly like CI's `build` job), the security scan
   and Semgrep, JS and CSS lint, `wp-org-check` and Plugin Check, and a full
   SBOM regeneration pinned to the same npm version CI uses — then asks you
   to confirm `/security-review` ran and its findings were resolved, before
   performing `git merge --no-ff` with a `Security-Reviewed:
   <branch-tip-sha>` trailer in the merge commit and deleting the branch.
   This runs entirely on your machine, before anything reaches GitHub.
   Only a merge commit carrying that trailer can reach `origin/main` — CI's
   `security-review-attestation` job hard-fails otherwise (that job is the
   one thing `merge-to-main.sh` can't mirror: it verifies, after the fact,
   that this very script was used, so it can't check itself). Don't merge
   with a bare `git merge`; it skips the attestation.
5. **Push** the branch and open a PR against `main`, or push `main` directly
   after a local merge. Either way, **that push** is what triggers CI on
   GitHub's servers — a PR triggers it per-push, before any merge happens;
   pushing `main` after `bin/merge-to-main.sh` triggers it on the merge
   commit, including the attestation job. Because `merge-to-main.sh` already
   mirrors every CI check locally, CI at this point is expected to be a
   redundant, independent confirmation — not the first time any of this ran.

Never commit straight to `main`; never merge a branch whose checks or
`/security-review` did not pass.

## Checks (run before every merge / PR)

| Command | What it checks |
|---------|----------------|
| `composer test` | PHPUnit unit tests (WordPress-free `Support\*` + `Api\Client`). |
| `composer lint` | PHPCS — [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) (`WordPress` + `PHPCompatibilityWP`), across `includes/`, `config/`, and `tests/`. |
| `composer lint:fix` | PHPCBF — auto-corrects the mechanical violations. |
| `composer lint:security` | PHPCS **security** ruleset (`WordPress.Security.*`) — the gate. |
| `composer analyse` | PHPStan static analysis (level 5, WP stubs). |
| `npm run lint:js` | ESLint with `@wordpress/eslint-plugin` over `src/` (`npm run lint:js:fix` to auto-correct). |
| `npm run lint:style` | stylelint with `@wordpress/stylelint-config` over `src/**/*.scss` (`npm run lint:style:fix` to auto-correct). |
| `bin/security-scan.sh` | The exact security gate (PHPCS security ruleset) the pre-commit hook, `bin/merge-to-main.sh`, and CI run. |
| `bin/coding-standards.sh` | The exact PHP standards check the pre-commit hook and CI run; `bin/php-matrix.sh` re-runs it across every supported PHP version at merge time. |
| `bin/style-lint.sh` | The exact CSS/SCSS standards check the pre-commit hook, `bin/merge-to-main.sh`, and CI run. |
| `bin/js-lint.sh` | The exact JS standards check the pre-commit hook, `bin/merge-to-main.sh`, and CI run. |
| `bin/unit-tests.sh` | Single-version PHPUnit — the pre-commit hook. `bin/php-matrix.sh` covers the full version range at merge time. |
| `bin/static-analysis.sh` | Single-version PHPStan — the pre-commit hook. `bin/php-matrix.sh` covers the full version range at merge time. |
| `bin/php-matrix.sh` | PHPUnit + PHPCS + PHPStan under a fresh `composer install` per PHP version, 8.0–8.5 — mirrors CI's `build` matrix exactly. `bin/merge-to-main.sh` only; too slow for every commit. |
| `bin/semgrep-scan.sh` | The exact Semgrep scan (`p/owasp-top-ten`, `p/php`) the pre-commit hook, `bin/merge-to-main.sh`, and CI run. Needs network access. |
| `bin/sbom-check.sh` | The exact SBOM freshness check the pre-commit hook runs (no-op unless a lock file is staged); `bin/merge-to-main.sh` and CI instead run `--regenerate` unconditionally, with npm pinned to the version the committed SBOM was generated with. |
| `bin/write-report.sh` | Runs all ten pre-commit checks above and writes their combined output to `../wynko-reports/`. This is what the pre-commit hook actually calls. |
| `bin/wp-org-check.sh` | WordPress.org readiness guard (readme/header fields — see below). Runs in the pre-commit hook, `bin/merge-to-main.sh`, the `plugin-check` CI job on every push/PR, and again from `bin/release.sh` before a release. |
| `npm run test:e2e` | The `tests/e2e/` Playwright suite (signup forms + caching) against a live Laposta test account; needs `npx @wordpress/env start` first plus `WYNKO_TEST_API_KEY` / `WYNKO_TEST_LIST_ID`. Runs nightly and on demand via `.github/workflows/e2e.yml`, and as a hard gate in `bin/release.sh`. Not in the pre-commit hook or `bin/merge-to-main.sh`. |
| `bin/plugin-check.sh` | The official [Plugin Check](https://wordpress.org/plugins/plugin-check/) tool, all categories, strict — every finding blocks, not just errors. Runs in the pre-commit hook and `bin/merge-to-main.sh` (both need `npx @wordpress/env start` first) and, via the `WordPress/plugin-check-action`, in CI. |
| `bin/merge-to-main.sh` | The merge gate — a full local mirror of every CI job except `security-review-attestation`. See "Branching & commit flow" above. |
| `composer sbom:check` | The same check, run unconditionally. CI + pre-release. |
| `composer sbom` | Regenerates `sbom/*.cdx.json` (runtime dependencies only). |
| `composer audit:deps` | Outdated packages + security advisories, both ecosystems. Pre-release; reports, doesn't gate. |

Via Docker, prefix the PHP commands with:
`docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.5-cli <cmd>`.

### The standard

WordPress's coding standards cover four languages — PHP, HTML, CSS, and
JavaScript — and three of them are enforced by an automated tool here.

PHP follows the WordPress Coding Standards
([handbook](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/),
[sniffs](https://github.com/WordPress/WordPress-Coding-Standards)): tabs,
Yoda conditions, spaces inside parentheses, `array()`, and a docblock on every
file, class, and function (summary + `@param`/`@return`). JavaScript follows the
WordPress JavaScript standard via `wp-scripts lint-js`, which applies
`plugin:@wordpress/recommended` (`typescript` is a dev dependency only because
that config's TypeScript rules refuse to load without it). CSS/SCSS follows the
WordPress CSS standard via `wp-scripts lint-style`, which applies
`@wordpress/stylelint-config`.
`phpcs.xml.dist` documents the two deliberate departures: `WordPress.Files.FileName`
(PSR-4 autoloading names files by class) and the docblock/naming sniffs waived
inside `tests/`.

HTML is the exception: WordPress's [HTML standard](https://developer.wordpress.org/coding-standards/html/)
is prose guidance (attribute quoting, indentation, void-element conventions)
with no WordPress-endorsed automated linter behind it, unlike the other
three. Markup this plugin outputs (admin screens, the frontend form) follows
it by convention and code review only — see `TECHNICAL_DEBT.md` for the
tracked gap and its revisit trigger.

## Definition of done

A change is ready to merge to `main` (or open as a PR) when **all** of these hold:

1. The pre-commit hook passes on every commit (it always runs — see
   "Branching & commit flow"), which already covers `composer test`,
   `composer lint`, `composer lint:security`, `composer analyse`, `npm run
   lint:js`, `npm run lint:style`, a Semgrep scan, `wp-org-check`, and
   Plugin Check, at a single PHP version.
2. `bin/merge-to-main.sh` passes — it re-runs everything above across the
   full PHP 8.0–8.5 matrix and a full pinned-npm SBOM regen, mirroring CI.
   Passing this means CI is expected to be a formality, not the first real
   check.
3. You have run Claude Code's **`/security-review`** on the branch and resolved
   (or justified) every finding.
4. Any new endpoint, handler, webhook, or form implements the control its OWASP
   row in `SECURITY.md` requires (capability check, nonce, sanitize, escape,
   signature verification…). Touching Hooks, Admin Menus, Settings, Metadata,
   Custom Post Types, Taxonomies, Users, the HTTP API, JS/Ajax, Cron,
   i18n, or Privacy? Check the matching row in `HANDBOOK_COMPLIANCE.md` is
   still accurate, and update it in the same commit if not.
5. Once a remote exists, the CI **`security` job is green** — it hard-fails on
   any PHPCS security or Semgrep OWASP/PHP finding and blocks merge.
6. Added a **runtime** dependency? `sbom/` is regenerated and committed
   alongside the lock file. The pre-commit hook blocks the mismatch, but don't
   rely on it. Dev-only changes need nothing — the SBOM does not cover them.
7. Did you defer, cap, or work around anything to get here? It is written
   down — see below.

## Recording technical debt

Any deliberate shortcut gets written down **in the change that takes it**, not
afterwards. A check that runs in one place but not another, a tool pinned below
its current major, a `@todo` you argued yourself out of fixing, a feature
shipped without the guard it deserves — each needs a note saying what was
deferred, why, what it costs while it stands, and the concrete trigger that
reopens it.

The reviewer's question is not "is this shortcut acceptable" but "is this
shortcut written down". Undocumented debt is the only kind that is not allowed.

The maintainers keep the full register (`TECHNICAL_DEBT.md`) outside this
repository, because it doubles as a roadmap. **If you are contributing from
outside, put the note in your pull request description** and it will be
transferred; you are not expected to maintain a file you cannot see.

## End-to-end / integration testing (`@wordpress/env`, Docker)

`.wp-env.json` spins up a full local WordPress site with this plugin installed,
via [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npx @wordpress/env start
# Site:       http://localhost:8888  (admin / password)
# Tests site: http://localhost:8889

npx @wordpress/env stop
```

Useful `wp-cli` smoke checks once the environment is running:

```bash
# Activate the plugin and confirm no PHP errors are raised
npx @wordpress/env run cli wp plugin activate wynko

# Confirm the block registered
npx @wordpress/env run cli wp eval \
  'echo WP_Block_Type_Registry::get_instance()->is_registered("wynko/campaigns") ? "REGISTERED" : "MISSING";'

# Confirm the plugin classes autoload
npx @wordpress/env run cli wp eval 'echo class_exists("Wynko\\Admin\\SettingsPage") ? "OK" : "NO";'
```

`WP_DEBUG` and `WP_DEBUG_LOG` are enabled in `.wp-env.json`, so PHP notices and
warnings are written to `wp-content/debug.log` inside the environment — check
that file (or the terminal output above) for anything unexpected.

Translations also regenerate through this container (`npm run i18n`), so start
wp-env before running them.

## Manual acceptance checklist (needs a real Laposta API key + a browser)

These steps cannot be automated — they need a live Laposta API key and a browser
session — and are walked through before a release. Run `npx @wordpress/env start`
and go in order, recording PASS/FAIL for each:

- [ ] Activate **Wynko** — no errors.
- [ ] Wynko → Settings loads; API key field is empty (never pre-filled).
- [ ] Save an **invalid** key → inline error "API key not saved: Invalid API
      key…"; key NOT stored; error row appears in the activity log.
- [ ] Save a **valid** key → success notice "API key verified — N campaigns
      loaded."; info row appears in the log.
- [ ] Reload settings → key field shows the "saved" placeholder, not the key
      value. View page source → the key string does not appear anywhere.
- [ ] Change cache duration, Save → value persists.
- [ ] Click **Sync now** → success notice; new "Sync started/succeeded" rows
      in the log.
- [ ] Let the cache expire and load a page holding the block → an "Automatic
      sync succeeded" row appears, once per cache window rather than once per
      page view.
- [ ] About tab → **Test connection now** with no key configured → a warning
      row appears in the log.
- [ ] About tab → the system report marks every row it can judge, and the
      recommendations above it read as guidance rather than findings.
- [ ] Dashboard on a site behind the advised versions → the requirements notice
      appears once, names what falls short, and links to the About tab; it does
      **not** appear on the About tab itself.
- [ ] Dismiss the requirements notice → it stays gone across page loads; change
      a version (or a threshold) → it comes back.
- [ ] Log in as an Editor → no requirements notice.
- [ ] Activity log → filter by level and by a search word → the count line
      matches the rows shown; **Reset** clears both.
- [ ] Activity log → **Download log (.txt)** → the file's rows match the
      filtered view and its header names the filter; the API key appears
      nowhere in it.
- [ ] Settings → set **Activity log detail** to *Errors only* → sync again;
      no new info rows are written, and the rows stored earlier are still
      listed.
- [ ] Submit a signup form → an info row naming the form appears, with no
      email address in it. Submit it with a bad email → a warning row.
- [ ] Activity log → **Clear log** → confirm, the empty state returns.
- [ ] Add the **Wynko: Campaigns** block to a post; inspector shows "Number
      of campaigns" (1–100) and a "List" dropdown; editor preview lists
      campaigns.
- [ ] Pick a list in the block inspector → the preview and front end narrow to
      that list's campaigns.
- [ ] View the post on the front-end → `<ul>` of links; each link opens in a
      new tab; `rel="noopener noreferrer"` present (check page source).
- [ ] Confirm only sent campaigns (with a `web` URL) appear, newest first.

## Releasing

Before cutting a release, on top of the definition of done:

```bash
composer sbom:check     # SBOM matches the lock files
composer audit:deps     # outdated packages + security advisories
```

Upgrade what should be upgraded; record what you consciously leave behind.

Then, from a clean `origin/main`, with `RELEASE_GPG_KEY_ID` and
`RELEASE_SSH_HOST` set (see [Remotes & release identity](#remotes--release-identity)
and, for maintainers, `RELEASE_IDENTITY.md`), plus `WYNKO_TEST_API_KEY` and
`WYNKO_TEST_LIST_ID` for the e2e gate (the live Laposta test account — see
`RELEASE_IDENTITY.md`), and Docker running:

```bash
bin/release.sh
```

This is the only sanctioned way to bump the version — it keeps the three
places version numbers live in sync instead of you editing them by hand:

| Where | What |
|---|---|
| `wynko.php` header | `Version:` |
| `wynko.php` | the `WYNKO_VERSION` constant |
| `readme.txt` | `Stable tag:` — WordPress.org serves whatever this names |

`bin/release.sh`:

1. Runs `bin/wp-org-check.sh` and refuses to proceed on a known WP.org
   submission blocker (e.g. `readme.txt`'s `Contributors:` still says `TODO`),
   then runs the full `tests/e2e/` suite (Playwright + wp-env, against the
   live Laposta test account) and refuses to proceed if it fails. This takes
   several minutes and needs `WYNKO_TEST_API_KEY` / `WYNKO_TEST_LIST_ID` set;
   a clean `test.skip` (a caching plugin wp-env can't drive headlessly) is
   fine, a real failure is not.
2. Lists commits since the last release tag, computes a suggested semver bump
   from their Conventional Commit types, and lets you confirm or override it.
3. Drafts a new `== Changelog ==` entry for `readme.txt` and **stops for your
   approval or edits** before writing anything.
4. On approval: bumps the version in all three places above, writes the
   changelog entry, commits `chore(release): x.y.z`, tags `vX.Y.Z`, and
   pushes both to `origin` (the private mirror — this is also your backup).
5. Builds a filtered snapshot of that release (via `git archive`, stripping
   the paths `.publishignore` lists — maintainer-only material like
   `TECHNICAL_DEBT.md` and `docs/superpowers/` never leaves this machine) and
   applies it as a single new commit on the `public` remote's `main`, signed
   as `roy-dg`. Public history is one commit per release; it shares no
   ancestry with `origin`'s branch history.
6. Pushing that tag to the public repo triggers its own
   `.github/workflows/release.yml`, which builds the ZIP with `bin/package.sh`,
   reads the release notes back out of the tag commit's body (the same
   changelog entry approved in step 3), and creates the GitHub Release with
   the ZIP attached — automatically, no further action needed.

While you're reviewing the changelog draft, also check `Tested up to:` in
`readme.txt` against the WordPress version you actually tested against
(`npx @wordpress/env run cli wp core version`) — `bin/release.sh` doesn't
infer this for you.

**Before submitting to WordPress.org specifically** (first submission, or a
resubmission after a rejection): CI's `plugin-check` job runs the full
default check suite already, but only against whatever the repo looks like
on that push. Build the actual ZIP with `bin/package.sh` and run Plugin
Check against it directly, all categories, using the same official plugin
WordPress.org's own review runs — CI is a proxy for this, not a replacement.
The review's own findings warn there may be more than what one pass names,
so read the entire output, not just what a prior review happened to flag.

Also run the WordPress.org MCP server's `Validate Readme` tool against the
built `readme.txt` before submitting or updating on WP.org — it complements
`bin/wp-org-check.sh` (which only checks a handful of fields) with the same
readme validation WordPress.org runs. It's a manual, interactive step, not a
CI one: `npx -y @wporg/mcp` opens a browser to authorize once and issues a
WP.org application password, which has no business sitting in a CI secret.

**Building the installable ZIP** for manual testing is a separate step (the
release workflow builds its own copy for the GitHub Release, independently):

```bash
bin/package.sh          # or: bin/package.sh <ref> — defaults to main
```

It exports the ref with `git archive` (so the working tree never reaches the
artifact), runs `composer install --no-dev` and `npm run build` inside that
export, strips everything `.distignore` lists, checks that the files the plugin
needs at runtime are present, and writes `dist/wynko-<version>.zip`.
Because the export comes from the ref, a `.distignore` change only takes effect
once it is committed. `.distignore` and `.publishignore` are different lists
for different artifacts — `.distignore` strips dev tooling (`bin/`, `tests/`,
`CONTRIBUTING.md`...) that has no place in the WordPress runtime ZIP;
`.publishignore` strips only maintainer-internal files from the public git
history, and keeps `bin/`, `tests/`, and `CONTRIBUTING.md` so contributors can
build and test the plugin from the public repo.

`sbom/` ships with the archive: it lists runtime dependencies only, so it
describes the artifact accurately, and the Composer manifests are stripped.
See [`sbom/README.md`](sbom/README.md).

`build/` is deliberately absent from version control — `bin/package.sh`
regenerates it inside the export with `npm run build`. Its absence from a fresh
clone is correct; don't "fix" it by committing compiled output.

CI runs the official [Plugin Check](https://wordpress.org/plugins/plugin-check/)
plugin on every push and PR (the `plugin-check` job), scoped to the same
runtime files `bin/package.sh` ships — dev tooling is excluded via
`.distignore`. It's the same tool the review team runs, so a red `plugin-check`
job means the WordPress.org submission would fail too. The job runs with
`strict: true`, so a `WARNING`-level finding fails the build exactly like an
`ERROR`-level one — Plugin Check only fails on `ERROR` by default, and several
of the checks WordPress.org review draws from (text-domain/slug match,
bundled translations, trademarks) report at `WARNING` severity, so leaving
the default in place let those pass CI silently. If a finding is ever accepted as a deliberate, recorded deferral (see
[`TECHNICAL_DEBT.md`](TECHNICAL_DEBT.md)), suppress it for CI with
`ignore-codes` on the same step, named there explicitly, rather than
loosening `strict` and letting other findings pass unnoticed too.

## Suppressing a false positive

Only with an inline justification, which review will scrutinize:

```php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- <why this is safe>
// nosemgrep: <rule-id> -- <why this is safe>
```

## Architecture (where things go)

- `includes/` — PSR-4 (`Wynko\`) plugin classes.
  - `Api/` — Laposta API transport (`Client`) + resources (`Campaigns`, …).
  - `Support/` — **pure, WordPress-free** logic; keep it unit-testable (no
    `get_option`/`__()`/WP calls — `SupportIsWordPressFreeTest` enforces this).
    Translation is a WordPress concern, so `Support/` returns a classification
    and the WordPress-facing caller supplies the wording: HTTP failures go
    `Sanitizer::classify_status()` → `Api\Client::status_message()`. New status
    semantics belong in the classifier; their prose belongs with the caller.
  - `Admin/`, `Blocks/` — WordPress-facing surfaces.
  - `Config.php` / `config/settings.php` — the single source of option keys,
    defaults, and bounds. Don't hard-code an option name or magic number
    elsewhere.
  - `Urls.php` / `config/urls.php` — the single source of external URLs and the
    target each link opens in; `Urls::rel()` derives `rel` from the target.
    `NoHardcodedUrlsTest` fails the build on a URL literal under `includes/`.
- `src/block/` — block JS source; `build/block/` — compiled output.
