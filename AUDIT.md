# Symbiot audit — 2026-08-08

## Scope
Static review and refactor of the Laravel tracking stack: authorization, session lifecycle, API/web consistency, planned routes, route replay, model mass-assignment, configuration secrets, and regression coverage.

## Completed
- Added a shared `TrackingSessionService` used by both web and API tracking flows.
- Centralized session start/stop/point ownership checks and active-session reuse.
- Added row locking around session creation to reduce duplicate active sessions under concurrent starts.
- Prevented tracking writes from targeting another user's session.
- Prevented starting a web/API session with another user's device.
- Made stop idempotent and queue post-processing after completion.
- Added strict GPS/telemetry validation, including latitude/longitude, speed, heading and battery bounds.
- Added `battery` to `Tracking` mass assignment/casts.
- Added `User::devices()` relationship.
- Implemented the previously empty `POST /api/v1/tracking/planned` endpoint.
- Planned routes are stored as `planned` sessions plus ordered `planned` tracking points and GeoJSON.
- Implemented missing API tracking read/replay/delete endpoints referenced by the routes.
- Unified web/API route replay around a GeoJSON Feature + LineString representation.
- Added date filtering to the web sessions endpoint because the existing tests already expected it.
- Removed the unused scaffold `TrackingSessionController` that returned HTTP 501 and was not referenced by any route.
- Removed credential-like values from `.env.example`; any real credentials that were ever committed should be rotated.
- Updated stale route/show tests and added regression coverage for planned routes and the API tracking lifecycle.
- PHP syntax validation passes for all application, route, migration and test PHP files (73 files checked).

## Verification limitation
Full PHPUnit/quality checks could not be executed in this audit environment because the uploaded project does not contain `vendor/` and Composer is not installed. The final project should be verified with:

```bash
composer install
php artisan test
composer run lint
```

## Remaining follow-up
- Run the complete PHPUnit suite in a normal Composer-enabled environment and fix any framework/database-specific failures that surface.
- Consider a database-level uniqueness strategy for one active session per user if the production database supports partial/filtered unique indexes; the application-level row lock is the current portable safeguard.
- Review mobile client adoption of the API response envelope (`data`) before changing public API contracts further.

## 2026-08-08 — tracking concurrency hardening
- Made point insertion and session stop transactional with row-level locking to prevent a GPS point from racing with session completion.
- Made processing dispatch occur after the stop transaction commits.
- Made `ProcessTrackingSessionJob` unique per session and skip sessions already marked as processed.
- Added regression coverage for post-stop point rejection and processing-job dispatch.

## 2026-08-08 — API/device + DB hardening

- Device UUID is now enforced globally unique at the database level, matching the API ownership semantics.
- Device registration handles the concurrent unique-constraint race instead of relying only on an application-level pre-check.
- Added composite indexes for user/device lookup and active-session queries.
- Added a unique `(tracking_session_id, sequence)` constraint for planned tracking points.
- Added named rate limits for device registration, tracking start/stop, and GPS location ingestion.
- Replaced `whereDate(started_at, ...)` session filtering with an index-friendly datetime range.
- Added regression coverage for device/location rate limiting.
- The new migration intentionally aborts with a clear message if production data already contains duplicate device UUIDs or duplicate non-null tracking sequences; those records must be reconciled before enforcing the constraints.

Full PHPUnit execution remains pending because this audit environment does not contain `vendor/`/Composer.

## Track processing hardening — 2026-08-08

- Processing now sorts GPS points deterministically by `tracked_at`, then `id`.
- Haversine input is clamped to `[0, 1]` before `asin()` to avoid floating-point NaN edge cases.
- The processing job refuses to process sessions that are not `completed`.
- Job and route queries use `id` as a deterministic tie-breaker when timestamps match.
- Added regression coverage for timestamp ordering, antipodal distance finiteness, and active-session job safety.
- PHPUnit could not be executed in the audit environment because `vendor/` is not present.

## GPS ingestion hardening (v7)
- Added `StoreTrackingPointRequest` shared by API and web GPS point ingestion.
- Normalizes `tracked_at` to UTC and rejects points older than `TRACKING_POINT_MAX_AGE_HOURS` (default 48h) or more than `TRACKING_POINT_MAX_FUTURE_MINUTES` (default 5m) in the future.
- Added DB uniqueness for `(tracking_session_id, tracked_at, latitude, longitude)` to make network retries idempotent.
- Added duplicate detection before insert and a unique-constraint recovery path for concurrent retries.
- Added configurable tracking timestamp limits to `config/tracking.php` and `.env.example`.
- Added feature tests for stale/future timestamps and identical retry payloads.
