# Configuring settings from the environment or `wp-config.php`

Every setting the screens offer — not only the API key — can be supplied by an
environment variable or a `wp-config.php` constant, which is what makes a site's
configuration deployable rather than something clicked in once per environment.
A supplied value wins over whatever is in the database, and the field it
replaces is shown as a read-only note naming the variable in effect.

| Setting | Name |
| --- | --- |
| API key | `WYNKO_API_KEY` |
| Cache duration (minutes) | `WYNKO_CACHE_MINUTES` |
| Activity log detail (`error`, `warning`, `info`) | `WYNKO_LOG_LEVEL` |
| Signup rate-limit window (minutes) | `WYNKO_THROTTLE_WINDOW` |
| Signups per visitor per window | `WYNKO_THROTTLE_IP_MAX` |
| Signups per form per window | `WYNKO_THROTTLE_FORM_MAX` |
| Critical email alerts on/off | `WYNKO_NOTIFY_ENABLED` |
| Alert recipients (comma-separated) | `WYNKO_NOTIFY_EMAILS` |

Precedence, highest first: environment variable for this site
(`WYNKO_CACHE_MINUTES_3` on blog ID 3), environment variable for the network,
the same two as constants, then the stored option. That is the order the API key
already used, applied to everything else.

Values are read the way a deployment writes them: booleans accept `1`, `true`,
`yes`, or `on` (anything else is off), numbers are held to the same bounds the
settings screen enforces, and a value the setting cannot take — an unknown log
level, a word where a number belongs, an exported-but-empty variable — is
ignored rather than applied, so a typo cannot silently reconfigure a site.

Block attributes (how many campaigns to show, how to sort and label them) are
per-block, not site settings, and have no variable.

This version allows **one** signup form per site. Multiple forms are the planned
commercial feature, and the cap is not a setting: no variable, no constant, and
no stored option raises it on a site that is being run.

---

Back to the [README](../README.md) · [All documentation](README.md)
