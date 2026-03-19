# FlightBridge API Reference

Reverse-engineered from the FlightBridge web application (flightbridge.com) on 2026-03-14 via mitmproxy traffic capture.

## Overview

FlightBridge is an ASP.NET MVC web application. There is no documented public REST API. All endpoints below are the same ones used by the web UI, accessed via standard HTTP with cookie-based session auth.

**Base URL:** `https://flightbridge.com`

## Authentication

FlightBridge uses ASP.NET Forms Authentication with a two-step login flow (email, then password).

### Login Flow

1. **GET `/Account/LogIn`** — Load the login page. Sets `ASP.NET_SessionId` cookie.

2. **POST `/Account/LogIn`** — Submit email address.
   - Content-Type: `application/x-www-form-urlencoded`
   - Body fields:
     | Field | Description |
     |-------|-------------|
     | `UserName` | FlightBridge email address |
     | `ReturnUrl` | Empty string |
   - Response: 302 redirect to `/Account/LogInPassword`

3. **GET `/Account/LogInPassword`** — Load the password page (maintains session state, shows email confirmation).

4. **POST `/Account/LogInPassword`** — Submit password.
   - Content-Type: `application/x-www-form-urlencoded`
   - Body fields:
     | Field | Description |
     |-------|-------------|
     | `Password` | FlightBridge password |
     | `RememberMe` | `true` or `false` |
   - Response: 302 redirect to `/`
   - Sets `.ASPXAUTH` cookie (valid ~14 days)

### Session Cookies

All subsequent requests must include:
- `ASP.NET_SessionId`
- `.ASPXAUTH`

## Endpoints

### Airport Search

**POST `/AirportTypeAhead/Search`**

Search for airports by ICAO/IATA code or name.

| Param | Type | Description |
|-------|------|-------------|
| `searchText` | string | Search query (e.g., `KSJC`, `San Jose`) |
| `maxResults` | int | Max results to return (default 12) |

Response:
```json
[
  {
    "Id": 5357,
    "Text": "Norman Y. Mineta San José International Airport, San José, California (SJC/KSJC)"
  }
]
```

### FBO Lookup

**POST `/FlightBridgeTrip/GetJsonFbosForAirport`**

Get FBOs at an airport.

| Param | Type | Description |
|-------|------|-------------|
| `airportId` | int | FlightBridge airport ID (from airport search) |

Response:
```json
[
  {
    "CompanyServiceAirportId": 2682,
    "Display": "Atlantic Aviation",
    "CompanyId": 90,
    "SearchableName": "ATLANTIC AVIATION",
    "IsPrivate": false,
    "IsRegistered": true,
    "PreferredRentalChain": 30
  }
]
```

### Calculate Arrival Time

**POST `/FlightBridgeTrip/CalculateArrivalDateTime`**

Estimate arrival time based on departure airport, arrival airport, and departure time.

| Param | Type | Description |
|-------|------|-------------|
| `DepartureAirportId` | int | FB airport ID |
| `ArrivalAirportId` | int | FB airport ID |
| `LocalOrZulu` | string | `Local` or `Zulu` |
| `DepartureDate` | string | `M/D/YYYY` format |
| `DepartureTime` | string | `HH:MM` (24hr) |
| `AirCraftTail` | string | Tail number (optional) |

Response:
```json
{
  "arrDate": "3/16/2026",
  "arrTime": "02:28"
}
```

### Traveler Search

**POST `/TravelerTypeAhead/Search`**

Search for travelers (crew/passengers) in the company directory.

| Param | Type | Description |
|-------|------|-------------|
| `searchText` | string | Name search query |
| `maxResults` | int | Max results |

Response: Array of `{Id, Text}` similar to airport search.

### Trip Creation

Trip creation is a multi-step process that builds up a trip on the server via a session-based form.

#### Step 1: Initialize Trip Form

**GET `/FlightBridgeTrip/CreateFlightBridgeTrip`** — new trip

**GET `/FlightBridgeTrip/EditFlightBridgeTrip?tripId={fbTripId}&BackToTripId={fbTripId}`** — edit existing trip

Both return an HTML form. The leg GUIDs are in `leg-nav-{guid}` elements. The edit form pre-populates with existing data; the same SaveLeg/SubmitFlightBridgeTrip flow updates the trip in place (same trip ID returned in redirect).

#### Step 2: Save Leg

**POST `/FlightBridgeTrip/SaveLeg`**

Save a leg to the in-progress trip.

| Param | Type | Description |
|-------|------|-------------|
| `Number` | string | Leg GUID (from form) |
| `DepartureAirportName` | string | Full display name from airport search |
| `DepartureAirportId` | int | FB airport ID |
| `ArrivalAirportName` | string | Full display name from airport search |
| `ArrivalAirportId` | int | FB airport ID |
| `DepartureFboName` | string | FBO display name |
| `DepartureFboId` | int | `CompanyServiceAirportId` from FBO lookup |
| `ArrivalFboName` | string | FBO display name |
| `ArrivalFboId` | int | `CompanyServiceAirportId` from FBO lookup |
| `DepartureDate` | string | `M/D/YYYY` |
| `DepartureTime` | string | `HH:MM` (24hr) |
| `ArrivalDate` | string | `M/D/YYYY` |
| `ArrivalTime` | string | `HH:MM` (24hr) |
| `FarPart` | string | FAR part (`91`, `135`, etc.) |
| `Callsign` | string | Flight callsign (optional) |
| `PassengerNumber` | string | Passenger name (optional) |
| `PassengerNumberId` | string | Passenger ID (optional) |
| `CrewNumber` | string | Crew member name |
| `CrewNumberId` | string | Crew member ID (optional) |
| `CrewType` | string | `PIC`, `SIC`, etc. |
| `OtherDepartureFbo` | string | Custom FBO name (default: `Enter FBO Name...`) |
| `OtherArrivalFbo` | string | Custom FBO name (default: `Enter FBO Name...`) |
| `TripPurposeId` | int | Purpose ID (`-1` for none) |

