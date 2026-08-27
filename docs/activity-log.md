# Activity log

The **Activity log** screen under the Wynko menu records what the plugin has
been doing: API key verification results, connection checks, campaign syncs
(both the ones you ask for and the ones that happen on their own when the cache
expires), and signup submissions. Each row carries a timestamp, a level
(`info`, `warning`, or `error`), and a message. The log is capped at 200 entries
and trimmed automatically so it doesn't grow unbounded.

**Levels.** `error` is a failure — a key Laposta rejected, a sync that did not
complete, a signup that never reached Laposta. `warning` is something to notice
that isn't a failure: an unreachable API, a sync that succeeded against an empty
account, a visitor whose signup failed validation. `info` is routine progress.

**How much gets recorded** is up to you: **Settings → Activity log detail**
offers *Everything*, *Warnings and errors*, or *Errors only*. The setting
applies when an event happens, so it decides what is written rather than what is
shown — turning it down does not hide entries already stored, and turning it
back up does not recover events that were skipped. The log screen always says
which level is currently being recorded, and links straight to the setting.

**Filtering.** Narrow the table by level, by a word in the message, or both.
The count line above the table reports how much of the log you're looking at.

**Download log (.txt)** exports what you're currently looking at — the same
filter, the same rows — with a short header naming the site, the plugin,
WordPress and PHP versions, and the filter that was applied. It is meant to be
attached to a support request. It never contains your API key. Signup entries
name the form and the outcome and never the submitted email address or field
values, so the file is safe to share.

**Clear log** empties the log for the current site.

---

Back to the [README](../README.md) · [All documentation](README.md)
