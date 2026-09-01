# Hooks and filters

Every hook is prefixed `wynko_`. Actions let other code react to something the
plugin just did; filters let it change a value before the plugin uses it. None
of these existed before this release — the plugin previously offered no
extension points of its own, only the WordPress core hooks it consumes.

## API transport

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_api_request_args` | filter | `Api\Client::request()`, before the call to `wp_remote_request()`. |
| `wynko_api_response` | filter | `Api\Client::request()`, on the raw response, before it is decoded. |
| `wynko_api_status_message` | filter | `Api\Client::status_message()`, on the human-readable text for a classified HTTP status. |
| `wynko_api_key` | filter | `ApiKey::resolve()`, on the resolved key. |
| `wynko_api_key_verified` | action | `KeyStatus::verify()`, after a live probe (not a cache hit). Args: key fingerprint (SHA-256, never the key), whether it authenticated. |

```php
// Add a header to every outgoing Laposta request.
add_filter( 'wynko_api_request_args', function ( array $args ) {
	$args['headers']['X-Proxy-Auth'] = 'token';
	return $args;
} );

// Pull the API key from an external secrets manager instead of the database.
add_filter( 'wynko_api_key', function ( string $key ) {
	return my_secrets_manager()->get( 'laposta_key' ) ?: $key;
} );
```

## Campaigns and the campaigns block

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_campaigns` | filter | `Api\Campaigns::all()`, on the normalized list before it reaches the cache and the block. |
| `wynko_campaigns_block_content` | filter | `Blocks\Campaigns::render()`, on the block's final HTML. |
| `wynko_campaigns_synced` | action | `Cache::fill()`, after a full campaigns/lists/fields sync completes successfully. Args: whether it was a manual sync, and counts of new campaigns, lists, and fields. |
| `wynko_cache_busted` | action | `Cache::bust()`, after the campaign/list/field caches are invalidated. |

```php
// Hide campaigns older than 90 days.
add_filter( 'wynko_campaigns', function ( array $campaigns ) {
	$cutoff = time() - 90 * DAY_IN_SECONDS;
	return array_values( array_filter( $campaigns, function ( $c ) use ( $cutoff ) {
		return '' === $c['sent_at'] || strtotime( $c['sent_at'] ) >= $cutoff;
	} ) );
} );
```

## Signup forms

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_form_block_content` | filter | `Blocks\Form::render()`, on the block's final HTML. |
| `wynko_form_fields` | filter | `Forms\FormData::fields()`, on a form's merged field definitions. |
| `wynko_form_submitted_values` | filter | `Frontend\FormSubmitHandler::process()`, on the sanitized submission before validation. |
| `wynko_form_subscriber_data` | filter | `Frontend\FormSubmitHandler::process()`, on the custom-field payload just before it is sent to Laposta. |
| `wynko_subscriber_data` | filter | `Api\Subscribers::create()`, on the request body for *any* code path that writes a subscriber, not only the form UI. |
| `wynko_form_submitted` | action | Fires after a signup is confirmed successful. |
| `wynko_form_submit_failed` | action | Fires when a signup fails to reach Laposta. Args: form id, the `WP_Error`. |
| `wynko_form_redirect_url` | filter | `Frontend\FormSubmitHandler::redirect_url()`, on the URL a visitor is sent to after submitting. |
| `wynko_form_config_saved` | action | `Admin\Forms\FormEditPage::save()`, after an admin saves a form's editor, messages, or settings tab. |

`wynko_form_subscriber_data` fires inside `FormSubmitHandler::process()`,
which is scoped to Wynko's own signup-form CPT — its form-scoped nonce, its
honeypot field, its own `Throttle` counters, its post-submit redirect. A
bridge from another plugin's own form (one that isn't a Wynko form to begin
with, and so has none of that pipeline to plug into) is not this hook's
audience: it belongs on `wynko_subscriber_data` instead, the lower-level
filter on `Api\Subscribers::create()` that fires for *any* code path that
writes a subscriber. The bundled Contact Form 7 and HTML Forms integrations
(`Wynko\Integrations\ContactForm7\ContactForm7Integration`,
`Wynko\Integrations\HtmlForms\HtmlFormsIntegration`) are exactly that case,
and call `Subscribers::create()` directly rather than going through
`FormSubmitHandler::process()` — see [Integrations](#integrations) below for
how they register themselves, and their own source for a worked example.

```php
// Tag every subscriber write with its source, regardless of which code path made it.
add_filter( 'wynko_subscriber_data', function ( array $body, string $list_id, string $email ) {
	$body['source'] = 'my-bridge';
	return $body;
}, 10, 3 );
```

## Integrations

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_register_integrations` | filter | `Integrations\Registry::all()`, on the list of registered integrations. |

Any plugin or theme — including Wynko's own bundled integrations — adds an
object implementing `Wynko\Integrations\Integration` to this filter to
register optional functionality that a site switches on from Wynko →
Integrations. See `Wynko\Integrations\ContactForm7\ContactForm7Integration`
and `Wynko\Integrations\HtmlForms\HtmlFormsIntegration` for complete worked
examples.

