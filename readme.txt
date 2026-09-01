=== Wynko for Laposta ===
Contributors:      roydg
Tags:              laposta, newsletter, email marketing, signup form, campaigns
Requires at least: 6.4
Tested up to:      7.1
Requires PHP:      8.0
Stable tag:        1.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Wynko – Campaigns & Sign-Up Forms for Laposta

== Description ==

Wynko connects your WordPress site to your Laposta account, then gets out of the way.

Once you've entered your API key, you get two things you can drop anywhere on your site: a **signup form** that adds people straight to one of your Laposta lists, and a **campaigns list** that shows the newsletters you've most recently sent.

No embed codes. No iframes. Just your own site, with your own styling.

= Signup forms that can't get out of sync =

Pick a Laposta list, and Wynko builds the form from that list's own fields. If Laposta says a field is required, it's required here too. If a dropdown has five options in Laposta, it has those same five options on your site. You can't accidentally build a form that Laposta will reject, because the form is always built from what Laposta actually accepts.

Wynko ships only the bare structural CSS. Colours, fonts and spacing come from your theme, and you can adjust them with CSS custom properties if you want to fine-tune.

= Subscribe from forms you already have =

Wynko also bridges other form plugins to Laposta. Bundled integrations for **Contact Form 7** and **HTML Forms** — off until you switch one on under **Wynko → Integrations** — let an existing form subscribe people to a list by adding a single checkbox. Any plugin or theme can register its own integration the same way, so the list isn't limited to those two.

= Campaigns block =

Add the **Wynko: Campaigns** block to any post or page and it shows a simple list of links to your most recently sent campaigns. Choose how many to show, which list they come from, the order, and what each line says. There's no front-end CSS at all here — your theme styles it completely.

= Your API key, handled carefully =

Your Laposta API key is the key to your whole mailing list, so Wynko treats it carefully.

* **It's checked before it's saved.** Wynko asks Laposta whether the key works before storing it. A wrong key is rejected right away instead of quietly failing later.
* **It's never shown back to you.** Once saved, the key isn't printed into the settings page, so it can't be read off your screen or pulled out of the page source.
* **It's encrypted at rest, when your server allows it.** If the `sodium` PHP extension is available (bundled with PHP since 7.2, so almost always) and your site has real WordPress security salts, the key is sealed with authenticated encryption before it's written to the database — a raw database export or a SQL-injection leak doesn't hand over a usable key. See the FAQ for exactly what this does and doesn't protect against.
* **You can keep it out of your database entirely.** This is the safest option, and the one we recommend. Add one line to your `wp-config.php` file and the key lives there instead:

`define( 'WYNKO_API_KEY', 'your-laposta-api-key' );`

A key defined this way never touches your database, so it can't leak through a database backup, a stray export, or a database-level breach. If a key is defined in `wp-config.php`, it always wins — Wynko won't let a saved value quietly override it.

* **It never shows up in the log.** The activity log records what happened, never your key.

Prefer environment variables? Every Wynko setting, not just the key, can come from an environment variable or a `wp-config.php` constant — see the FAQ for the full list. That means staging and production can each have their own configuration, deployed with your code, instead of someone remembering to click through the settings screen on every site.

= Built-in spam and abuse protection =

Signup forms are public by nature, so every submission is checked and metered before anything reaches Laposta:

* **Rate limiting, per visitor and per form.** Submissions are counted over a rolling time window. Once a cap is hit, further submissions are turned away until the window passes; nothing is sent to Laposta, and nothing is lost, since a genuine visitor can simply try again once it does. Both caps and the window are adjustable, and the counters can be reset instantly.
* **A hidden honeypot field** catches simple bots: if it's filled in, the visitor sees the ordinary success message, but nothing is actually sent to Laposta.
* **Every value is re-validated on your server**, against the list's real field definitions in Laposta — required fields, allowed choices, number and date ranges, text length and patterns. What your browser enforces is a convenience for real visitors; what Wynko enforces server-side is what actually decides whether a signup goes through.
* **"Already subscribed" stays private by default.** Telling an anonymous visitor that an address is already on the list turns a form into a way to test whether someone is subscribed. Wynko shows the same success message either way, unless you turn that off for a specific form.

= Keeping an eye on things =

* **Activity log** — see key checks, connection checks, syncs and signups, at whatever level of detail you want. Filter it on screen, or download it as a `.txt` to attach to a support request. It never contains your API key, and signup entries never contain anyone's email address or answers.
* **Email alerts** — get an email when something goes wrong, at most once an hour so your inbox stays sane. Switched off by default.
* **System report** — a quick health check of WordPress, PHP, your database, PHP modules and your server, each flagged against what Wynko is tested with. It warns you about anything unusual, but never blocks you. Downloadable, again, for support.
* **Multisite friendly** — each site in the network has its own settings, its own Laposta account, its own log and its own alerts. Uninstalling cleans up after itself across the whole network.

= Source code and contributing =

