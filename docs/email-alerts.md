# Critical email alerts

**Wynko → Settings → Notifications** turns the log into something that reaches
you without your going to look. When the plugin records an `error` — and only an
error; warnings and informational entries never send — it emails the addresses
you list there.

The feature ships switched off with no recipients, so an update never starts
sending mail on its own. A deployment that supplies the recipients through
`WYNKO_NOTIFY_EMAILS` has opted in by doing so: the alerts switch on, and the
checkbox shows as on and is not editable until that variable goes away. Set
`WYNKO_NOTIFY_ENABLED` as well to say otherwise — the switch's own variable
outranks this, so `WYNKO_NOTIFY_ENABLED=0` means recipients ready, sending off. Recipients are typed addresses separated by commas, up
to ten of them; anything that is not an address is dropped when you save, and
the page says which. There is no user picker: the alert has to be sendable from
a request with no logged-in user behind it — a visitor's signup, a scheduled
refresh — so the addresses are stored rather than resolved from whoever is
looking.

**At most one alert per hour, per site.** Errors that happen while that hour is
running are recorded in the activity log as usual but are not mailed, and the
alert says so and links to the log. This is a deliberate cap rather than a
digest: the mail tells you to go and look, and the log is what you look at.

The mail is plain text and carries the site, the time, the error message, and a
link to the activity log. It never contains your API key or any part of it.

**Send test email** mails the current recipients right away. It ignores the
hourly cap and does not consume it, so testing your configuration can never
suppress a real alert. If a test arrives nowhere, the problem is the site's mail
configuration rather than the plugin — WordPress hands the message to `wp_mail()`
and the plugin does not configure a transport.

---

Back to the [README](../README.md) · [All documentation](README.md)
