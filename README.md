# BongCalendar

A multi-tenant calendar app: users create workspaces, keep personal calendars inside them,
share those calendars with teammates, and invite people to events.

Two front doors onto the same domain layer:

- **JSON API** at `/api/v1`, authenticated with JWT (`php-open-source-saver/jwt-auth`).
- **Livewire dashboard** at `/dashboard`, authenticated with the session guard.

Both go through the same services (`app/Services`) and policies (`app/Policies`), so
permission rules cannot drift between them.

## Stack

| | |
|---|---|
| Framework | Laravel 13 (PHP 8.3+) |
| Database | MySQL 8+ |
| Dashboard | Livewire 4 (single-file components) + Tailwind 4 |
| API auth | JWT bearer tokens |

## Setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
php artisan jwt:secret

mysql -u root -e "CREATE DATABASE bong_calendar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# set DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env

php artisan migrate --seed
npm run build      # or: npm run dev

php artisan serve
```

Sessions are configured to last until the user signs out: `SESSION_LIFETIME` is
30 days and both JWT TTLs are `null`. See [Auth](#auth) for what that means.

Attachments are capped at 5 MB, but **PHP discards a larger upload before Laravel
sees it** — the stock `upload_max_filesize` is 2M, and a 3 MB file would fail
with an unhelpful "the file failed to upload". Give PHP some headroom:

```ini
; php.ini
upload_max_filesize = 8M
post_max_size = 12M
```

The editor quotes whichever limit is actually in force
(`Attachment::effectiveMaxKilobytes()`), so it never promises 5 MB on a server
that will not carry it.

Want something to look at? A second seeder fills the workspace with a worked
example — four departments with people in them, a day of tasks carrying real
checklists and priorities, and two reports already written:

```bash
php artisan db:seed --class=SampleDataSeeder
```

It is deliberately separate from `db:seed`, and safe to re-run.

Seeded logins (password `password` for all):

| Email | Role |
|---|---|
| `rady@example.com` | Owner of *Acme Team* and *Side Projects* |
| `sophea@example.com` | Admin in *Acme Team*, has an edit-share and a pending invite |
| `dara@example.com` | Outsider, in their own workspace only |

## Data model

```
tenants ─┬─< tenant_user >─┬─ users
         │                 │
         └─< calendars ─┬──┴─< calendar_shares (view | edit | manage)
                        │
                        └─< events ─┬─< event_invitations
                                    └─< event_reminders