Development happens at https://github.com/FlexBorder/wynko — issues and pull requests are welcome.

Laposta is a trademark of its respective owner. This plugin is developed independently by FlexBorder Co., Ltd with Laposta's permission and is not an official Laposta product.

== External services ==

This plugin connects to the Laposta API (https://api.laposta.nl), a third-party email marketing service. Laposta is the whole point of the plugin: it is how signup forms add subscribers to your mailing lists and how the campaigns block shows what you've sent.

Wynko contacts Laposta:

* **When you save or verify your API key** — your API key is sent to confirm it works before it's stored.
* **When campaign data is fetched or refreshed** — to list your most recently sent campaigns.
* **When a visitor submits a signup form** — the email address and the other field values that visitor typed in are sent to Laposta, along with the visitor's IP address and the path of the page they submitted from, so the subscriber can be added to the chosen list. Nothing else about the visitor is sent, and nothing is sent at all unless a visitor actually submits the form. A signup made through a bundled integration sends the same kind of data for the same reason.

Wynko doesn't store signups on your own site, and its own server-side code makes no request to any host other than Laposta's.

Laposta's terms of service: https://www.laposta.nl/en/terms-and-conditions
Laposta's privacy policy: https://www.laposta.nl/en/privacy-statement

The admin screens also show a handful of outbound links you may click, which your browser — not Wynko — then requests: Laposta's own help article on getting an API key (docs.laposta.org) and list-management page (app.laposta.nl); WordPress core's reference docs on security salts (developer.wordpress.org); the plugin's own documentation site, linked from its row on the Plugins screen (getwynko.com); and, on the About screen, a link to another plugin's page on the official directory (wordpress.org). None of these run unless you click them, and none of them are third-party services Wynko itself connects to.

== Installation ==

1. Install Wynko from **Plugins → Add New**, or upload the ZIP under **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Wynko → Settings** and paste in your Laposta API key. Wynko checks it with Laposta before saving anything, so you'll know immediately if it's wrong.
4. Add the **Wynko: Campaigns** block to a post or page, or build a form under **Wynko → Signup forms** and place it with the form block or the `[wynko_form id="N"]` shortcode.

**Extra credit:** instead of pasting your key into the settings screen, put it in `wp-config.php` (see the FAQ below). It's a little more effort once, and your key stays out of your database for good.

== Frequently Asked Questions ==

= Where do I find my Laposta API key? =

Laposta explains it here: https://docs.laposta.org/article/947-how-do-i-get-an-api-key

Paste the key into **Wynko → Settings**. Wynko makes a live check with Laposta before saving, so an invalid key is caught straight away.

= What's the safest way to store my API key? =

Put it in your `wp-config.php` file rather than in the settings screen:

`define( 'WYNKO_API_KEY', 'your-laposta-api-key' );`

Why this is better: your database gets backed up, exported, copied to staging sites, and is the first thing an attacker goes looking for. A key in `wp-config.php` isn't in any of that. It also means the key travels with your deployment rather than being re-entered by hand on every environment.

Two things to know:

* A key defined this way always takes priority, and Wynko won't save a database value that would shadow it.
* Because it isn't in the database, uninstalling Wynko won't remove it. Delete the line from `wp-config.php` yourself when you're done.

On multisite, add the blog ID to give one site its own key — for example `WYNKO_API_KEY_3`.

= How is my API key encrypted when it's stored in the database? =

When your server allows it: if the `sodium` PHP extension is available (bundled with PHP since 7.2, so almost every host has it) and your site has real `SECURE_AUTH_KEY`/`SECURE_AUTH_SALT` values in `wp-config.php` — the ones WordPress itself generates, and the same ones you'd rotate as part of an incident response — Wynko seals the key with authenticated encryption (libsodium's secretbox) before writing it to the database. A raw database export, a SQL-injection leak, or an administrator browsing the options table doesn't hand over a usable key.

**What this protects against:** exposure of the database on its own — a leaked backup, a stray export, a SQL-injection read.

**What it doesn't protect against:** anyone who can also read `wp-config.php`. Your security salts live there, right alongside your database credentials, so someone with filesystem access already has everything needed to open the key. For that level of protection, keep the key out of the database entirely with the `WYNKO_API_KEY` constant or environment variable described above.

If you rotate `SECURE_AUTH_KEY` — standard practice after a suspected leak — every previously sealed key becomes unreadable on purpose. Wynko treats that exactly like no key being configured (it will never send garbage to Laposta), and the settings screen tells you plainly what happened so you can re-enter it.

If your server has no `sodium` extension, or your site is still running WordPress's placeholder salts, the key is stored as plain text and the settings screen says so.

= Can I use environment variables instead? =

Yes. Every setting below can come from an environment variable (your `.env` file, web server config, or container definition) or a `wp-config.php` constant. An environment variable always outranks a constant, and a constant always outranks whatever is saved on the settings screen. On multisite, suffix the blog ID to override one site only — for example `WYNKO_API_KEY_3` or `WYNKO_THROTTLE_WINDOW_3` — otherwise the value applies network-wide.