```php
// Register a bridge integration from your own plugin.
add_filter( 'wynko_register_integrations', function ( array $integrations ) {
	$integrations[] = new My_Plugin_Wynko_Integration();
	return $integrations;
} );
```

**For integration authors: guard against Wynko being absent.** Your plugin
or theme depends on Wynko, never the other way round — Wynko's core never
requires any third-party plugin to function, and the reverse dependency an
integration takes on is entirely optional for the site running it. That
means your registration code needs to degrade to a no-op, not a fatal error,
on a site where Wynko isn't installed or isn't active. Two ways to do that:

```php
// Either: check the interface exists before implementing it.
if ( interface_exists( '\Wynko\Integrations\Integration' ) ) {
	add_filter( 'wynko_register_integrations', function ( array $integrations ) {
		$integrations[] = new My_Plugin_Wynko_Integration();
		return $integrations;
	} );
}

// Or: register on plugins_loaded, after every plugin (Wynko included) has loaded.
add_action( 'plugins_loaded', function () {
	if ( ! interface_exists( '\Wynko\Integrations\Integration' ) ) {
		return;
	}
	add_filter( 'wynko_register_integrations', function ( array $integrations ) {
		$integrations[] = new My_Plugin_Wynko_Integration();
		return $integrations;
	} );
} );
```

Registering an integration requires being an active plugin or theme — a
trust level that already grants arbitrary PHP execution on the site, so
`wynko_register_integrations` does not grant anything a malicious actor
could not already do by other means. It is not, however, a substitute for
your own integration behaving well: `Integration::name()`, `description()`,
`author()`, `author_uri()`, `documentation_uri()`, `version()`, and
`deactivation_warning()` are rendered escaped on the Integrations screen and
Settings → About regardless of what they return, but everything your own
`render_settings()` prints is your own responsibility to escape, exactly as
it would be on any other admin screen. `version()` is your own plugin's or
theme's version — whatever you'd put in its own header — not Wynko's.
`author_uri()` and `documentation_uri()` are both optional (`''` means no
link) and, when set, open in a new tab.

**`deactivation_warning()`** names, in one sentence, the concrete thing that
stops working when an admin turns your integration off — e.g. "the sign-up
checkbox already pasted into a form will stop subscribing anyone." It is
shown in the confirmation dialog behind the Integrations screen's own
Deactivate link; return `''` to fall back to a generic warning instead of
writing your own.

**Availability and enabled state are two different things.** Your own
`is_available()` should report whether whatever you bridge to (another
plugin, typically) is actually present — Wynko never calls `boot()` unless
both `is_available()` and the site's own enabled setting say yes. If your
dependency disappears while an admin had already switched your integration
on, `Integrations::demote_unavailable()` notices on the next `init` and
turns the stored setting back off on its own — silently keeping "on" would
misreport what is actually running — logs it as an `error` (so `Notifier`
mails the site owner: their form may have just gone quiet without them
choosing that), and queues a one-time admin notice naming your integration
by `name()`, so there is no separate "unavailable but still enabled" state
for your own code to account for. It runs on `init` rather than
`plugins_loaded` specifically because that logging translates a string, and
WordPress does not consider a text domain safe to load any earlier.

## Lists, fields, and sync

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_lists` | filter | `Api\Lists::for_editor()`, on the list options before they are cached. |
| `wynko_fields` | filter | `Api\Fields::for_list()`, on one list's normalized field definitions before they are cached. |
| `wynko_list_gone` | action | `GoneLists::check()`, the first time a list a form still references is found missing from Laposta. Args: list id, its last known name, how many published forms use it. |

## Activity log and alerts

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_log_added` | action | `Log::add()`, after an entry is recorded. Args: level, message. |
| `wynko_notification_recipients` | filter | `Notifier::recipients()`, on the alert email's deliverable addresses. |
| `wynko_notification_sent` | action | `Notifier::maybe_notify()`, after a critical-email alert is sent successfully. |

```php
// Forward every error-level log entry to an external alerting integration.
add_action( 'wynko_log_added', function ( string $level, string $message ) {
	if ( 'error' === $level ) {
		my_alerting_integration()->notify( $message );
	}
} );
```

## Maintenance

| Hook | Type | Fires in |
| --- | --- | --- |
| `wynko_migrations_completed` | action | `Migrations::maybe_run()`, after this site's per-site schema upgrade runs. Args: the schema version just migrated to. |

## What does not get a hook

`includes/Support/*` stays WordPress-free — it carries no hooks, and never
will, because it is unit-tested without WordPress loaded at all. Where the
interesting logic lives in a `Support/` class (`FormValidator`, `SyncDiff`),
the hook sits in the WordPress-facing caller instead, as noted in the tables
above.

---

Back to the [README](../README.md) · [All documentation](README.md)