```

- **Tenant** (workspace) is the isolation boundary. Every calendar and event carries a `tenant_id`.
- **Calendar** has a `visibility` of `private` (only explicit shares), `tenant` (all workspace
  members can read) or `public`.
- **Access to a calendar** resolves to one of `manage` (owner, or a `manage` share), `edit`,
  `view`, or none — see `Calendar::permissionFor()`.
- **Activity** is the audit trail: one row per thing that happened to a task or a report — who
  did it, when, and what changed. Polymorphic, because the question is the same whatever is
  being asked about. See the API's [audit trail](#audit-trail).
- **Event times are stored in UTC.** The `timezone` column records the wall-clock zone the event
  was authored in, so the original local time survives a DST or zone change.

## API

All routes are prefixed `/api/v1`. Protected routes take `Authorization: Bearer <token>`.
Routes below the workspace boundary also accept `X-Tenant: <workspace-slug>` to act on a
workspace other than the caller's active one; without it the user's `current_tenant_id` is used.

### Auth

| Method | Route | Notes |
|---|---|---|
| POST | `/auth/register` | Creates the user, a starter workspace and a default calendar. Returns a token. |
| POST | `/auth/login` | Returns a token. |
| POST | `/auth/refresh` | Exchanges the current token for a fresh one. Optional — see below. |
| POST | `/auth/logout` | Revokes the token. The only thing that ends a session. |
| GET | `/auth/me` | Current user with their workspaces. |

**Sessions last until sign-out.** `JWT_TTL` and `JWT_REFRESH_TTL` are both
`null`, so an issued token carries no `exp` claim and never ages out — a client
holds the token it was given and `expires_in` comes back as `null`, meaning
"don't schedule a refresh against this". `/auth/refresh` still works, on a token
of any age, for clients that would rather rotate than keep one forever.

Signing out is what ends it: `POST /auth/logout` adds the token to the JWT
blacklist, which handles a token with no expiry by revoking it permanently. The
browser works the same way — sign-in always sets a remember-me cookie, and
`Auth::logout()` cycles the token behind it.

Two things worth knowing about that trade:

- **A leaked token is valid until someone signs that device out.** There is no
  expiry to save you. If a device is lost, sign out on it — or change the user's
  password and rotate `JWT_SECRET`, which invalidates every token at once.
- **The blacklist lives in the cache store** (`CACHE_STORE=database`, so it
  survives restarts). `php artisan cache:clear` wipes it, which would make every
  previously signed-out token valid again. Clear tags or specific keys instead of
  flushing the whole cache on a running system.

`config/jwt.php` carries two edits that make this work: `ttl`/`refresh_ttl`
preserve `null` through the cast (`(int) null` is `0`, which would expire every
token instantly), and `exp` is off `required_claims`, since the validator would
otherwise reject the very tokens it is asked to issue.

### Workspaces

| Method | Route |
|---|---|
| GET / POST | `/workspaces` |
| GET / PATCH / DELETE | `/workspaces/{tenant}` |
| POST | `/workspaces/{tenant}/switch` |
| GET / POST | `/workspaces/{tenant}/members` |
| PATCH | `/workspaces/{tenant}/members/{user}` |
| DELETE | `/workspaces/{tenant}/members/{user}` |

**Roles.** Every membership is `owner`, `admin` or `member`. Admins may invite
and remove people; only the **owner** may change what someone is allowed to do —
`PATCH /workspaces/{tenant}/members/{user}` with `{"role": "admin" | "member"}`.
That ability is held tighter than the rest on purpose: an admin who could
appoint admins could promote themselves past the owner.

The owner's own role is not assignable through it. A workspace has exactly one
owner, and handing that over is a different decision from granting admin
rights — so `owner` is rejected as a role, and the owner's row is refused.

### Departments (tenant-scoped)

| Method | Route | Notes |
|---|---|---|
| GET / POST | `/departments` | |
| GET / PATCH / DELETE | `/departments/{department}` | Admin-only to change. |
| POST | `/departments/{department}/calendars` | Move a calendar in, or out of every department. |
| GET / POST | `/departments/{department}/members` | POST takes `user_id` and an optional `role` of `lead` or `member`. |
| DELETE | `/departments/{department}/members/{user}` | |

**Department membership scopes work; it does not gate it.** Everyone in the
workspace still sees every department, its tasks and its reports — membership
decides where new work lands and what a page leads with. `lead` carries no extra
permission either; it only breaks the tie when someone belongs to more than one
department.

**Assigning a task files it.** A task handed to someone who works in a
department is filed under that department — the one they lead if they are in
several. It only ever fills a blank: a department chosen deliberately outranks
the assignee's default, and reassigning never moves a task that already has a
home. The rule lives in a `saving` hook on `Task`, because tasks are created
from four places (API, board, template, recurrence) and three would have been
easy to miss.

### Calendars (tenant-scoped)

| Method | Route |
|---|---|
| GET / POST | `/calendars` |
| GET / PATCH / DELETE | `/calendars/{calendar}` |
| GET / POST | `/calendars/{calendar}/shares` |
| DELETE | `/calendars/{calendar}/shares/{user}` |

### Events & invitations (tenant-scoped)

| Method | Route | Notes |
|---|---|---|
| GET | `/events` | `?from=&to=&calendar_ids[]=`. Defaults to the current month. Returns events **overlapping** the window, so multi-day events that began earlier are included. |
| POST | `/events` | Requires `edit` or `manage` on the target calendar. |
| GET / PATCH / DELETE | `/events/{event}` | |
| GET | `/invitations` | The caller's invitations; `?status=pending`. |
| POST | `/events/{event}/respond` | `{"status": "accepted" \| "declined" \| "tentative"}` |

### Notes (tenant-scoped)

The workspace noticeboard. A note is shared with the whole workspace by
default (`visibility: tenant`); its author can keep it to themselves with
`private`, and admin rank does not open a private note.

| Method | Route | Notes |
|---|---|---|
| GET | `/notes` | Shared notes plus the caller's own private ones, pinned first. `?q=` searches title and body, `?mine=1` narrows to the caller, `?pinned=1` to pinned. Paginated. |
| POST | `/notes` | `body` is required; `title`, `color`, `visibility` and `is_pinned` are optional. |
| GET / PATCH / DELETE | `/notes/{note}` | Editing is the author's, or an admin's on a *shared* note. Only the author changes `visibility`. |
| POST | `/notes/{note}/pin` | Toggles, or takes `{"is_pinned": true\|false}`. |
| POST | `/notes/{note}/attachments` | `multipart/form-data` with `file`. |
| GET / DELETE | `/attachments/{attachment}` | Streams or removes one file. |

The report editor shows that department's tasks for the day — status, priority,
checklist progress and assignee — with **Add** on each and **Add all to report**,
which write them into the editor as formatted lines. The day's list also shows a
per-department task strip, so the page answers "what were they actually doing?"
without opening anything.

Note bodies are **rich text**: `body` is sanitised HTML and `body_text` its
plain-text rendering, used for search and previews. Both go through
`RichTextService`, which reports share — see [Rich text and attachments](#rich-text-and-attachments).

### Reports (tenant-scoped)

One report per department per day, written in a rich-text editor. Any member of
the workspace can write or correct a department's report; deleting the record of
a day stays with its author or an admin.

| Method | Route | Notes |
|---|---|---|
| GET | `/reports` | `?date=`, `?from=&to=`, `?department_id=`, `?q=` (searches the plain-text rendering). Newest day first, paginated. |
| GET | `/reports/daily` | `?date=` — every department with its report or `null`, plus `reported` / `missing` counts. Answers "who still owes a report today?", which the index cannot: a department that never reported has no row. |
| POST | `/reports` | `department_id`, `report_date`, `body`. An **upsert** — posting twice for a day corrects that day rather than failing on the unique index. `201` on the first write, `200` after. |
| GET / PATCH / DELETE | `/reports/{report}` | PATCH takes `body` only; the department and the day are fixed at creation. |

### Audit trail

Tasks and reports keep a record of who did what to them. `GET /tasks/{task}` and
`GET /reports/{report}` carry it as `activity`, newest entry first:

```json
"activity": [
  {
    "id": 412,
    "action": "updated",
    "action_label": "edited",
    "user_id": 7,
    "actor_name": "Sokha",
    "changes": [
      { "label": "Status", "from": "Todo", "to": "Completed", "opaque": false },
      { "label": "Due date", "from": "2026-09-12 09:00", "to": "2026-09-13 09:00", "opaque": false }
    ],
    "created_at": "2026-09-12T07:41:55.000000Z"
  },
  { "id": 380, "action": "created", "actor_name": "Dara", "changes": [], "created_at": "..." }
]
```

- `action` is one of `created`, `updated`, `deleted`, `restored`.
- `changes` is already resolved to labels and names — "Completed", not `done`; a
  person, not an id — because an entry records a moment, and a department renamed
  next month must not rewrite what last month's entry says.
- `opaque: true` means the field changed but its content is not kept. Report
  bodies are opaque: two copies of a page of HTML per edit would make the trail
  several times the size of the thing it describes.
- `actor_name` is `System` when nothing was signed in — the seeder, a console
  command or the recurrence job.

The trail is written by `App\Models\Concerns\RecordsActivity`, hooked onto the
model's own events rather than onto each caller: a task is written from six
places, and a trail with holes in it reads as "nobody touched this". It outlives
what it describes, since a deleted task is exactly the one somebody asks about
afterwards.

### Rich text and attachments

Reports and notes are both written in a rich-text editor (Quill), and both store
sanitised HTML in `body` alongside a plain-text `body_text` used for search and
previews — searching the markup would match tag names and miss any phrase a bold
tag happens to split.

**HTML is sanitised on the way in, never on the way out.** Bodies are user input
rendered back as markup to a whole workspace, so everything funnels through
`RichTextService` and the `rich_text` HTMLPurifier profile in
`config/purifier.php` — an allowlist of exactly what the editor emits. `script`,
`img`, `iframe`, `on*` handlers, inline CSS and `javascript:` URLs do not survive
it. Nothing but `RichTextService` should write those columns.

The editor is one Blade component, `<x-rich-text-editor>`, used by reports,
report headers and notes. Only Quill's *core* stylesheet is loaded — the toolbar
markup is ours — and the format list it offers is deliberately the same shortlist
`config/purifier.php` allows, so nothing can be typed that the server then
strips.

One Quill quirk worth knowing: in the DOM it renders **both** list types as
`<ul>` with the real type hidden on `li[data-list]`. Saving `innerHTML` would
turn every numbered list into bullets the moment the sanitiser dropped that
attribute, so the editor saves `getSemanticHTML()` instead, which emits proper
`<ol>`/`<ul>`. For the same reason list markers are styled for `.rich-text`
only: inside the editor Quill draws its own, and dressing them twice shows two
bullets per line.

**Attachments** (notes today; the table is polymorphic, so reports are the
obvious next one) accept PDF, Word, Excel and ordinary images up to **5 MB**.
SVG is deliberately excluded: it is a script-carrying document browsers render,
which would undo the sanitising everywhere else. `mimes:` validates by sniffing
the contents, so an HTML page renamed `.png` is refused.

Files live on the **private** `local` disk and are never served off the
filesystem. The stored path is generated (`attachments/{tenant}/{Y}/{m}/{ulid}.ext`)
and the uploader's filename is kept for display only, so a crafted name buys
nothing. Every read goes through `AttachmentController@download`, which checks
`AttachmentPolicy` — an attachment is exactly as private as the note it hangs
off — and sends `X-Content-Type-Options: nosniff`, showing images and PDFs
inline and pushing everything else to disk.

### Example

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"rady@example.com","password":"password"}' | jq -r .access_token)

curl -s localhost:8000/api/v1/events \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'X-Tenant: acme-team' | jq '.data[] | {title, starts_at}'
```

