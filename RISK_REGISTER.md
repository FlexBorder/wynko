# Risk register

Every identified security risk that doesn't resolve as an immediate fix in
the same commit that found it — because it's accepted, needs a broader change
than the discovery warrants on its own, or simply hasn't been treated yet —
gets an entry here. `SECURITY.md` states the controls a surface is required
to have; this document tracks live, scored risk against those controls, in
the spirit of ISO/IEC 27001 Clause 6.1.2 (risk assessment) and 6.1.3 (risk
treatment). This is process inspiration, not a claim of certification.

Low-severity risks belong here too. A risk scoring low is a reason to treat
it patiently, not a reason to leave it unwritten.

**Scope:** the Wynko plugin runtime, its admin surfaces, and its bundled
integrations (Contact Form 7, HTML Forms). Out of scope: WordPress core,
hosting infrastructure, and third-party plugins/themes beyond the boundary
Wynko itself exposes to them.

## Scoring methodology

- **Likelihood** 1–5, **Impact** 1–5, **Risk score** = Likelihood × Impact.
- Bands: **Low** 1–5 · **Medium** 6–14 · **High** 15–25.
- **Treatment:** Mitigate / Accept / Transfer / Avoid. Most risks here will be
  Mitigate or Accept — Wynko doesn't outsource risk to a third party
  (Transfer) or have features worth dropping outright (Avoid) as a normal
  outcome.
- **Accept requires a sign-off line** (`Accepted by` + date + reason) in the
  entry's detail block. An accepted risk is a decision, not silence — it
  doesn't get to disappear from view.

## How to add an entry

Add a row to the register table below, then a detail block in the matching
Open/Accepted/Resolved section using this shape:

```markdown
## RISK-000 — One-line summary
- **Date:** YYYY-MM-DD · **Area:** <subsystem>
- **Asset/Surface:** What's exposed.
- **Threat:** Who, and what they're trying to do.
- **Vulnerability:** The specific gap, with file:line.
- **Exploit scenario:** Concrete inputs/access → concrete outcome.
- **Existing controls:** What already limits this, if anything.
- **Likelihood / Impact / Score:** N / N / N (band)
- **Treatment:** Mitigate|Accept|Transfer|Avoid — the plan, or the
  acceptance reasoning and sign-off.
- **Review date:** When this gets re-scored.
```

When a risk is resolved, move its row and block to Resolved with the date and
the commit or branch that closed it. Nothing gets deleted.

---

## Register

| ID | Title | Asset/Surface | Threat | Likelihood | Impact | Score | Existing controls | Treatment | Status | Review date |
|---|---|---|---|---|---|---|---|---|---|---|
| RISK-001 | Cross-form Laposta list/field injection | CF7 / HTML Forms bridges | Submitter of a bridged form crafts extra POST fields | 3 | 3 | 9 (Medium) | Per-IP throttle, email validation, required-field abort | Mitigate | Resolved | 2026-11-30 |
| RISK-002 | Pre-throttle field-cache-key churn | CF7 / HTML Forms bridges, `Api\Fields` cache | Submitter varies `list_id` per request to force live API calls | 2 | 2 | 4 (Low) | Per-list transient caching, per-IP throttle (after the fetch) | Mitigate | Resolved | 2026-11-30 |
| RISK-003 | Unescaped third-party integration metadata in system report export | `Admin\SystemReport` plain-text export | Malicious active plugin/theme author | 1 | 2 | 2 (Low) | `esc_html()` on the HTML rendering path (bypassed by this gap) | Mitigate | Resolved | 2026-11-30 |

---

## Open

_None yet._

---

## Accepted

_None yet._

## Resolved

### RISK-001 — Cross-form Laposta list/field injection
- **Date:** 2026-08-30 · **Resolved:** 2026-08-30, `fix/risk-002-003` ·
  **Area:** integrations
- **Asset/Surface:** `ContactForm7Integration::checked_list_id()`/
  `mapped_custom_fields()` (`includes/Integrations/ContactForm7/ContactForm7Integration.php:227-238,278-302`)
  and the equivalent `HtmlFormsIntegration` methods.
- **Threat:** Anyone able to submit a bridged CF7/HTML Forms form appends an
  extra POST field the form's own template never declared.
