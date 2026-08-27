# Security Policy

Security is treated as a continuous part of every development cycle for this
plugin, not a one-off audit. This document is the **spec** that the automated
checks (local pre-commit hook, gating CI security job) and the human review
step (`/security-review` in the definition of done, see `CONTRIBUTING.md`)
enforce against.

## Reporting a vulnerability

Please report suspected vulnerabilities **privately** — do not open a public
issue.

- Preferred: open a [GitHub private security advisory](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
  on this repository (Security → Report a vulnerability).
- Include: affected version, a description, reproduction steps, and impact.

We aim to acknowledge reports within a few business days and to ship a fix in a
timely, coordinated manner before public disclosure.

## Supported versions

The latest released version receives security fixes.

## Threat model — OWASP Top 10 mapped to this plugin

The reference plugin in this space (Mailchimp for WordPress, 1M+ installs) has a
disclosure history dominated by **broken access control / unauthenticated
actions** and **XSS**. This plugin's roadmap — a public webhook endpoint and
front-end signup forms that *write* to Laposta — introduces exactly that attack
surface. Each row below is a rule that new code must satisfy.

| OWASP | Surface in this plugin | Required control |
|-------|------------------------|------------------|
| **A01 Broken Access Control** | Settings page, `admin-post` sync, the About tab's system-report export and connection re-test (`admin_post_wynko_system_report`, `admin_post_wynko_api_ping`), the activity log's download and clear (`admin_post_wynko_export_log`, `admin_post_wynko_clear_log`), the Notifications tab's test send (`admin_post_wynko_notify_test`), the signup rate-limit reset (`admin_post_wynko_reset_throttle`), the requirements notice's dismissal (`admin_post_wynko_env_dismiss`), the signup forms' create, save, rename, and delete actions (`admin_post_wynko_new_form`, `_save_form`, `_rename_form`, `_delete_form`), the public signup submit action (`admin-post[_nopriv]_wynko_submit_form`), the `wynko/v1` REST routes, future webhook routes | Every state-changing handler checks `current_user_can()` for the right capability, except the public signup submit endpoints, which are deliberately unauthenticated and rely on a form-scoped nonce plus `Throttle`'s per-IP and per-form rate limit instead. REST routes define a real `permission_callback`; a bare `permission_callback => '__return_true'` is allowed **only** for an endpoint that is public by design *and* verifies a form-scoped nonce as its first action — today that is `POST wynko/v1/forms/<id>/submit` and nothing else. Anything else using it is a bug. Webhook routes authenticate the caller (Laposta signature / shared secret) before doing anything. |
| **A02 Cryptographic / secrets** | Laposta API key | Never echoed back to the page (masked placeholder, or the source's *name* only); never written to logs; only a SHA-256 fingerprint of it is cached (`wynko_key_status`). Encrypted at rest — see below. A key stored in the database is deleted on uninstall (`uninstall.php`); a key supplied by an `WYNKO_API_KEY` / `WYNKO_API_KEY_{blog_id}` environment variable or `wp-config.php` constant is never written to the database, so it is outside the plugin's lifecycle and is not removed on uninstall. When either is set, the settings page refuses to store a shadowing option, but a value saved beforehand is retained (inert) in the database; `register_setting()` only runs behind `is_admin()`, so WP-CLI or a direct `update_option()` call can still write `wynko_api_key` — it is inert, since `ApiKey::resolve()` always prefers the higher-precedence source. |
| **A03 Injection & XSS** | Every request input; all admin/front-end output; a signup field's `pattern`; critical-email recipients | Sanitize + validate all input (`sanitize_*`, `wp_unslash`); escape all output at the point of output (`esc_html`, `esc_attr`, `esc_url`). No raw SQL — use the options/transients API. A field's `pattern` is the one input the *server* executes rather than prints: it is compile-tested by `Support\Fields::compile_pattern()` when the form is saved and a form carrying one that will not compile is refused whole, so `preg_match()` never meets an unusable pattern during a submission — where its warning would be silent, and on an admin screen would raise core's `php-error` body class. It is always wrapped anchored (`/\A(?:…)\z/u`) so it cannot match a substring, and a pattern that fails to compile matches nothing rather than waving a value through. Writing one needs `manage_options`, the same capability that can install code; a deliberately catastrophic regex is therefore a trusted-author risk, not a privilege boundary, and is not defended against. Critical-email recipients are the one typed input that becomes a mail *header*, and the control that keeps a newline — and therefore an injected header — out of `$to` is `Support\Recipients::parse()`, not `is_email()`: `parse()` treats `\r` and `\n` as list separators and `trim()`s every piece, so a smuggled header becomes its own list entry rather than riding along inside one. Core's `is_email()` is the *deliverability* filter applied after that (its local-part regex is unanchored against a trailing newline, so it must never be relied on alone). Both run at save time and again at send time, and a submitted string never reaches the mailer whole. Narrowing `parse()`'s separator class, or adding a caller that hands pre-split addresses straight to `is_email()`, reopens this. The alert body is `text/plain`, so a log message carrying remote text from Laposta is inert rather than escaped. |
| **A05 Security Misconfiguration** | Direct file access | Every PHP file guards with `if ( ! defined( 'ABSPATH' ) ) { exit; }`. |
| **A08 CSRF (WP-specific)** | Every state-changing form/handler | Nonces required (`wp_nonce_field` + `check_admin_referer` / `wp_verify_nonce`). This includes handlers whose only effect is cosmetic — dismissing the requirements notice writes an option, so it is nonced like any other write. Deleting a form keeps its own form, action, and nonce, separate from the save it sits beside, so a save can never trigger a delete — the two are adjacent by CSS only. Bulk deletion from the forms list runs on the list screen itself under `WP_List_Table`'s own `bulk-wynko_forms` nonce, and `FormsListPage::bulk_delete()` re-checks the capability and calls `FormData::load()` on every id, so an arbitrary post id cannot be destroyed through it. |
| **A09 Logging failures** | Activity log | Log security-relevant events (key rejected/accepted, connection probes, sync outcomes, signup outcomes) — but never secrets and never personal data. A signup entry names the form and the outcome; it never carries the submitted address or field values, because the log is downloadable as a `.txt` by any `manage_options` user. The recording threshold (`wynko_log_level`) may suppress `info` and `warning`, never `error`. The three publicly reachable failure modes — a bad nonce, an unknown form, and a throttled request — are deliberately not logged, so anonymous probe traffic cannot push real entries past the cap. A duplicate signup is logged at `warning`, never `error`: the visitor is told it succeeded, so it must not reach `Notifier`. `Notifier` mails an `error` entry onward to the configured addresses, capped at one per hour; the alert carries the log message, the site URL, and the time, and — like the log itself — never the API key, any part of it, or its fingerprint. |
| **A10 SSRF** | Outbound calls to Laposta | `Wynko\Api\Client` only ever calls paths under the fixed `Config::api_base()` host. No user-supplied hostnames or redirects. A failed signup carrying Laposta's field-drift signature can cause one forced field refetch, to that same fixed host: the exposure is request volume, bounded by a per-list cooldown of `Cache::negative_ttl()` (`FormSubmitHandler::may_resync()`) and by `Throttle`'s per-IP and per-form caps, which run first. |

## Encryption of the stored API key

A key stored in `wp_options` is sealed with `sodium_crypto_secretbox`, using
material derived from `SECURE_AUTH_KEY` + `SECURE_AUTH_SALT` (falling back to
`AUTH_KEY` + `AUTH_SALT`). Sealed values carry an `wynko:v1:` prefix.

**What this protects against:** database-only exposure — SQL injection, a
leaked or shared backup, a host-level dump, an administrator browsing
`wp_options`.

**What it does not protect against:** filesystem access. `wp-config.php` holds
both the salts and the database credentials, so anyone who can read it can
decrypt the key. Do not describe this as protecting the key from a compromised
server. Operators who need the key out of the database entirely should set the
`WYNKO_API_KEY` environment variable, which outranks both the constant and the
option.

**Salt rotation.** Rotating `SECURE_AUTH_KEY` — standard incident response —
makes every sealed key unopenable. The plugin treats that as *no key
configured*: `ApiKey::stored()` returns `''` rather than the ciphertext, so a
useless credential is never sent to Laposta. `ApiKey::stored_state()` reports
`unreadable`, the settings page says so specifically instead of showing a bare
*Not connected*, and one error is written to the activity log — from the
settings-page render only, never from `resolve()`, which runs on front-end
requests.

**Sites without usable salts.** When neither pair is defined, or they still
carry WordPress's `put your unique phrase here` placeholder, the key is stored
as plain text and the settings page says so. Refusing to store it would be a
worse outcome than storing it the way earlier versions did.

## Rules for new endpoints, handlers, and forms

Before merging code that adds any of the following, confirm the matching control:

- **A new `admin-post` / AJAX action** → capability check **and** nonce.
- **The system report's two actions** (`admin_post_wynko_system_report`,
  `admin_post_wynko_api_ping`) each check `Menu::CAP` and their own nonce, and
  post from separate forms so one button cannot trigger the other. The exported
  `.txt` is written to be pasted into a public support thread: it carries the
  API key's **source** and the cached **verdict** about it, never the key, any
  part of it, or its SHA-256 fingerprint (A02). It reads no salts, no database
  credentials, and no `AUTH_*` constant. The body is streamed as `text/plain`
  after `nocache_headers()`, with nothing echoed before the headers — the one
  place in the plugin where output is deliberately not HTML-escaped, because
  escaping would corrupt the file the operator downloads. The re-test is the
  only path on that screen that calls Laposta; the report itself reads
  `KeyStatus`'s cached verdict, so opening the tab never makes a request. The
  environment rows carry nothing privileged either: `OPENSSL_VERSION_TEXT`,
  `curl_version()`, `SERVER_PROTOCOL`, and `SSL_PROTOCOL` are facts the server
  already announces to every client.
- **The requirements notice's dismissal** (`admin_post_wynko_env_dismiss`)
  checks `Menu::CAP` and its own nonce, and posts nothing but the action — there
  is no submitted value for it to trust. What it writes is a SHA-256 digest of
  the environment readings and never the readings themselves, so an option an
  unprivileged query could reach still discloses no version, no module list, and
  no server software. The notice it silences is gated on `Menu::CAP` as well:
  what a site runs is an operator's business, not an author's.
- **The activity log's two actions** (`admin_post_wynko_export_log`,
  `admin_post_wynko_clear_log`) each check `Menu::CAP` and their own nonce, and
  post from separate forms so Download cannot trigger Clear. The download
  reproduces the filter shown on screen, which arrives as hidden POST fields —
  request data, not screen state, so `handle_export()` re-validates the level
  against `Config::allowed_for( 'log_level' )` and re-sanitizes the search text
  before either reaches the filter. The body streams as `text/plain` with
  `nocache_headers()` and `X-Content-Type-Options: nosniff`, unescaped for the
  same reason the system report is. `.txt` rather than CSV, so no cell can be
  read as a spreadsheet formula. It contains no key material (A02) and no
  submitted subscriber data (A09).
