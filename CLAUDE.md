# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Aviation trip planner web app for planning multi-leg flights. Handles flight time calculations, crew rest/duty time tracking, FBO lookups, timezone conversions, and ForeFlight dispatch integration. Served via Apache on submarine at `public_html/trip-planner/`.

## Architecture

**Single-page app** — all frontend logic lives in `index.html` (inline CSS + JS, ~1800 lines). No build system, no frameworks, no bundling. PHP backend serves JSON APIs.

### Frontend (`index.html`)
- Three screens: login -> home (trip list) -> planner
- Aircraft profiles defined in `PROFILES` object (G550, PC-12, custom) with speed/altitude/timing parameters
- `CAA_FBOS` map: ICAO -> preferred FBO name (highlighted in UI)
- Core state: `legs[]` array, each leg has departure/arrival ICAO, ground time, rest flag, wind data
- `recalc()` is the main render function — rebuilds the entire leg table on any change
- Two view modes: Passenger (simplified) and Crew (full timing/duty details)
- Airport autocomplete uses `airports.json` (loaded at startup, ~1.8MB, cached 30 days)
- Wind data fetched from Open-Meteo API at cruise altitude pressure levels

### Passenger view (`pax.html`)
- Read-only shareable itinerary page, loads saved trip by `?id=` parameter
- Has its own copy of `PROFILES` — keep in sync with `index.html`

### Backend (PHP, no framework)
| File | Purpose |
|------|---------|
| `auth.php` | Session-based auth with SHA256 password check |
| `trips.php` | CRUD for saved trips (JSON files in `saved_trips/`) |
| `fbo.php` | Scrapes AirNav for FBO data, enriches with Google Places addresses, caches 7 days in `fbo_cache/` |
| `foreflight.php` | Proxies flight plans to ForeFlight Dispatch API (requires auth) |

### Data
- `airports.json` — generated offline by `generate_airports.py` from OurAirports CSV data. Includes ICAO, name, city, state, country, lat/lon, timezone, longest paved runway length.
- `saved_trips/` — individual trip JSON files with auto-incrementing trip numbers (`.counter` file)
- `fbo_cache/` — cached FBO lookup results per ICAO code

## Regenerating Airport Data

```bash
pip install timezonefinder
python generate_airports.py
```

Fetches from OurAirports, filters to airports with ICAO codes and paved runways >= 2000 ft, adds timezone info.

## Key Patterns

- State is serialized to URL hash for shareability (`l=` legs, `t=` start time, `a=` aircraft, etc.)
- FBO selections stored per-ICAO in leg state; CAA preferred FBOs auto-selected as defaults
- Crew duty time calculations follow FAR 117 concepts (14-hour duty, 10-hour rest minimums)
- All times internally in UTC; displayed in departure/arrival airport local time via `Intl.DateTimeFormat`
- PHP APIs return JSON; write operations require session auth, reads are public
