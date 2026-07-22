# EMPREINTE Live — Documentation

> Standalone Nextcloud app that integrates **EMPREINTE Live** into the calendar,
> **without modifying** the official Calendar app.
>
> App id: `empreintelive` · PHP namespace: `OCA\EmpreinteLive` · License: AGPL-3.0-or-later

---

## 1. Goal & principle

**EMPREINTE Live for Nextcloud** is a **separate app** that sits *alongside*
the official Calendar (installed as-is from the App Store) and grafts video
features onto it, **without modifying or forking it** — Calendar keeps receiving
its normal updates from the App Store.

```
┌────────────────────────────┐     ┌──────────────────────────────────────┐
│  Official Calendar          │     │  empreintelive (this app)             │
│  (App Store, NOT modified)  │     │  AGPL — OAuth + Proxy + UI + API       │
│                             │     │                                        │
│  • .ics events              │     │  • Dedicated "EMPREINTE Live" app (top-bar)│
│  • event deletion ──────────┼────▶│  • Server-side listener (auto-cleanup) │
└────────────────────────────┘     │  • OAuth tokens stored server-side     │
                                    └───────────────────┬────────────────────┘
                                                        │ HTTPS (Bearer)
                                                        ▼
                                        ┌────────────────────────────────┐
                                        │  EMPREINTE Live backend        │
                                        │  https://api.empreinte.live     │
                                        │  (proprietary)                  │
                                        └────────────────────────────────┘
```

**Key design rules:**

- **Zero tokens in the browser.** The whole OAuth flow runs **server-side** and
  tokens are stored per Nextcloud user (never in `localStorage`, which is exposed
  to XSS). The frontend only calls *our* internal endpoints; the server is the one
  that talks to EMPREINTE.
- **Public OAuth client (PKCE, no secret).** The app is a *public* OAuth client:
  there is **no `client_secret`** to bundle, store, or configure. The flow is
  secured by PKCE (`code_verifier` / `code_challenge`). The `client_id` is a public
  value bundled in the app; `redirect_uri` is derived from the Nextcloud instance
  URL (`<host>/apps/calendar/empreinte-callback`) and needs no configuration.
- **Loose coupling to Calendar.** We call no private Calendar API: we listen to a
  **public** Nextcloud event and read the event's `.ics`.

---

## 2. Architecture

### 2.1 Backend (PHP — `lib/`)

Layered design: *thin* Controllers → *business* Services → HTTP client.

| File | Role |
|---|---|
| `AppInfo/Application.php` | Bootstrap (`IBootstrap`). Registers the Calendar listener on the 3 events (trash / delete / update). |
| `Controller/OAuthController.php` | `/oauth/*` endpoints. Delegates to `OAuthService`, triggers reconciliation after `connect`. |
| `Controller/LiveController.php` | `/lives*` endpoints. Delegates to `EmpreinteApiService`. |
| `Controller/AttendeeController.php` | `/attendees/search` — participant autocomplete. Delegates to `ContactSearchService`. |
| `Controller/ProxyController.php` | `/proxy` — generic relay. |
| `Service/OAuthService.php` | Full OAuth flow: register / login / **authorize + approve + connect (PKCE)** / refresh / revoke. |
| `Service/EmpreinteApiService.php` | Lives business logic: create/update/delete/get/list + invitations. Auto-retry on 401. |
| `Service/TokenService.php` | Per-user token storage (`IConfig`), expiry computation, `hasScopedToken()`. |
| `Service/EmpreinteClient.php` | Low-level HTTP client (no business logic). |
| `Service/CalendarEventService.php` | Writes / updates / deletes the mirror event in the calendar (inverse flow, see §2.4). |
| `Service/ContactSearchService.php` | User / contact search via `OCP\Contacts` (participant autocomplete). |
| `Service/EmpreinteLiveId.php` | Extracts the `liveId` from an `.ics` (X-EMPREINTE-*, LOCATION, DESCRIPTION) — shared logic. |
| `Service/LiveCalendarSyncService.php` | **Reconciliation**: aligns the calendar with the connected account's Lives (back-fill + prune). See §2.5. |
| `Service/SyncGuard.php` | Per-request flag preventing the reconciliation prune from deleting EMPREINTE Lives. See §2.5. |
| `Listener/CalendarObjectListener.php` | Syncs the Live when the event is trashed, deleted **or updated**. |
| `Controller/PageController.php` | Renders the full-page "EMPREINTE Live" app (app-menu / top-bar icon). Loads the Vue bundle. |