- **The forms list's inline rename** (`admin_post_wynko_rename_form`) checks
  `Menu::CAP` and its own nonce, reads the id and the name from `$_POST` only,
  and `sanitize_text_field()`s the name before `wp_update_post()` — the title is
  escaped at every point it is printed, on the list and in the editor. Its input
  sits inside the list table's row but posts to a form declared after the table,
  bound by the `form` attribute, so the rename cannot ride on the bulk-action
  form's nonce and a bulk delete cannot be triggered by pressing Save. An id
  that is not a signup form changes nothing (`FormData::load()` returns null).
- **The forms-per-site cap** (`Config::max_forms()`, checked in
  `FormsListPage::can_add()` and again in `FormEditPage::handle_new()`) is a
  product boundary, **not** an access control. It gates this plugin's own create
  request; it does not stop `wp_insert_post()` from WP-CLI or another plugin.
  Unlike every other setting it reads no stored option, and its override applies
  only where `wp_get_environment_type()` is `local` or `development` — but that
  is product hygiene, not a control either: anyone who can set the environment
  type can edit the plugin's own PHP. Nothing may be built on top of it that
  assumes a form count is enforced.
- **A setting supplied by the environment or a constant** (`Config::override()`)
  is trusted input by definition — whoever can set it can already edit
  `wp-config.php` — but it is still coerced to the setting's declared shape:
  enums are held to `allowed`, integers are clamped to `bounds`, and a value
  that fits neither is ignored rather than stored. The API key is deliberately
  **not** part of this path: it resolves through `ApiKey`, which owns the sealed
  storage as well as the precedence (A02). A field whose value comes from an
  override renders as a read-only note plus a hidden input carrying the stored
  option, so saving that tab cannot blank what the database holds.
