# Multisite

The plugin is multisite-compatible.

Settings — the API key, cache duration, activity log, and alert recipients —
are **per-site**, so each site connects to its own Laposta account and mails its
own alerts on its own hourly cap. Transients are per-site for the same reason.

Each site can also have its own API key defined as a constant, by suffixing the
blog ID: see [Storing the key in `wp-config.php`](api-key.md#storing-the-key-in-wp-configphp-recommended).
The same per-site suffix works for [every other setting](configuration.md).

Uninstalling removes the plugin's data — including a database-stored API key —
from every site in the network. A key defined as a constant is never written to
the database and is therefore not removed; delete the constant yourself.

---

Back to the [README](../README.md) · [All documentation](README.md)
