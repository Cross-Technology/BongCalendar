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
| POST | `/auth/refresh` | Exchanges the current token for a fresh one. |
| POST | `/auth/logout` | Invalidates the token. |
| GET | `/auth/me` | Current user with their workspaces. |

### Workspaces

| Method | Route |
|---|---|
| GET / POST | `/workspaces` |
| GET / PATCH / DELETE | `/workspaces/{tenant}` |
| POST | `/workspaces/{tenant}/switch` |
| GET / POST | `/workspaces/{tenant}/members` |
| DELETE | `/workspaces/{tenant}/members/{user}` |

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
| `/invitations` | Accept / maybe / decline invitations. |

Livewire's update endpoint does not re-run a page route's custom middleware, so components
resolve the active workspace themselves through `App\Livewire\Concerns\InteractsWithTenant`
rather than trusting the `ResolveTenant` middleware from the initial page load. The middleware
still guards the API, where every request is independently authenticated.

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
