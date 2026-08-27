# Configuring the API key

1. Go to **Wynko → Settings**.
2. The API key field is always shown empty — it is never pre-filled with the
   stored value, and the raw key never appears in the page source once saved.
3. Enter your Laposta API key and click **Save changes**.
   - The plugin makes a live request to the Laposta API to verify the key
     before storing anything.
   - An **invalid** key is rejected: you'll see an inline error such as
     `API key not saved: Invalid API key…`, nothing is stored, and an error
     row is added to the activity log.
   - A **valid** key is accepted: you'll see
     `API key verified — N campaigns loaded.`, the campaign cache is primed,
     and an info row is added to the log.
4. On reload, the key field shows a masked "saved" placeholder
   (`•••••••• (saved — leave blank to keep)`) rather than the key value.
   Leaving the field blank on a subsequent save keeps the existing key.

## Storing the key in `wp-config.php` (recommended)

The API key can be defined as a PHP constant instead of being stored in the
database. A constant always wins over the stored option, and the plugin will
not write a database value that would shadow it.

```php
define( 'WYNKO_API_KEY', 'your-laposta-api-key' );
```

On multisite, each site can have its own key by suffixing the blog ID. The
per-site constant takes precedence over the network-wide one, so you can set a
default and override it for individual sites:

```php
define( 'WYNKO_API_KEY', 'default-key' );       // used by any site without its own
define( 'WYNKO_API_KEY_3', 'site-three-key' );  // used by blog ID 3 only
```

When a constant is in effect, the settings page shows the constant's name
instead of the key field. A key defined this way is never written to the
database and is therefore not removed when the plugin is uninstalled — delete
the constant yourself.

If a key was already saved through the settings page before the constant was
added, that value stays in the database — this is what lets the field work
again if the constant is later removed — but it is inert while the constant
is defined; the constant is always used instead. Leaving the field blank on
**Wynko → Settings** keeps the existing value by design (blank means "no
change"), so it cannot be used to clear it; to remove it and keep the
database clean, run `wp option delete wynko_api_key`.

The **Status** row shows **Connected** or **Not connected** for
whichever key is in effect. The verdict is cached against a fingerprint of the
key, so rotating the key (in the database or in `wp-config.php`) re-checks it
automatically.

---

Back to the [README](../README.md) · [All documentation](README.md)
