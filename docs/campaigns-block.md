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

## The `[wynko_campaigns]` shortcode

The same list can be placed anywhere shortcodes run — a classic-editor post,
a widget, a template — with `[wynko_campaigns]`. It renders through the same
code as the block, so the output is identical. Every attribute is optional
and mirrors a block setting:

| Attribute  | Block setting        | Default    | Values |
| ---------- | --------------------- | ---------- | ------ |
| `count`    | Number of campaigns   | `5`        | `1`–`100` |
| `list`     | List                  | *(all)*    | a Laposta list id |
| `order_by` | Order by              | `date`     | `date`, `subject`, `name` |
| `order`    | Direction              | `desc`     | `asc`, `desc` |
| `label`    | Item label             | `subject`  | `subject`, `date`, `subject_date`, `name`, `name_date` |

For example, the five most recent campaigns sent to one list, oldest first,
labelled with their name and send date:

```
[wynko_campaigns count="5" list="abc123" order="asc" label="name_date"]
```

An unrecognised `order_by`, `order`, or `label` value falls back to its
default rather than causing an error.

---

Back to the [README](../README.md) · [All documentation](README.md)
