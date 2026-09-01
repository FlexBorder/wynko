# Cache duration and manual sync

- **Cache duration (minutes)** controls how long fetched campaign data is
  cached in a WordPress transient before the next page load triggers a
  re-fetch (default: 60 minutes). Change the value and click **Save changes**
  to persist it.
  The last refresh is shown under the field, so how stale the data is allowed
  to get and how stale it currently is read together.
- **Sync now** immediately busts the cache and re-fetches campaigns from
  Laposta, regardless of the configured cache duration. It is named for the
  fetch rather than for the flush because it does both: flushing alone would
  leave the screens with no data and nothing said about why. Success or failure is
  shown as an admin notice, and "Sync started" / "Sync succeeded" (or
  "Sync failed") rows are added to the activity log, including a note when
  new campaigns are detected. Refreshes that happen on their own when the cache
  expires are logged too, worded as "Automatic sync" so you can tell them apart.

## If the site also runs a page-caching plugin or CDN

This cache duration and a page-caching plugin (WP Super Cache, WP Rocket,
W3 Total Cache, LiteSpeed Cache, a CDN's edge cache) are separate layers and
don't conflict — one stores fetched Laposta data, the other stores rendered
page output. What they can do is *stack*: after **Sync now** or an
automatic refresh, Wynko's own data is fresh immediately, but a front-end
page showing it (the campaigns block, a signup form's field list) that is
also served from a page cache keeps showing the older version until that
separate cache entry expires or is purged on its own schedule. If a change
doesn't seem to show up right after a sync, this is usually why.

To have a page-caching plugin purge automatically on a sync, hook
`wynko_campaigns_synced` (fires after every sync, manual or automatic) or
`wynko_cache_busted` (fires the moment the cache is cleared, before the
refetch) and call that plugin's own purge function — for example:

```php
add_action( 'wynko_cache_busted', 'w3tc_flush_all' );          // W3 Total Cache
add_action( 'wynko_cache_busted', 'wp_cache_clear_cache' );    // WP Super Cache
add_action( 'wynko_cache_busted', 'rocket_clean_domain' );     // WP Rocket
```

A signup form catches one instance of this stacking on its own: see
["Caching plugins and CDNs"](signup-forms.md#caching-plugins-and-cdns) for
the quiet activity-log signal it leaves when a rendered form falls behind
Wynko's own field data.

---

Back to the [README](../README.md) · [All documentation](README.md)
