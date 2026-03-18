# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Is

Flight trip planner web app for N785QS (Gulfstream G550). PHP backend + vanilla JS/HTML frontend, no build tools or frameworks. Deployed on a PHP-capable web server.

## Architecture

**Frontend** — three HTML pages, no bundler:
- `index.html` — main planner app (login, home screen, trip editor with legs/ground time/crew rest). All JS is inline. Has two view modes: Passenger (simplified) and Crew (full duty/rest calculations). Supports Google OAuth and password auth.
- `pax.html` — read-only passenger itinerary view, loaded via `?id=<tripId>`. Auto-refreshes every 60s. Shows food/transport details.
- `planner.html` — stripped-down trip planner share view. Shows only airport names, FBO names, and departure times. Auto-refreshes every 60s.

**Backend** — plain PHP files, no framework:
- `db.php` — shared SQLite helper. Opens/initializes `flights.db` with tables: `users`, `trips`, `trip_versions`.
- `auth.php` — session-based auth. Supports password login (`login` action), Google OAuth (`google` action), and session check (`check`). Google OAuth verifies JWTs and checks user allowlist in `users` table.
- `trips.php` — CRUD for trips using SQLite. Supports version history (`?history=1`) and loading specific versions (`?version=N`). On save, archives current state to `trip_versions` (capped at 20 per trip). Extracts `purpose` and `cargo` from state into dedicated columns.
- `migrate.php` — one-time migration script to import `saved_trips/*.json` into SQLite. Run once after deploying.
- `fbo.php` — FBO lookup by ICAO code. Scrapes AirNav, enriches with Google Places API for addresses. Caches results in `fbo_cache/` for 7 days.
- `foreflight.php` — pushes flight schedule to ForeFlight Dispatch API.

**Data generation** (Python, run locally):
- `generate_airports.py` — builds `airports.json` from OurAirports CSV data. Filters to airports with paved runways >= 2000 ft, adds timezone via `timezonefinder`. Run with `pip install timezonefinder && python generate_airports.py`.
- `scrape_fbos.py` — bulk scrapes FBO data from AirNav for all US (K-prefix) airports into `fbos.json`. Saves progress to `fbos_progress.json`. Throttled 1-2s between requests.

**Key data flow**: `airports.json` is loaded client-side for ICAO autocomplete, distance calculation (haversine), and timezone lookups. Trip state is serialized as a compact query-string-style object (`l` for legs, `g` for ground times, `d` for departure overrides, `pu` for purpose, `cg` for cargo, `fd` for food, `gtd`/`gta` for departure/arrival ground transport, etc.) and stored in SQLite as a JSON blob. The `saved_trips/` directory is obsolete after running `migrate.php`.

## State Keys

| Key | Scope | Format | Purpose |
|-----|-------|--------|---------|
| `pu` | trip | plain string | Trip purpose |
| `cg` | trip | plain string | Cargo/luggage details |
| `fd` | per-leg | pipe-separated | Food/catering per leg |
| `gtd` | per-leg | pipe-separated | Ground transportation at departure per leg |
| `gta` | per-leg | pipe-separated | Ground transportation at arrival per leg |

## Database

SQLite database at `flights.db`. Tables:
- `users` — email, name, allowed flag (for Google OAuth allowlist)
- `trips` — trip data with dedicated `purpose` and `cargo` columns, JSON state blob, version counter
- `trip_versions` — version history snapshots, capped at 20 per trip

## Aircraft Profiles

Defined in both `index.html` and `pax.html` as `PROFILES` object. Each profile has cruise/climb/descent speeds, phase minutes, and startup/shutdown times. Currently: G550, PC12, custom.

## Deployment

Hosted at `thesemite.com/trip-planner/`. Deploy by SSH (`ssh des@thesemite.com`) and uploading files to `/home/des/public_html/trip-planner/`. The server runs PHP natively.

## Local Development

No test suite or linting configured. Run locally with `php -S localhost:8000`.