**Internal endpoints** (`appinfo/routes.php`, prefix `/apps/empreintelive`):

| Method | URL | Action |
|---|---|---|
| GET  | `/`               | Full-page app (top-bar icon → `PageController::index`) |
| GET  | `/oauth/status`   | `{ connected: bool }` — `true` only if a **scoped token** is present |
| POST | `/oauth/register` | Create an EMPREINTE account |
| POST | `/oauth/login`    | Authenticate (session token, **empty scope**) |
| POST | `/oauth/authorize`| Step 1/2: returns the **scopes** to display on the consent screen |
| POST | `/oauth/approve`  | Step 2/2: consent granted → continue the PKCE flow |
| POST | `/oauth/connect`  | **PKCE** → `live:*` scoped token, then calendar **reconciliation** |
| POST | `/oauth/logout`   | Revoke + delete the local token |
| GET  | `/lives`          | List meetings |
| POST | `/lives`          | Create a meeting |
| PUT  | `/lives/{id}`     | Update |
| DELETE | `/lives/{id}`   | Delete |
| GET  | `/attendees/search` | Participant autocomplete (contacts / users) |
| POST | `/proxy`          | Generic relay |

> All endpoints are `#[NoAdminRequired]` (accessible to any logged-in user) and
> the `userId` comes from the Nextcloud session (`IUserSession`).

### 2.2 Frontend (Vue 3 — `src/`)

Stack: **Vue 3** + `@nextcloud/vue` 9 + `@nextcloud/webpack-vue-config` 7.

| File | Role |
|---|---|
| `src/main.js` | Entry point (`createApp`), mounts on `#empreintelive-app`. |
| `src/App.vue` | App-page shell (`NcContent` + `NcAppContent`) embedding `MeetingsPage.vue`. |
| `src/services/api.js` | Axios wrapper to our endpoints (`@nextcloud/axios` adds the requesttoken). **No token handled client-side.** |
| `src/views/MeetingsPage.vue` | Orchestrator: `/oauth/status` → connection form or meeting management. |
| `src/components/ConnectionForm.vue` | Login / register tabs → chains `login`, `authorize`, consent, then `connect`. |
| `src/components/ConsentDialog.vue` | OAuth consent screen: shows the permissions (scopes), emits `approve` / `cancel`. |
| `src/components/CreateMeeting.vue` | Creation form (local time → ISO8601 UTC) + participant autocomplete. |
| `src/components/MeetingList.vue` | List + join + delete (defensive access to API fields). |

The build produces `js/empreintelive-main.js`, loaded by `PageController` via
`Util::addScript` and mounted in `templates/main.php`.

> ⚠️ **Webpack gotcha:** the entry key must be `main` (not `empreintelive-main`)
> because `@nextcloud/webpack-vue-config` already prefixes the appId → otherwise
> a double prefix. Apps in `custom_apps/` are served under the `/custom_apps/...`
> URL, not `/apps/...`.

### 2.3 Calendar → EMPREINTE sync (server-side listener)

`CalendarObjectListener` is registered on **three** public Nextcloud events
(`AppInfo/Application.php`):

- `CalendarObjectMovedToTrashEvent` and `CalendarObjectDeletedEvent` → **deletion**
  of the associated Live (`EmpreinteApiService::deleteLive()`);
- `CalendarObjectUpdatedEvent` → **update** of the Live (title / description / dates)
  via `EmpreinteApiService::updateLive()`.

Common steps:

1. The listener reads the `.ics` (`getObjectData()['calendardata']`).
2. It extracts the `liveId` via `EmpreinteLiveId` (searching in
   `X-EMPREINTE-ADMIN-URL`, `X-EMPREINTE-PARTICIPANT-URL`, `LOCATION`, `DESCRIPTION`);
   an event without a `liveId` is ignored.
3. It derives the `userId` from the `principaluri` (`principals/users/{uid}`).
4. It calls the matching Live service.

We **also listen to the trash** so the meeting disappears as soon as the event is
trashed (display consistency). The listener is **best-effort**: any error is logged
but **never blocks** the Calendar operation. Since the `liveId` already lives in the
`.ics`, **no separate storage** is required.

> ⚠️ A deletion triggered by the app itself (reconciliation prune, §2.5) goes
> through the same `CalendarObjectDeletedEvent`. The `SyncGuard` is precisely there
> to **not** propagate these internal deletions to EMPREINTE.