## Dashboard

| Route | What it does |
|---|---|
| `/login`, `/register` | Session auth. Registering provisions a workspace and calendar. |
| `/dashboard` | Month grid, per-calendar filters, create/edit/delete events with guests. Month and filters live in the URL. |
| `/calendars` | Calendar CRUD, visibility, colour, and sharing with workspace members. |
| `/workspaces` | Create and switch workspaces, manage members and roles. |
| `/notes` | Workspace noticeboard: write, search, filter, pin and colour notes. Search and filter live in the URL. |
| `/reports` | Daily report per department: step through days, see who has reported and who has not, write in a rich-text editor. |
| `/invitations` | Accept / maybe / decline invitations. |

Livewire's update endpoint does not re-run a page route's custom middleware, so components
resolve the active workspace themselves through `App\Livewire\Concerns\InteractsWithTenant`
rather than trusting the `ResolveTenant` middleware from the initial page load. The middleware
still guards the API, where every request is independently authenticated.

## Deploying

```bash
./scripts/deploy.sh
```

Run it on the server once the new code is in place. It installs dependencies,
builds the front end, migrates, and rebuilds the caches.

**If a page still renders its old version after a deploy, it is almost always
one of these two**, and both are what the script exists to prevent:

| Cause | Why it bites | Fix |
|---|---|---|
| `public/build/` is gitignored | Pulling code brings no CSS or JS, so the server keeps serving the bundle it last built | `npm run build` |
| Compiled Blade under `storage/framework/views/` | Blade recompiles by comparing file times. A deploy that writes `.blade.php` files with *older* timestamps — `rsync -t`, an unpacked archive, a restored checkout — makes the stale compile look current, and the previous page goes on being served | `php artisan view:clear` |