- **A form's own message wording** is the only stored value the plugin renders
  as markup. Three slugs render above the form and nowhere else — success, the
  generic error, and already-subscribed — and those may carry a link, `strong`,
  `em`, and `br`: `Forms\Messages::allowed_html()`. `target` is deliberately
  absent from that allowlist: `Urls` derives where a link opens from what it
  points at, and a hand-typed `target` would reach a visitor's page without the
  `rel` that has to accompany it. Writing one needs
  `Menu::CAP` (`manage_options`), the same capability that can install code, so
  this is a trusted-author surface rather than a privilege boundary; the
  allowlist is what keeps a mistake from becoming a script tag, and
  `wp_kses()`'s protocol check is what keeps a `javascript:` href out of the
  link. It is filtered twice: on save in `Admin\Forms\FormEditPage::
  clean_messages()`, and again at render in `Frontend\FormRenderer::notice()`,
  so wording stored before this rule existed cannot reach a visitor unfiltered.
  Every other slug can be attached to a single field by `FormValidator` and is
  `sanitize_text_field()` on the way in and `esc_html()` on the way out.
- **A new REST route** → a real `permission_callback`; sanitize every arg via its
  `args` schema; escape everything returned into HTML contexts.
- **A webhook receiver** → verify the Laposta signature/shared secret first;
  treat the payload as untrusted; return early on any mismatch.
