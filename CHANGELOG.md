# Trip Planner — Changelog & Architecture Notes

## Architecture

**Stack:** Vanilla HTML/JS frontend (no frameworks, no build tools) + PHP backend + SQLite database. Deployed on Apache at `thesemite.com/trip-planner/`.

**Frontend** — three HTML pages, all JS/CSS inline:
- `index.html` — main planner app (~2200 lines). Three screens: login → home (trip list) → planner. Two view modes: Passenger (simplified) and Crew (full duty/rest calculations). Mobile-responsive at ≤640px via CSS media queries (card layout, hamburger settings drawer).
- `pax.html` — read-only passenger itinerary, shareable via URL. Auto-refreshes every 60s.
- `planner.html` — stripped-down share view for trip planners. Shows airports, FBOs, departure times, passenger names only.

**Backend** — plain PHP, no framework:
- `db.php` — SQLite helper. Opens `flights.db`, creates tables on first use (users, trips, trip_versions, trip_locks).
- `auth.php` — Google OAuth (GIS popup flow) + password fallback. Users table acts as allowlist for Google login.
- `trips.php` — CRUD for trips, version history, and locking. State stored as JSON blob.
- `fbo.php` — scrapes AirNav for FBO data, enriches with Google Places for addresses, caches 7 days.
- `foreflight.php` — pushes flight plans to ForeFlight Dispatch API.
- `migrate.php` — one-time import from old `saved_trips/*.json` into SQLite.

**Key design decisions:**
- **State as URL params:** Trip state is a flat key-value map (aircraft, legs, times, FBOs, etc.) serialized as URL search params. This makes trips shareable via URL and keeps the state format simple. The DB stores this as a JSON blob — it's opaque to the database.
- **No SPA framework:** The app is simple enough that vanilla JS with a single `recalc()` function that rebuilds the DOM on every change works fine. No virtual DOM, no state management library.
- **SQLite over JSON files:** Switched from per-trip JSON files to SQLite to support versioning, user management, and locking without file system race conditions.
- **Wind sampling along route:** Long legs sample wind data every ~300nm along the route (up to 8 points) and average the headwind component, rather than using a single midpoint measurement.
- **Optimistic locking:** Trip editing uses a lock table with heartbeats. Lock expires after 60s without heartbeat to handle browser crashes. `sendBeacon` on page unload for clean release.

## Changelog

### 2026-03-18 — Ground Transport, Food Dialogs, Save Fixes

#### Ground Transport per Departure/Arrival
- Split single ground transport field into **departure transport** and **arrival transport** per leg
- Each has structured fields: provider name, provider contact (phone/email), pickup location (departure) or dropoff location (arrival)
- State keys changed from `gt` (single) to `gtd` (departure) and `gta` (arrival), tilde-separated fields within each leg (`provider~contact~location`), pipe-separated between legs
- Backward compatible: old `gt` key loads into departure transport provider field

#### Food/Catering Dialog
- Food expanded from plain text to structured fields: description, link (URL), delivery time (time picker)
- State key `fd` now uses tilde-separated format (`desc~link~time`), backward compatible with old plain strings
- Food and transport edited via popup dialogs instead of inline inputs — buttons show summary, click to open editor

#### Passenger Page Improvements
- Food and transport now displayed contextually: food + departure transport in departure column, arrival transport in arrival column (previously all lumped in a footer row)
- Food links render as clickable "View Menu / Order" links
- Transport contact numbers render as clickable `tel:` links for mobile calling
- **Block times now match planner exactly** — planner serializes computed block times (with wind correction) into `bt` state key; pax page reads them directly instead of recalculating independently

#### Save Reliability Fixes
- **Fixed crash in `buildURL()`** — referenced `legCalc` (local to `recalc()`) instead of `lastLegCalc` (global). This caused every save, share link, and URL update to throw a `ReferenceError`. All saves were silently failing.
- **`doSave` now checks HTTP status** — catches 401 (session expired) and shows login screen instead of falsely displaying "Saved" toast
- **`doSave` checks for error in JSON response** — catches server-side errors
- **Active input flushed before save** — `document.activeElement.blur()` ensures in-progress edits are committed to the `legs` array
- **`saveTrip`/`doSave` return promises** — callers can await completion
- **"Go Home + Save" waits for save** — replaced 300ms `setTimeout` race with `.then()` chain; navigates home only after save succeeds
- **`restoreVersion` waits for save** — toast shows after save actually completes
- **`loadTrip` sets `lastSavedState`** — loading from trips dropdown no longer falsely triggers "unsaved changes" prompt

#### Share Link Fixes
- "Share with Passengers" and "Share with Planners" now auto-save if trip hasn't been saved yet, then share — eliminates the "save trip first" loop
- Fixed deployment path in CLAUDE.md (`flight-planer` → `trip-planner`)

#### Version History
- Only show last 5 versions by default, with "Show N more..." link to expand