- **Vulnerability:** Both bridges scan every posted key for the
  `wynko-optin-` prefix and trust whatever `list_id` follows it, and every
  posted `wynko-{custom_name}` key, without checking either was declared on
  the *submitting* form's own template (CF7's `scan_form_tags()` is available
  but not cross-checked). This deviates from `SECURITY.md`'s stated
  invariant: "a form is opted in by containing that tag, not by anything
  saved through this handler."
- **Exploit scenario:** A form with only a `wynko-optin-{list_A}` tag is
  submitted with an extra `wynko-optin-{list_B}=1` field appended by the
  submitter (e.g. via browser devtools or a scripted POST) — the write goes
  to `list_B`, a list the form's admin never intended to expose through that
  form, with any `wynko-{custom_name}` values for `list_B`'s fields also
  accepted.
- **Existing controls:** `Throttle::allows_ip()` still gates the actual
  Laposta write; email validation and required-field checks still apply.
  Independent verification scored the same underlying write only 5/10,
  because an attacker with knowledge of `list_B`'s real opt-in tag can
  already achieve the identical write through the legitimate path — this
  finding grants "any bridged form silently acts as an undisclosed subscribe
  endpoint for any list," not a wholly new write capability. Separately,
  every write is authenticated to Laposta with this site's own configured
  key (`Api\Client::request()`, `includes/Api/Client.php:73-74`, via
  `ApiKey::resolve()`), and Laposta scopes `list_id` to the account that key
  belongs to — so `list_id` tampering, however it's reached, can only ever
  target a list already living in *this site owner's own* Laposta account.
  There is no cross-account write reachable this way.
