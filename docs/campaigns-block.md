# The campaigns block

1. In the block editor, add the **Wynko: Campaigns** block (search "Wynko"
   in the block inserter).
2. In the block's Inspector panel, set **Number of campaigns** (1–100) and,
   optionally, a **List** to show only that list's campaigns.
3. Still in the Inspector, choose **Order by** (Date sent, Subject, or
   Campaign name), a **Direction** (Newest/Oldest first for dates, A–Z/Z–A for
   the other two), and an **Item label** — Subject, Date sent, Subject and date
   sent, Campaign name, or Campaign name and date sent.
4. The editor preview lists the most recent sent campaigns using the same
   server-rendered markup shown on the front end.
5. On the front end, the block renders a plain `<ul>` of campaign links.
   Each link opens in a new tab (`target="_blank"`) with
   `rel="noopener noreferrer"`. Only campaigns that have actually been sent
   (i.e. have a `web` URL) are shown. The most recent are selected first, then
   arranged according to **Order by** and **Direction**; campaigns Laposta
   reports no delivery date for are listed last.

---

Back to the [README](../README.md) · [All documentation](README.md)