* `WYNKO_API_KEY` — the Laposta API key (see above).
* `WYNKO_CACHE_MINUTES` — how long campaign data is cached before Laposta is asked again. Default: 60.
* `WYNKO_LOG_LEVEL` — the lowest severity recorded in the activity log: `error`, `warning`, or `info`. Default: `info`.
* `WYNKO_THROTTLE_WINDOW` — the signup rate-limit window, in minutes. Default: 10.
* `WYNKO_THROTTLE_IP_MAX` — signups one visitor may submit per window, across all your forms. Default: 15.
* `WYNKO_THROTTLE_FORM_MAX` — signups one form may take per window, from every visitor combined. Default: 400.
* `WYNKO_NOTIFY_ENABLED` — whether critical-error email alerts are on. Default: off.
* `WYNKO_NOTIFY_EMAILS` — comma-separated addresses that receive those alerts.

Wherever a setting is supplied this way, its tab on the settings screen shows it as read-only and names the variable or constant in charge, so it's never ambiguous where a value came from.

= How does Wynko stop spam and abuse on signup forms? =

A few layers, all before anything reaches Laposta:

* A hidden honeypot field — a bot that fills it in is shown the normal success message, but nothing is actually sent to Laposta.
* Rate limiting per visitor and per form, over a rolling window (see the next question for the defaults and how to tune them).
* Full server-side validation against the list's real fields in Laposta, regardless of what a script sends — required fields, allowed choices, value ranges, text length and patterns.
* A security token scoped to each individual form, checked before anything else in the submission is read.
* Identical responses to a forged token, an unknown form, and a rate-limited request, so a script probing the endpoint can't learn which check it failed.
* "Already subscribed" answered the same as a new signup by default, so the form can't be used to check whether a given address is on your list.

= What are the default signup rate limits, and should I change them? =

By default, one visitor can submit up to 15 signups, and one form can accept up to 400 signups in total, within any rolling 10-minute window. All three numbers live on the Security tab and can be changed — the per-visitor and per-form caps go up to 1000 and 100,000 respectively, and the window up to 24 hours.

Raise the per-visitor cap if a shared office, school, or NAT gateway sends real visitors from a single address — that's the most common reason a legitimate visitor gets turned away. Treat the per-form cap as a backstop rather than a first line of defense: keep it well above your form's real traffic, because a form that reaches it turns away every visitor, real or not, until the window passes. If a limit ever locks out real visitors before you've had a chance to raise it, use "Reset signup limits" on the same tab to clear the counters immediately.

= Does it work on multisite? =

Yes. Every site keeps its own settings, connects to its own Laposta account, keeps its own log, and sends its own alerts on its own hourly limit.

= What does Wynko store about my visitors? =

Nothing beyond what it passes to Laposta. Signups aren't saved on your site. The activity log notes that a form was submitted and whether it worked, and names the form — but never the email address or anything else the visitor typed.

= How many signup forms can I have? =

As many as you need. Each is bound to its own Laposta list and has its own fields, messages and settings.

= Can I subscribe people from a form built in another plugin? =

Yes. Wynko bundles integrations for Contact Form 7 and HTML Forms, switched off until you enable one under **Wynko → Integrations**. Add a checkbox to your existing form and accepted submissions are subscribed to the Laposta list you choose. The system is open, so any plugin or theme can add support for another form plugin. Integrations you didn't get from Wynko are supported by whoever wrote them.

= Will it slow my site down? =

No. Campaign data is cached for 60 minutes by default (you can change this), so a page with the campaigns block on it isn't calling Laposta every time someone visits.

= Can I style the forms to match my theme? =

Yes. Wynko only ships the minimum layout CSS and leaves colours, fonts and spacing to your theme. CSS custom properties are there if you want more control. The campaigns block ships no front-end CSS at all.

== Changelog ==

= 1.1.0 =
* New: bundled Contact Form 7 and HTML Forms integrations — subscribe people to a Laposta list from a form you already have by adding a single checkbox. Off by default; enable under Wynko → Integrations.
* New: Integrations admin screen, plus a wynko_register_integrations filter so any plugin or theme can bridge another form plugin to Laposta.
* New: developer hooks and filters across the signup and activity-log flow — see the Hooks and filters documentation.
* Signup forms: Wynko now warns, on the settings screen and in the activity log, when a page or CDN cache is serving a stale copy of a form, and cross-checks every submitted field against the live form template.
* Signup forms: hardened for cached pages — a no-JS fallback and automatic nonce refresh keep submissions working when the page is served from cache.
* Security tab: rate-limiting controls reorganised — the toggle moved, per-field caps nested under it, clearer warnings.
* System Report: now detects page-cache and CDN layers and reports the signup-form security posture.
* Fixed: notification recipients could be dropped when the address list went over the 10-address cap.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
