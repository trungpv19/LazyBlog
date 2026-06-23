# Badges — Operator Flex on `/about`

The `/about` page shows a BADGES grid derived entirely from the
published-post set — no DB, no admin clicks. This doc covers how to
customise the catalogue.

## How it works

Badges are split into two tiers:

- **Volume** — always rendered. Locked entries show `N/M` progress and
  dim out; unlocked entries glow phosphor and surface the unlock date.
- **Hidden** — only rendered after they unlock. Locked hidden badges
  never appear in the HTML response, so the surprise factor survives a
  "view source" peek.

Each badge is declared in a JSON file. The compute logic lives in PHP
"kinds" — small reusable templates — so adding a new badge of an
existing pattern is just a new JSON entry, not a code change.

## Catalogue location

The badge catalogue lives at **`content/badges.json`** (allowlisted in
`.gitignore` so it ships with the repo). LazyBlog comes with 10 volume
badges and 8 hidden badges out of the box. Edit the file in place to
customise — there is no separate "default" path, this single file is
the source of truth.

If the file is missing or unreadable the BADGES panel is silently
omitted from `/about`; nothing else breaks.

```json
{
  "volume": [
    {
      "code": "BROADCAST-X10",
      "label": "BROADCAST ×10",
      "description": "10 published transmissions",
      "kind": "post-count",
      "params": { "threshold": 10 }
    }
  ],
  "hidden": [
    {
      "code": "NIGHT-OWL",
      "label": "NIGHT OWL",
      "description": "Transmission between 00:00 and 04:00",
      "kind": "time-window",
      "params": { "hourFrom": 0, "hourTo": 4 }
    }
  ]
}
```

Per-entry fields:

- `code` — uppercase kebab identifier, shown in the badge tile
- `label` — human-readable name, e.g. `BROADCAST ×10`
- `description` — one-line explanation under the badge
- `kind` — one of the kinds below
- `params` — kind-specific parameters

Hidden time-based kinds (`time-window` with restrictive hours, etc.)
only count posts whose frontmatter `date:` carries an explicit ISO
datetime (`2024-03-15T02:30:00+07:00`). Legacy `YYYY-MM-DD` posts have
no wall-clock and can't trigger NIGHT-OWL etc.

## Available kinds

| Kind | What unlocks | Params |
|------|--------------|--------|
| `post-count` | N or more published posts | `threshold` (int) |
| `longest-post-words` | Any single post has ≥N words | `threshold` (int, default 5000) |
| `body-pattern-count` | Any single post has ≥N markdown elements of a pattern | `threshold` (int), `pattern` (`"image"`/`"code-block"`/`"external-link"`/`"blockquote"`) |
| `series-min-parts` | First series has ≥N published parts | `threshold` (int) |
| `tag-count` | Any single tag has ≥N posts | `threshold` (int) |
| `blog-age-days` | Blog age (today − first post) ≥ N days | `threshold` (int) |
| `longest-streak` | Longest streak ≥ N consecutive periods | `threshold` (int), `unit` (`"day"`/`"week"`/`"month"`/`"year"`, default `"week"`) |
| `time-window` | Post published within hour window | `hourFrom`, `hourTo` (0–24), optional `dayOfWeek` (1=Mon..7=Sun) |
| `gap-days` | Post after ≥N days since previous post | `threshold` (int) |
| `same-day-count` | ≥N posts on the same calendar day | `threshold` (int) |
| `anniversary` | Post on the same month-day as the first post | (none) |
| `date-suffix` | Post date ends with a specific `-MM-DD` | `mmDd` (string, e.g. `"-02-29"`) |

Entries with an unrecognised `kind` are skipped and logged to PHP error
log — they don't break `/about`. Mistyped `params` keys fall back to
sensible defaults where possible (e.g. `post-count` defaults to
`threshold: 1`).

## Streak badges

The `longest-streak` kind reads its own `unit` param per badge — there
is no global cadence setting. Available units:

- `day` — ≥1 published post per calendar day
- `week` — ISO Mon–Sun week (default if `unit` is omitted)
- `month` — calendar month (`Y-m`)
- `year` — calendar year (`Y`)

One published, non-draft post per period keeps the run alive; gaps
break it. The badge stores the longest consecutive run ever achieved.

```json
{
  "code": "DAILY-X30",
  "label": "DAILY STREAK (30 days)",
  "description": "Longest streak ≥ 30 consecutive days",
  "kind": "longest-streak",
  "params": { "threshold": 30, "unit": "day" }
}
```

Multiple streak badges with different units coexist freely — declare
as many as you want. The calculator memoises the longest run per unit
so 5 weekly streak badges only walk the post set once.

Period boundaries use the site `TIMEZONE` env (default UTC).

**Note on the `STREAK_UNIT` env:** it's reserved for any future
standalone "current streak" indicator (not yet implemented). Badges
are NOT affected by that env — each one carries its own `unit` param.

## Adding a brand-new pattern

If you want a badge whose logic doesn't fit any existing kind, you'll
need to add a new executor:

1. Add a method to `src/Badges/BadgeKinds.php` returning a closure that
   matches the signature documented at the top of that file.
2. Register it in `BadgeKinds::executors()`.
3. Declare badges of the new kind in your `content/badges.json`.

Keep new kinds tight in scope — each one should be parameterised by a
small param dict, not by arbitrary expressions. Anything more dynamic
than that belongs in code, not config.