### 2.4 Creating the meeting in the calendar

The user creates the meeting from the app page, and the app **writes the event
itself** into the calendar via the public Calendar API (there is no public
"conference provider" API to hook a button into the Calendar event modal):

1. `EmpreinteApiService::createLive()` creates the Live on the EMPREINTE side.
2. `CalendarEventService::createEventForLive()`:
   - picks the user's first **writable** calendar (priority to `personal`);
   - builds the event via `IManager::createEventBuilder()` (summary, dates,
     description) and writes it with `createInCalendar()`;
   - puts the **meeting link in `LOCATION` + `DESCRIPTION`** → the deletion listener
     (§2.3) can recover the `liveId`.
3. `LiveController::create` returns `eventCreated`; the frontend confirms
   "Meeting created **and added to your calendar**".

Best-effort: if there is no writable calendar or on failure, the Live is still
created (the failure is logged, `eventCreated = false`). **The official Calendar is
never modified.**

> **Link by role:** the **creator** receives the **organizer** link (`admin_url`,
> meeting control), the **participants** the participant link (`participant_url`).
> The choice is automatic based on the connected account, both in the calendar event
> and in the invitations.

### 2.5 Reconciliation: the calendar reflects the connected account

When an account connects (`OAuthController::connect` → `LiveCalendarSyncService::reconcile()`),
the "EMPREINTE Live" calendar is **aligned** with that account's `/lives` list:

- **back-fill**: the account's meetings without a local mirror are **added** to the calendar;
- **prune**: mirror events belonging to **another** account are **removed from display**.

Guarantees:

- **EMPREINTE Lives are never deleted** — each account gets its own back when it
  reconnects (the back-fill recreates them).
- The prune deletes **calendar events** via `CalDavBackend`, which fires
  `CalendarObjectDeletedEvent` **synchronously within the same request**. The
  `SyncGuard` (per-request flag) marks these deletions as internal so the listener
  (§2.3) **does not propagate** a `deleteLive()`.
- **Best-effort**: `reconcile()` never fails the connection, and if the `/lives`
  list could not be fetched, **nothing is pruned** (no wiping based on a
  partial/erroneous list).

---

## 3. OAuth flow (important)

⚠️ **A tricky point to know.** The EMPREINTE API has **two steps**:

1. **`login`** → returns an `access_token` but with an **empty `scope`**. This token
   is recognized (no 401) but **rejected with `insufficient_scope`** as soon as you
   call `/lives`.
2. **`connect` (PKCE)** in three stages app-side: **`authorize`** returns the scopes
   to display → **consent screen** (`ConsentDialog.vue`) → **`approve`** → code
   exchange → token scoped `live:read live:write live:update live:delete`.

**You must ALWAYS chain `login` THEN consent/`connect`.** The frontend already does
it in the right order (`ConnectionForm.vue`). If `/lives` replies
`insufficient_scope`, it means `connect` did not complete — **not** a credentials
problem.

> 💡 `status` returns `connected: true` **only if a scoped token exists**
> (`TokenService::hasScopedToken()`). A mere identification (`login`) without
> validated consent is therefore **not** considered connected: the connection screen
> reappears to finish the step, which avoids getting stuck unable to create/list
> meetings. A calendar reconciliation (§2.5) is triggered right after a successful
> `connect`.

---

## 4. Getting started (development)

### Prerequisites
- Docker + Docker Compose
- Node.js 22 + npm (to build the frontend, host-side)
- (Optional) Composer, otherwise use the container's PHP

### 4.1 Start the environment

```bash
cd empreinte-calendar-integration
docker compose up -d          # Nextcloud 32 + official Calendar + this app
# UI: http://localhost:8080   —   credentials: admin / admin
docker compose down           # stop (keeps data; -v to wipe everything)
```

The `empreintelive/` folder is mounted in the container at
`/var/www/html/custom_apps/empreintelive`.

### 4.2 Build the frontend

```bash
cd empreintelive
npm install
npm run build                 # prod  → js/empreintelive-main.js
npm run watch                 # dev   → rebuild on every change
```

### 4.3 OAuth configuration (optional)

The app is a **public OAuth client** secured by PKCE: it ships with a bundled `client_id`
and a default API URL, there is **no `client_secret`**, and the OAuth callback is
validated and handled by the EMPREINTE backend. The `redirect_uri` is derived
automatically from the current Nextcloud instance URL
(`<host>/apps/calendar/empreinte-callback`). **No configuration is required to
connect.** The following values can still be overridden if needed:

