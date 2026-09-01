# Testing: signup forms and caching

Wynko's signup forms carry several protections against page caching and
CDNs — a long-lived, self-healing submit nonce, an uncached post-submit
result page, and two field-drift signals (see
["Caching plugins and CDNs"](../signup-forms.md#caching-plugins-and-cdns)).
This page covers the automated test suite that exercises all of it against a
real Laposta test account, plus the one scenario that stays manual.

## Running the automated suite

The suite is [Playwright](https://playwright.dev/) driven through
`wp-scripts test-playwright`, against a real `wp-env` instance and a real
Laposta test account. One-time setup:

```bash
npm run test:e2e:install
```

Then, with the test account's credentials in the environment:

```bash
WYNKO_TEST_API_KEY=... WYNKO_TEST_LIST_ID=... npm run test:e2e
```

`WYNKO_TEST_API_KEY` is deliberately a different name from `WYNKO_API_KEY`,
so a maintainer's real key is never picked up by accident.
`WYNKO_TEST_LIST_ID` is the Laposta id of the list the suite is allowed to
add and remove fields and members on — the suite has no way to resolve a
list's name to its id itself (see "The campaign-forbidden rule" below), so
the id has to be supplied directly.

Without `WYNKO_TEST_API_KEY` set, every spec that needs the live account
calls `test.skip()` with a clear reason — a clean skip, not a failure, so a
contributor without the test credentials isn't blocked from running the
rest of the suite.

The suite lives in `tests/e2e/`; see its specs for exactly what each
scenario checks:

| Spec | Scenario |
| --- | --- |
| `nonce-self-heal.spec.js` | JS on, a stale submit nonce retries silently and succeeds — no visible error, no activity-log entry. |
| `nonce-no-js.spec.js` | No JS, a stale submit nonce gets a 404 and no retry — the documented, accepted gap stays as scoped. |
| `validation-failure.spec.js` | A missing or badly formed field is rejected before it ever reaches Laposta. |
| `required-field-drift.spec.js` | Laposta gains a *required* field Wynko hasn't refetched yet — the existing warning-level resync fires and the form re-renders asking for it. |
| `optional-field-drift.spec.js` | Laposta gains an *optional* field while a page cache is still serving the old rendering — the quiet, info-level field-fingerprint signal fires exactly once per hour per form (cross-referenced from ["Caching plugins and CDNs"](../signup-forms.md#caching-plugins-and-cdns)). |
| `campaign-guard.spec.js` | Standing regression test that the campaign-forbidden guard below still throws. |

## The campaign-forbidden rule

**This suite must never create or send a Laposta campaign** — it only ever
touches the test list's members and fields. That rule is enforced three
ways, not just stated:

1. **A structural allowlist.** `tests/e2e/laposta-client.js` is the only
   module in the suite allowed to call `api.laposta.nl` directly, and its
   request helper throws synchronously — before any network call — on any
   path outside `field`/`member`. `campaign-guard.spec.js` is a standing
   regression test that this still throws.
2. **A lint-time guard.** An ESLint override (`.eslintrc.js`, scoped to
   `tests/e2e/specs/**` and `tests/e2e/fixtures/**`, enforced by the
   mandatory `npm run lint:js`) refuses a call carrying a literal that names
   Laposta's own host, so a spec can't route around the allowlist above by
   simply not importing `laposta-client.js`.
3. **This prose.** The two guards above are what actually stop it; this
   paragraph is the third belt, not the load-bearing one.

## What isn't automated

Laposta's own propagation lag — a change landing in the Laposta admin
taking a moment to become visible over the API — is inherently
non-deterministic and isn't exercised by this suite. To check it by hand:
add a field to "lijst om te testen" in Laposta's own UI, then immediately
call `Wynko\Api\Fields::for_list( $list_id, true )` (for example via
`wp eval-file`) and see whether the new field is already present.

```bash
npx @wordpress/env run cli wp eval 'var_export( \Wynko\Api\Fields::for_list( "YOUR_LIST_ID", true ) );'
```

If it's missing, wait a few seconds and try again — this is Laposta's own
timing, not something Wynko can control or detect.

## Environment notes

- `tests/e2e/mu-plugins/wynko-e2e.php` is mapped into `wp-env` by
  `.wp-env.json`'s `mappings` entry. It never ships (the whole `tests/`
  tree is excluded from the release ZIP via `.distignore`) and only
  shortens Wynko's own signup-submit nonces when a test-only option is set
  — it can never touch any other nonce.
- WP Super Cache is installed by `global-setup.js` (download only) and
  activated only by the two drift specs, bracketed to just the tests that
  need a real page cache in front of a form. It is not in `.wp-env.json`'s
  `plugins` list, so an ordinary `wp-env start` never sees it enabled.
- `global-teardown.js` removes the injected API key and does a best-effort
  sweep of any Laposta field left over from a crashed run (by the
  `wynko_e2e_` naming convention); per-spec hooks are the primary cleanup
  path.

---

Back to the [README](../../README.md) · [All documentation](../README.md)
