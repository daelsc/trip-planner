#!/usr/bin/env python3
"""Generate airports.json from OurAirports public domain data.

Filters to airports with ICAO codes and paved runways >= 3000 ft.
Uses timezonefinder for coordinate-to-timezone lookup.

Usage:
    pip install timezonefinder
    python generate_airports.py
"""

import csv
import json
import io
import urllib.request
from timezonefinder import TimezoneFinder

AIRPORTS_URL = "https://davidmegginson.github.io/ourairports-data/airports.csv"
RUNWAYS_URL = "https://davidmegginson.github.io/ourairports-data/runways.csv"

PAVED_SURFACES = {
    "ASP", "ASPH", "BIT", "CON", "CONC", "CONCRETE", "PEM", "ASPHALT",
    "BITUMINOUS", "PAVED", "TARMAC",
}

MIN_RUNWAY_FT = 3000


def fetch_csv(url):
    print(f"Fetching {url} ...")
    with urllib.request.urlopen(url) as resp:
        text = resp.read().decode("utf-8-sig")
    return list(csv.DictReader(io.StringIO(text)))


def main():
    airports_raw = fetch_csv(AIRPORTS_URL)
    runways_raw = fetch_csv(RUNWAYS_URL)

    # Build set of airport IDs with qualifying paved runways
    qualifying_ids = set()
    for rwy in runways_raw:
        surface = (rwy.get("surface") or "").strip().upper()
        length = rwy.get("length_ft") or "0"
        try:
            length_ft = int(length)
        except ValueError:
            continue
        is_paved = any(p in surface for p in PAVED_SURFACES)
        if is_paved and length_ft >= MIN_RUNWAY_FT:
            qualifying_ids.add(rwy.get("airport_ident", "").strip())

    print(f"Found {len(qualifying_ids)} airports with paved runways >= {MIN_RUNWAY_FT} ft")

    tf = TimezoneFinder()
    results = []
    skipped_tz = 0

    for ap in airports_raw:
        icao = (ap.get("ident") or "").strip()
        if not icao or len(icao) != 4 or not icao[0].isalpha():
            continue
        if icao not in qualifying_ids:
            continue

        lat = ap.get("latitude_deg", "")
        lon = ap.get("longitude_deg", "")
        try:
            lat_f = float(lat)
            lon_f = float(lon)
        except (ValueError, TypeError):
            continue

        tz = tf.timezone_at(lat=lat_f, lng=lon_f)
        if not tz:
            skipped_tz += 1
            continue

        name = (ap.get("name") or "").strip()
        city = (ap.get("municipality") or "").strip()
        country = (ap.get("iso_country") or "").strip()

        results.append({
            "icao": icao,
            "name": name,
            "city": city,
            "country": country,
            "lat": round(lat_f, 6),
            "lon": round(lon_f, 6),
            "tz": tz,
        })

    results.sort(key=lambda x: x["icao"])

    out = "airports.json"
    with open(out, "w") as f:
        json.dump(results, f, separators=(",", ":"))

    size_mb = len(json.dumps(results, separators=(",", ":"))) / 1024 / 1024
    print(f"Wrote {len(results)} airports to {out} ({size_mb:.1f} MB)")
    print(f"Skipped {skipped_tz} airports with no timezone match")


if __name__ == "__main__":
    main()