### 2026-03-14 — Trip Locking

- **Concurrent edit protection:** When a user opens a trip, they acquire a lock. Other users see "X is currently editing this trip. View only." with Save disabled. Heartbeat every 30s; lock expires after 60s of inactivity. Released on navigate away, go home, or browser close.

### 2026-03-13/14 — Major Overhaul

#### SQLite Migration
- Replaced `saved_trips/*.json` file storage with SQLite database (`flights.db`)
- New `db.php` shared helper with WAL mode, foreign keys
- `migrate.php` for one-time import of existing JSON trip files
- `trips.php` fully rewritten for PDO/SQLite, same HTTP API surface

#### Google OAuth
- Google Identity Services popup flow — no redirect URI needed
- `users` table as allowlist (email + allowed flag)
- Password login preserved as fallback behind "Use password instead" link
- Session tracks `user_email` and `user_name` for version attribution

#### Trip Metadata
- **Purpose** — free text field per trip (`pu` state key)
- **Cargo** — yes/no toggle (required before save), with description + weight when yes. Design: making it required ensures cargo status is always confirmed before sharing itinerary.
- **Lead Pilot** — defaults to David Scheinman / 650-387-1111. Changing pilot clears phone default. Only non-default values serialized to keep state compact.
- **Passenger names** — input fields per leg based on pax count. Setting count on subsequent legs inherits names from previous leg only (not all earlier legs), since passengers typically board/deplane one leg at a time.

#### Per-Leg Fields
- **Food/catering** — structured per leg (description, link, delivery time), serialized as `desc~link~time` pipe-separated in `fd` state key. Edited via popup dialog.
- **Ground transport** — separate departure and arrival transport per leg (provider, contact, location), serialized as `provider~contact~location` in `gtd`/`gta` state keys. Edited via popup dialogs.
- Passenger page shows food in departure column, transport in respective departure/arrival columns

#### FBO Improvements
- **CAA preferred FBOs** highlighted with ★ (CAA) in dropdown (data from caa.org)
- **Default FBO per airport** — KSJC defaults to Signature Aviation via `DEFAULT_FBOS` map
- **Custom FBOs** — "Other..." option in dropdown with name/address/phone fields for international airports without AirNav coverage

#### Version History
- On save, current state archived to `trip_versions` (capped at 20 per trip)
- Inline version history section at bottom of trip editor
- Auto-generated change summaries (route, schedule, FBOs, passengers, etc.)
- View/restore old versions with banner UI

#### Planner Share View
- New `planner.html` — stripped-down view for trip planners
- Shows only airports, FBO names, departure times, passenger names
- Version number, generated timestamp, auto-refresh every 60s

#### Wind Calculations
- Sample every ~300nm along route instead of single midpoint
- Average headwind component across all samples (up to 8 points)
- Shows sample count in crew view (e.g. "5pt avg")

#### Crew Display
- Duty day headers show origin airport (e.g. "Day 1 — KSJC", "Day 2 — LICC")

#### Mobile Layout (≤640px)
- Settings bar collapses to sticky strip + hamburger drawer
- Leg table converts to stacked cards with labeled sections
- Touch targets sized to 44px minimum, 16px font on inputs (prevents iOS zoom)
- Autocomplete dropdown widened, bigger tap targets
- Crew tables stack as mini-cards
- Dialogs go near full-width

#### UX
- **Go Home dialog** — Save / Discard Changes / Cancel (3 buttons, not confusing OK/Cancel)
- **Drag-to-reorder legs** — grab ☰ handle or anywhere on row
- Home screen filters by aircraft type, hides past trips (by end date)

### 2026-03-03 — Performance & Auth

- Cache `airports.json` for 30 days (1.8MB file)
- Gzip responses, show home screen instantly before airports load
- Switch from bcrypt to SHA256 for faster password verification
- Aircraft selector on home screen, pax count per leg

### 2026-02 — Passenger View & FBOs

- `pax.html` — shareable read-only itinerary with auto-refresh
- FBO scraping from AirNav with Google Places address enrichment
- FBO phone numbers, Google Maps links
- N785QS tail number and date range in itinerary title
- ForeFlight Dispatch API integration with leg selection dialog

### 2026-01 — Core Features

- Trip save/load with auto-incrementing trip numbers
- Home screen with saved trips list
- Crew duty/rest tracking (FAR 117 concepts: 14h duty, 10h rest)
- Winds aloft from Open-Meteo API at cruise pressure levels
- Multi-timezone display with auto-populated timezones from route
- 3-phase speed model: climb, cruise, descent with per-aircraft profiles
- Crew rest blocks with show time calculations
- Runway length advisories per aircraft type
- Quick route entry (paste airport list)

### 2025-12 — Initial Release

- Basic multi-leg trip planner with haversine distance
- Airport autocomplete from OurAirports data
- G550, PC-12, and custom aircraft profiles
- URL-based state sharing