```bash
# (optional) — none of these are required:
docker compose exec --user www-data nextcloud php occ config:app:set \
  empreintelive redirect_uri  --value="https://<host>/apps/calendar/empreinte-callback"
#   occ config:app:set empreintelive client_id     --value="..."
#   occ config:app:set empreintelive api_base_url  --value="https://api.empreinte.live"
```

### 4.4 Use the app

**EMPREINTE Live** icon in the top app menu (`http://localhost:8080/apps/empreintelive/`)
→ sign in, then create / list / delete meetings.

### 4.5 Reload after changes

- **PHP** (outside routes): applied immediately (opcache in dev mode).
- **Routes** (`appinfo/routes.php`): `occ app:disable empreintelive && occ app:enable empreintelive`.
- **Frontend**: `npm run build` (or `watch`).

---

## 5. Tests

| Layer | Tool | Files | Command |
|---|---|---|---|
| Backend PHP | PHPUnit 10 | `tests/Unit/**` (51 tests: Token, OAuth, EmpreinteApi, CalendarEvent, ContactSearch, EmpreinteLiveId, listener) | see `empreintelive/tests/README.md` |
| Frontend JS | Vitest | `tests-js/api.spec.js` (20 tests) | `npm run test:unit` |

**PHP** (no PHP on the host → run inside the container):

```bash
docker exec empreinte-nextcloud sh -c \
  'curl -sSfL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-10.phar'
docker exec -w /var/www/html/custom_apps/empreintelive/tests \
  empreinte-nextcloud php /tmp/phpunit.phar --configuration phpunit.xml
```

**Frontend**:

```bash
cd empreintelive && npm run test:unit
```

Unit tests open **no** network connection (everything is mocked).

---

## 6. Features

- **OAuth 2.0 connection** to an EMPREINTE account, with a consent screen and tokens
  handled **server-side** (§3).
- **Meeting management** from the dedicated app page (app-menu / top-bar icon):
  create, list, join, delete.
- **Automatic event writing** into the user's calendar when a meeting is created (§2.4).
- **Calendar → EMPREINTE sync**: an event that is trashed, deleted or updated updates
  the associated meeting (§2.3).
- **Reconciliation on connection**: the "EMPREINTE Live" calendar reflects the
  connected account (back-fill + prune protected by `SyncGuard`, §2.5).
- **Link by role**: the creator receives the organizer link (`admin_url`), the
  participants the participant link (`participant_url`) (§2.4).
- **Participant autocomplete** via `OCP\Contacts` (§2.1).
- **Reliable connection state**: based on the presence of a **scoped token**
  (`hasScopedToken`, §3).
- **No separate storage**: the `liveId` is carried by the event's `.ics`.
- **Unit tests**: PHPUnit (51) + Vitest (20).

---

## 7. Deployment

### Build & package
1. Build the frontend: `npm run build` (produces `js/`).
2. Package the `empreintelive/` runtime folder as a `.tar.gz`, excluding
   `node_modules/`, `tests/`, `tests-js/`, `src/` and `.map` files.

### Install on a Nextcloud server
1. Place the `empreintelive/` folder in the server's `custom_apps/` (or `apps/`).
2. `occ app:enable empreintelive`.
3. Make sure the official Calendar app is installed and enabled. No OAuth
   configuration is required (see §4.3 for optional overrides).

---

## 8. Cheat sheet

```bash
# Environment
docker compose up -d                 # start
docker compose down                  # stop (-v to wipe data)

# Frontend
cd empreintelive
npm install && npm run build         # prod build
npm run test:unit                    # Vitest

# OAuth config
docker compose exec --user www-data nextcloud php occ config:app:set \
  empreintelive client_id --value="..."

# Reload routes
docker compose exec --user www-data nextcloud php occ app:disable empreintelive
docker compose exec --user www-data nextcloud php occ app:enable  empreintelive

# Inspect a user's config / token
docker exec --user www-data empreinte-nextcloud php occ config:list empreintelive
docker exec --user www-data empreinte-nextcloud php occ user:setting admin empreintelive tokens
```

**Container**: `empreinte-nextcloud` · **Nextcloud**: 32 · **PHP**: 8.3 · **URL**: http://localhost:8080 (admin/admin)
