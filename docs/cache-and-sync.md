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

---

Back to the [README](../README.md) · [All documentation](README.md)
