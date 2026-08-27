# Software Bill of Materials

A machine-readable list of the dependencies **this plugin uses** — the packages
that ship inside the distributed archive, and their transitive runtime
dependencies — in [CycloneDX](https://cyclonedx.org/) 1.6 JSON.

| File | Covers | Source of truth |
|------|--------|-----------------|
| `composer.cdx.json` | PHP runtime dependencies (`composer install --no-dev`) | `composer.lock` |
| `npm.cdx.json` | JavaScript runtime dependencies (`dependencies`, not `devDependencies`) | `package-lock.json` |

## Both are currently empty, and that is the correct answer

The plugin has no third-party runtime dependencies:

- `composer.json` requires only `php: >=8.0`. Everything in `vendor/` is a
  `require-dev` entry, so `composer install --no-dev` leaves Composer's own
  autoloader and nothing else.
- Every module `src/` imports (`@wordpress/block-editor`, `blocks`,
  `components`, `i18n`, `server-side-render`) is externalised by `wp-scripts` to
  a WordPress script handle — see the `dependencies` array in
  `build/block/index.asset.php`. Nothing from `node_modules/` is bundled into
  `build/`.

So each document lists the plugin itself and no components. The value is not
today's contents; it is that **the first real dependency cannot be added without
appearing here**, and cannot then drift.

Build tooling — phpunit, phpcs, phpstan, `@wordpress/scripts` — is deliberately
excluded. It is used to build and verify the plugin, not *by* the plugin.
Listing it would tell a downstream scanner that the shipped artifact contains
phpunit, which is simply false. The full dev tree is not undocumented: it is
`composer.lock` and `package-lock.json`, which is where it belongs.

## Regenerating

```bash
composer sbom     # or: npm run sbom, or: bin/sbom-generate.sh
```

Needs Docker (Composer side) and network access (both sides).

Both generators read the *installed* tree — `vendor/composer/installed.json` and
`node_modules/` — not the lock files directly, so a stale checkout would produce
a wrong SBOM. The script therefore runs `composer install` first, which makes
`composer.lock` the effective source of truth and is a no-op when the two
already agree. The npm equivalent (`npm ci`) is too slow to run per commit; CI
does it instead, so a local run trusts whatever `node_modules/` currently
holds.

Output is deterministic for a given generator version: `--output-reproducible` strips the
random serial number and timestamp, and the Composer root version is pinned from
the plugin header rather than guessed from the branch name. Regenerating without
a dependency change produces a byte-identical file.

That determinism is what makes the gate work:

- **Pre-commit** — `bin/sbom-check.sh` does nothing unless a lock file is
  staged. When one is, it regenerates and fails if the result differs from what
  you staged. A dev-only bump changes the lock but not the SBOM, so it passes
  without asking you to stage anything.
- **CI / pre-release** — `bin/sbom-check.sh --regenerate` runs the same check
  unconditionally.

## Generator versions

Pinned to **exact** versions in `bin/sbom-generate.sh`:
`cyclonedx/cyclonedx-php-composer 6.2.0` and `@cyclonedx/cyclonedx-npm 4.2.1`.
A range would eventually pull a new generator, change the output, and fail the
check above on a commit that touched no dependency. Bumping them is deliberate,
and the resulting SBOM diff belongs in that same commit — `composer audit:deps`
is where the bump should surface.

Neither is a project dependency. The Composer plugin needs PHP 8.1+ while this
plugin still supports 8.0, and a generator that appears in its own output is
noise. They are fetched on demand instead, which leaves their own dependency
trees unpinned, so the SBOM is reproducible only down to the generators
themselves.