- **A front-end signup form** → nonce on submit; sanitize every field; escape all
  echoed values; rate-limit / validate before calling the Laposta write API.
- **The public signup submit action** (`admin-post[_nopriv]_wynko_submit_form`)
  is the plugin's only unauthenticated state-changing endpoint. It verifies a
  **form-scoped** nonce (`wynko_submit_form_{form_id}`), so one form's token
  cannot submit another; answers a bad nonce and a missing form with the same
  404, so a probe learns nothing; validates every value server-side against the
  list's live field definitions (`Support\FormValidator` — the front end's
  `required`/`type` attributes are a convenience, never the boundary); and
  redirects rather than rendering, so a refresh cannot re-subscribe.
- **The submit endpoint is rate limited** by `Throttle`, per IP and per form,
  before the form is even loaded. The nonce is an authenticity check, not a
  throttle: `wp_verify_nonce()` is not single-use, and a logged-out visitor's
  token hashes uid 0 against an empty session, so *every* anonymous visitor
  holds the same one for its full ~24h life. Without the counter, one fetch of
  a form page buys a day of unlimited submissions — and the damage is not API
  quota but mail: each accepted POST makes Laposta send a confirmation email,
  carrying the site's branding, to any address the caller names. Both caps and
  the window are administrator-settable (Settings → API), and both are clamped
  to their configured bounds on read as well as on save, because a cap of zero
  would close every form on the site. Counters are transients keyed by
  `wp_hash()` of the IP — a one-way, salt-keyed digest, never an address, and
  never `Support\Crypto`, which is reversible by design. "Reset signup limits"
  clears them all by bumping an epoch the keys carry.
- **The submit endpoint carries a honeypot** (`wynko_website`), hidden in CSS
  rather than with `type="hidden"` or `display:none` — both of which a capable
  scraper skips. A filled one is answered with the ordinary success message and
  never reaches Laposta: a bot told it failed simply tries again.
- **A duplicate signup is answered exactly like a new one, by default.**
  Reporting "already subscribed" to an anonymous caller turns the form into a
  membership oracle for any address someone cares to try. A per-form setting
  (Settings → Already subscribed, `reveal_duplicate`) can turn the honest
  message back on; it ships **off**, and the control states the exposure beside
  itself so the choice is made knowingly rather than discovered later.
  Administrators see the duplicate in the activity
  log either way — as a `warning`, not an `error`, because `Notifier` mails
  every error onward and an outcome the visitor may have been told succeeded
  must not page anyone.
- **The submission-result transient** (`wynko_form_result_{token}`) briefly
  holds one visitor's submitted values so the form can be redisplayed with
  their input after a failure. It is keyed by a 24-character random token,
  expires in five minutes, and is deleted on first read. Never put the terms
  checkbox in it: a legal agreement is not something the plugin re-ticks on
  someone's behalf.