Neither is a browser cache: the service worker never stores HTML (see below), so
a hard reload cannot fix either one and does not tell you anything when it fails.

## Progressive web app

BongCalendar installs to a phone or tablet home screen and runs without browser
chrome. Android and desktop Chrome read `public/manifest.webmanifest`; iOS
ignores the manifest for installs and reads the `apple-*` meta tags in the
layout instead, so both sets are present.

| File | Role |
|---|---|
| `public/manifest.webmanifest` | Name, colours, icons, and Calendar/Tasks/Notes shortcuts. |
| `public/sw.js` | Service worker. Registered from `resources/js/app.js`, production builds only. |
| `public/offline.html` | Offline fallback. Self-contained — no build assets, since it must render with no network. |
| `public/logo.png` | The brand mark every icon is generated from. |
| `public/icons/` | Generated icon set. Committed, so a deploy does not need ImageMagick. |
| `scripts/generate-icons.sh` | Regenerates every icon from one source image. |

### What the service worker does and does not cache

The app is server-rendered, authenticated and multi-tenant, so the rule is
**never serve one person's HTML to another**:

- **Pages** are fetched from the network every time and are never written to a
  cache. Offline falls back to `offline.html` rather than a stale dashboard
  belonging to whoever logged in last.
- **`/build/` assets** are cached first and served from cache. Vite
  content-hashes them, so a hit is always correct, and new markup references new
  filenames that miss the cache — a deploy cannot leave a tab on an old bundle.
  That is also why there is no update-and-reload prompt.