- **Likelihood / Impact / Score:** 3 / 3 / 9 (Medium) — likelihood is
  moderate (no special access needed, just POST-field knowledge); impact is
  moderate rather than high because the write isn't a new capability and
  can't leave the site owner's own account — it's a same-tenant
  confused-deputy/disclosure problem (a form can silently subscribe to a
  list of the *same* owner's that its own markup never mentions), not a
  cross-tenant data-write problem.
- **Treatment:** Mitigate.
- **Resolution:** Both bridges now build a `declared_field_names()`
  allowlist — CF7 via `scan_form_tags()`, HTML Forms via a markup grep for
  `name="..."` attributes (the TD-067-noted lower-cost approach for that
  bridge) — and `checked_list_id()`/`mapped_custom_fields()` cross-check
  every posted `wynko-*` key against it before accepting a `list_id` or
  custom-field value. A `list_B` opt-in field appended by the submitter but
  never declared on the submitting form's own template is now ignored.
- **Out of scope: Wynko's own form.** `Frontend\FormSubmitHandler::process()`
  (`includes/Frontend/FormSubmitHandler.php:140-146,217`) never reads
  `list_id` or the allowed custom-field set from POST at all — `list_id`
  comes from `FormData::load( $form_id )`'s own post meta and the field
  allowlist from `$form->visible_custom_fields()`, both looked up
  server-side from the submitted `form_id`, so there's no submitted value to
  cross-check in the first place. Its `wynko_nonce` is scoped per form via
  `FormSubmitHandler::nonce_action( $form_id )` (`:69-71`), WP core's own
  salt-backed nonce, which additionally binds a submission to the specific
  form it claims — CF7/HTML Forms have no equivalent because neither
  renders/owns the form markup itself. Not vulnerable to this class; not
  part of this entry's fix.
- **Review date:** 2026-11-30.

### RISK-002 — Pre-throttle field-cache-key churn
- **Date:** 2026-08-30 · **Resolved:** 2026-08-30, `fix/risk-002-003` ·
  **Area:** integrations / caching
- **Asset/Surface:** `Api\Fields::for_list()` (`includes/Api/Fields.php:49-111`),
  reached via both bridges' `maybe_subscribe()` → `mappable_fields()` path
  before `Throttle::allows_ip()` runs
  (`ContactForm7Integration.php:188,197,315`; HTML Forms equivalent at
  `:187,196,291`).
- **Threat:** Submitter of a bridged form, same access level as RISK-001.
- **Vulnerability:** `Fields::for_list()` is cache-first — a repeat
  submission with the *same* `list_id` is served from the transient and
  never re-hits Laposta. But the cache is keyed by `list_id` itself, and
  both bridges pass through the same unvalidated `list_id` from
  `checked_list_id()` (RISK-001's root cause) ahead of the per-IP throttle.
  Varying `list_id` per request is therefore a guaranteed cache miss each
  time.
- **Exploit scenario:** Submitting the bridged form repeatedly with a
  different bogus `wynko-optin-{value}` each time forces one outbound
  Laposta `GET /field` call and one new entry in the shared
  `Config::fields_transient_key()` transient per distinct value, none of it
  metered by `Throttle::allows_ip()` (which only gates the later
  `Subscribers::create()` write).
- **Existing controls:** Per-list negative-TTL caching bounds how long any
  one bogus entry lives; the per-IP throttle still gates the final
  subscribe write, so this doesn't itself produce a Laposta account write.
- **Likelihood / Impact / Score:** 2 / 2 / 4 (Low) — requires deliberately
  varying input per request (more effort than a naive repeat-submit
  script); impact is bounded to outbound-call cost and bounded transient
  growth, not data exposure or a write.
- **Treatment:** Mitigate.
- **Resolution:** Closed as a side effect of the RISK-001 fix, exactly as
  that entry's treatment note predicted: `checked_list_id()` (both bridges)
  now only accepts a `list_id` whose POST key is present in
  `declared_field_names()` — the submitting form's own declared tags/markup.
  A `list_id` that never reaches `mappable_fields()`/`Fields::for_list()`
  can't churn the cache, so varying `list_id` per request is now rejected
  before the cache is ever touched, collapsing the reachable key space to
  the bounded set of lists the form's own template actually declares. No
  separate code change was needed beyond RISK-001's.
- **Review date:** 2026-11-30.

### RISK-003 — Unescaped third-party integration metadata in system report export
- **Date:** 2026-08-30 · **Resolved:** 2026-08-30, `fix/risk-002-003` ·
  **Area:** admin / diagnostics
- **Asset/Surface:** `Admin\SystemReport::text()`/`handle_export()`
  (`includes/Admin/SystemReport.php:133-253`), fed by
  `SystemInfo::integration_rows()`.
- **Threat:** Author of a malicious active plugin or theme registering a
  Wynko integration.
- **Vulnerability:** An uncommitted diff moves integration rendering from
  `Admin/AboutTab.php` (HTML, `esc_html()`-escaped) into
  `SystemInfo::integration_rows()`, whose output now also reaches
  `SystemReport::text()`, joined with `"\n"` and echoed unescaped in the
  `.txt` export (deliberately unescaped there so the download isn't
  corrupted). `Integration::name()`/`author()`/`version()` can originate
  from a third-party plugin/theme; previously these strings only ever
  reached the escaped HTML path.
- **Exploit scenario:** A malicious integration's `name()` includes an
  embedded newline plus a fake `== Section ==` header or `[fail]`/`[warn]`
  marker; a site owner exporting the system report and pasting it into a
  public support thread unknowingly includes the forged line.
- **Existing controls:** Registering an integration already requires being
  an active plugin/theme — the same arbitrary-PHP trust boundary
  `SECURITY.md` invokes elsewhere to downgrade similar issues.
- **Likelihood / Impact / Score:** 1 / 2 / 2 (Low) — requires an already
  highly-privileged attacker position (arbitrary PHP execution via an
  active plugin/theme); impact is limited to a cosmetic/social-engineering
  forged report line, not a data or capability breach.
- **Treatment:** Mitigate.
- **Resolution:** Added `Support\Sanitizer::single_line()` — a pure,
  WordPress-free helper that strips control characters (newlines included)
  from a string — and run `name()`/`author()`/`version()` through it in
  `SystemInfo::integration_rows()` before they become a row's label/note.
  Restores the newline safety the HTML path already had implicitly via
  `esc_html()`, on the plain-text export path that has no equivalent
  escaping. Covered by `SanitizerTest::test_single_line_*` and
  `SystemInfoTest::test_it_strips_embedded_newlines_from_a_third_party_integrations_strings`.
- **Review date:** 2026-11-30.