- **The `wynko/v1` REST routes.** Two exist. Both are registered on
  `rest_api_init` outside the `is_admin()` gate, because a REST request does not
  reliably report as admin.

  | Route | Auth | Nonce | Sanitize | Escape |
  |---|---|---|---|---|
  | `GET wynko/v1/forms/<id>/fields` | `permission_callback` requires `Menu::CAP` (`manage_options`) | REST cookie nonce (`X-WP-Nonce`), checked by core before routing | `absint` on the id, `sanitize_text_field` on `list_id` | `Admin\Forms\FieldRows` escapes each value where it writes it |
  | `POST wynko/v1/forms/<id>/submit` | public by design — a visitor is not logged in; metered by `Throttle` per IP and per form | form-scoped `wp_verify_nonce`, the first thing `FormSubmitHandler::process()` does | `FormSubmitHandler::process()`, unchanged from the redirect path | `Frontend\FormRenderer`, unchanged |

  The fields route returns **markup**, not data, so the editor's field table has
  one source. It is read-only and administrator-only, and exposes nothing the
  editor screen does not already show.

  The submit route answers a bad nonce and an unknown form with the identical
  404 body, exactly as the `admin-post` handler does, so a probe learns nothing
  about which check it failed. It never returns markup alongside a redirect. A
  throttled request answers 429 on this path and `wp_die`s 429 on the
  `admin-post` path — short of the redirect on purpose, so a metered request
  cannot mint a result transient per attempt.

  Every reply on the namespace carries core's own no-cache headers, set by
  `Rest\Headers` on `rest_post_dispatch`. Core decides this by
  `apply_filters( 'rest_send_nocache_headers', is_user_logged_in() )`, so a
  logged-out caller — which is every caller of the submit route — otherwise gets
  none, and the reply is the visitor's own submitted values rendered back with a
  live form-scoped nonce (A02/A09): nothing between the site and the browser may
  keep a copy. Nothing is removed. `X-Powered-By` and `Server` belong to the
  server (`expose_php`, the vhost) and cannot be suppressed for one namespace
  while every other request still carries them; core's `Link` header is
  discovery the plugin publishes itself in `Admin\Assets`, and removing it
  wholesale would take `rel="next"`/`rel="prev"` with it once a collection route
  exists; `X-Content-Type-Options: nosniff` and `X-Robots-Tag: noindex` core
  already sends on every REST response.

- **The signup form's nonce field is named `wynko_nonce`, not `_wpnonce`.** This
  is load-bearing, not cosmetic: `rest_cookie_check_errors()` validates *any*
  request carrying `_wpnonce` against the `wp_rest` action globally, before
  routing. A form-scoped nonce under that name is rejected with a 403 and never
  reaches the handler. Do not rename it back.

- **A new admin string handed to core for display** → escape it yourself, at the
  call, wherever core echoes it raw. Core does that deliberately in several
  places so that plugins may pass markup, which makes escaping the caller's
  duty; a plain `__()` in one of those slots is a hole, not a style preference.
  Our own literals are harmless, but their translations are not necessarily —
  language packs are fetched from translate.wordpress.org rather than shipped
  from this repository, so a hostile or compromised translation is the realistic
  attack. Use `esc_html__()`, or `esc_html( sprintf( … ) )` when composed.

  | Passed to | Echoed by | Escape? |
  | --- | --- | --- |
  | `add_settings_section()` title | `do_settings_sections()` | **yes** |
  | `add_settings_field()` title | `do_settings_fields()` | **yes** |
  | `add_settings_error()` message | `settings_errors()` | **yes** |
  | `add_menu_page()` / `add_submenu_page()` **menu** title | `menu-header.php` | **yes** |
  | `add_menu_page()` / `add_submenu_page()` **page** title | `admin-header.php` via `esc_html()` | no — pre-escaping double-encodes |
  | `submit_button()` text | `get_submit_button()` via `esc_attr()` | no |
  | `wp_die()` message | escaped by us at the call | already done |

  Anything we print ourselves — `LogPage`, `AboutTab`, the block's front-end
  render — escapes at output as usual. When adding a row here, verify against
  core's source rather than assuming: the split above is not intuitive.
- **New data localized into the block editor** (`wp_add_inline_script` on
  `enqueue_block_editor_assets`) → readable by everyone who can edit content,
  which is a wider audience than `manage_options`. Ship only non-secret
  operational data — bounds, list names — and never key material or anything
  derived from it.

## Suppressing a false positive

Automated findings may be suppressed only with an inline justification:

```php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- <why this is safe>
// nosemgrep: <rule-id> -- <why this is safe>
```

An unjustified suppression is itself grounds to reject a change. So is a
justification that answers a different question than the sniff asks — e.g.
arguing *when* a nonce is checked when the sniff is asking whether the value
reaching it was sanitized first. Read the sniff's own rule name/description
before writing the reason, not just the line it's flagging.