Response:
```json
{
  "Status": "Success"
}
```

#### Step 3: Add Additional Legs

**POST `/FlightBridgeTrip/AddLeg`**

Returns HTML partial for a new leg form (with a new `Number` GUID).

#### Step 4: Add Travelers

**POST `/FlightBridgeTrip/AddTraveler`**

| Param | Type | Description |
|-------|------|-------------|
| `Number` | string | Traveler ID (optional, blank for new) |
| `FirstName` | string | First name |
| `LastName` | string | Last name |
| `TravelerType` | int | `1` = passenger, `2` = crew |
| `CrewType` | string | `PIC`, `SIC`, etc. (if crew) |

#### Step 5: Submit Trip

**POST `/FlightBridgeTrip/SubmitFlightBridgeTrip`**

Submit the completed trip to FlightBridge for processing.

| Param | Type | Description |
|-------|------|-------------|
| `TripNumber` | string | Trip number (e.g., `0000002`) |
| `AircraftTailNumber` | string | Tail number |
| `LocalZuluType` | string | `Local` or `Zulu` |
| `LastEditedDateTime` | string | Timestamp |
| `Number` | string | Last leg GUID |
| (+ all SaveLeg fields for the current/last leg) | | |

Response: 302 redirect to `/FlightCenter/TripsLink?tripToOpen={tripId}`

The `tripId` in the redirect is the FlightBridge trip ID.

### Service Provider Directory

**GET `/DirectoryListing/SelectProvider`**

List available service providers for an airport within a submitted trip.

| Param | Type | Description |
|-------|------|-------------|
| `legId` | int | FlightBridge leg ID (from ROTrip HTML) |
| `legNumber` | string | Leg GUID |
| `airportId` | int | FB airport ID |
| `tripId` | int | FlightBridge trip ID |
| `arrivalOrDeparture` | string | `Arrival` or `Departure` |
| `providerType` | string | `Ground`, `Caterer`, `Helicopter`, or `AirportServices` |
| `BackToTripId` | int | Same as tripId |
| `BackToLegNumber` | string | Same as legNumber |

Response: HTML page containing provider forms. Each provider has:
- `OrderProviderName` — company name
- `OrderProviderPhone` — phone number
- `OrderProviderEmail` — email address
- `ProviderCsaId` — company service airport ID
- `ProviderCompanyId` — global company ID
- `ProviderRegistrationStatus` — `Registered` or not
- `GroundOrderSubType` — `CarService` for ground transport

### Create Ground Transport Order

**POST `/Order/GroundOrderAddEdit`**

Create a car service order with a specific provider.

| Param | Type | Description |
|-------|------|-------------|
| `GroundOrderSubType` | string | `CarService` |
| `OrderForTravelerType` | string | Traveler type (optional) |
| `ProviderCsaId` | int | Provider's CSA ID |
| `ProviderCompanyId` | int | Provider's company ID |
| `OrderProviderName` | string | Provider name |
| `OrderLegId` | int | FlightBridge leg ID |
| `ArrivalOrDeparture` | string | `Arrival` or `Departure` |
| `ProviderRegistrationStatus` | string | `Registered` |
| `OrderProviderEmail` | string | Provider email |
| `OrderProviderPhone` | string | Provider phone |
| `submit` | string | `Select` |

Response: HTML order form for the selected provider.

### Read-Only Trip View

**GET `/ROTrip/Trip?tripId={tripId}`**

View a submitted trip. Returns HTML.

**GET `/ROTrip/TripHeading?tripNumber={num}&tripId={tripId}`**

Get trip heading/summary.

### Trip List

**GET `/FlightCenter/Trips`**

View all trips (HTML page).

### Order Search

**GET `/Order/Search`**

Search orders across trips (HTML page).

## Real-Time Updates

FlightBridge uses SignalR for real-time updates. Three hubs:
- `companyprefshub` — company preferences changes
- `triphub` — trip updates
- `watchlisthub` — watchlist notifications

Connect via `/signalr/negotiate` → `/signalr/connect` (WebSocket).

## Known FlightBridge Internal IDs

These are IDs observed in captured traffic and may vary by account:

- Airport IDs are FlightBridge-internal, not ICAO codes. Use `/AirportTypeAhead/Search` to resolve.
- FBO IDs use `CompanyServiceAirportId` (airport-specific) not `CompanyId` (global).
- `TripPurposeId: -1` means "none specified".

## Notes

- All POST endpoints use `application/x-www-form-urlencoded` (not JSON).
- The trip creation flow is stateful — the server tracks the in-progress trip via session. Legs must be saved sequentially.
- The `Number` field on legs is a GUID generated client-side.
- FlightBridge's `CalculateArrivalDateTime` uses its own flight time estimates — our planner's calculated times are more accurate for N785QS since we know the aircraft profile.
- FBO names from FlightBridge may not match AirNav names exactly.