- **Non-GET requests are never intercepted.** Livewire updates and form posts go
  straight to the network; caching or replaying them would corrupt state.
- **`/livewire`, `/api`, `/login`, `/logout` and `/register` are never touched.**

Bump `VERSION` in `public/sw.js` to retire every previously cached response.

### Regenerating the icons

Every icon comes from `public/logo.png`. After changing it, re-run the generator
and commit the result:

```bash
./scripts/generate-icons.sh public/logo.png
```

It writes `public/icons/` and `public/favicon.ico`, and fails loudly rather than
emitting a blank or broken set. Three things it handles that are easy to get
wrong by hand:

- **Flat backgrounds are stripped.** The mark is a rounded white card exported
  onto solid black. Left alone that black survives as four hard triangles on
  every icon, so when all four corners share a colour (within a tolerance — the
  downscale shifts each corner by a point or two) it is flood-filled to
  transparency. Flood fill only reaches the connected border region, so artwork
  of the same colour *inside* the mark is untouched. Pass `--keep-background` to
  skip this.
- **Maskable icons are cut past the card's corner radius, then inset.** Android
  crops these to a circle, squircle or teardrop and only the middle 80% is
  guaranteed to survive. The stripped card also leaves a faint anti-aliased edge
  that reads as a grey ring once flattened onto white, so the outer 15% is
  cropped away first — enough to clear the corner curve, which a smaller crop
  would only trade for four nicks.
- **Apple touch icons are flattened onto white.** iOS applies its own rounded
  mask and does not composite transparency; a transparent corner renders black.

SVG sources need `rsvg-convert` (`brew install librsvg`) — ImageMagick's own SVG
renderer silently drops strokes and transforms and returns an empty square.

`tests/Feature/PwaTest.php` checks that every icon the manifest promises exists
at the size it claims, and that the icons which cannot carry transparency are
fully opaque.

## Tests

```bash
php artisan test
```

Tests run against MySQL (`bong_calendar_testing`, configured in `phpunit.xml`) rather than
SQLite, so they exercise the same driver as production:

```bash
mysql -u root -e "CREATE DATABASE bong_calendar_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Coverage centres on the boundaries that matter: token issuance, cross-tenant leakage,
share-permission escalation, timezone conversion, and overlap queries.
