# Housing Offers API

Laravel 12 REST API for asynchronous offer imports, property search, and safe reservations.

Assignment: [original document](docs/assignment.md). Git history is available locally; GitHub publication will follow separately.

## Stack

- PHP 8.2+
- Laravel 12
- MySQL 8.4
- Redis 7
- Eloquent, Form Requests, API Resources, queued Jobs, factories, seeders, feature tests

HTTP uses thin single-action handlers backed by focused services.

## Setup with Docker

Requirements: Docker with Compose support.

```bash
cp .env.example .env
docker compose build app
docker compose up -d mysql redis
docker compose run --rm app composer install --no-interaction
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed --force
docker compose up -d app worker scheduler
```

Compose starts the import worker and recovery scheduler as separate services. Check their logs:

```bash
docker compose logs -f worker scheduler
```

To run either process manually for debugging:

```bash
docker compose stop worker scheduler
docker compose run --rm worker php artisan queue:work redis --queue=imports --tries=1 --timeout=60
docker compose run --rm scheduler php artisan schedule:work
```

Run each command in its own terminal. Restart the background services afterward with `docker compose up -d worker scheduler`. After code changes, restart them with `docker compose restart worker scheduler`.

The Docker development server uses an absolute document root and router. Rebuild after Dockerfile changes with `docker compose up -d --build app`. The image includes `pcntl` so queue worker timeouts are enforced. This PHP server is for local development.

API listens on `http://localhost:8088`; health endpoint is `/up`. Set `APP_PORT` in `.env` to change the host port. MySQL and Redis are reachable only within the Compose network.

Stop services without removing the database volume:

```bash
docker compose down
```

## API

### Create import

`POST /api/imports` returns `202 Accepted`. Supplier codes seeded by default: `supplier-a`, `supplier-b`.

```bash
curl -X POST http://localhost:8088/api/imports \
  -H 'Content-Type: application/json' \
  -d '{
    "supplier": "supplier-a",
    "external_import_id": "import-2026-09-01-001",
    "sent_at": "2026-09-01T10:00:00Z",
    "offers": [
      {
        "external_id": "offer-a-10001",
        "property": {
          "code": "BCN-0001",
          "name": "Apartment near Sagrada Familia",
          "city": "Barcelona"
        },
        "check_in": "2026-10-10",
        "check_out": "2026-10-15",
        "max_guests": 4,
        "price": 72500,
        "currency": "EUR",
        "available_units": 2,
        "expires_at": "2026-09-10T23:59:59Z"
      }
    ]
  }'
```

`price` is an integer in minor currency units. Currency belongs to each offer; mixed-currency imports are accepted. Import validation rejects duplicate external offer IDs, invalid date order, non-list offer payloads, and quantities exceeding MySQL unsigned integer bounds.

### Read import status

`GET /api/imports/{import}` returns current status and progress:

```bash
curl http://localhost:8088/api/imports/1
```

Statuses: `pending`, `processing`, `completed`, `failed`.

### Search properties

`GET /api/properties` requires exact `check_in`, `check_out`, and `guests`. `city` is optional. `per_page` is limited to 100.

```bash
curl 'http://localhost:8088/api/properties?city=Barcelona&check_in=2026-10-10&check_out=2026-10-15&guests=2&per_page=20'
```

The database uses `ROW_NUMBER() OVER (PARTITION BY property_id ORDER BY price, id)` to select one cheapest current offer per property. Availability and expiry filters, sorting, and pagination stay in SQL. Response fields are `data`, `next`, `prev`, and `per_page`.

### Reserve offer

`POST /api/offers/{offer}/reservations` reserves one unit:

```bash
curl -X POST http://localhost:8088/api/offers/1/reservations \
  -H 'Content-Type: application/json' \
  -d '{
    "client_reference": "web-order-9f782b1c",
    "customer_name": "John Smith",
    "customer_email": "john@example.com"
  }'
```

First request returns `201`. Repeating same `client_reference` with same offer and customer data returns existing reservation with `200`, without another decrement. Reusing it with another offer or customer data returns `409`.

Expired or out-of-stock offers return `409`.

## Import idempotency and reservation locking

Imports have database unique key on `(supplier_id, external_import_id)`. Duplicate requests return existing import and do not dispatch another job. `ProcessImport` is queued after transaction commit and is unique by import ID.

Import offer writes run in one transaction. Properties are created or updated by property code. Offers are created or fully updated by `(supplier_id, external_id)`, including current import association.

If Redis dispatch fails after commit, the API still returns `202` for the durably saved pending import and logs the failure. `imports:recover` runs every minute via the scheduler: pending imports older than five minutes are requeued, while abandoned processing imports are marked failed. Run it manually with `docker compose exec app php artisan imports:recover`. The unique dispatch lock lasts 120 seconds; the worker timeout is 60 seconds and Redis retry interval is 90 seconds. Duplicate HTTP requests never dispatch another job. Recovery may redeliver a job, but the import row lock and terminal status check prevent applying a completed import twice. Worker failures also update the import through the job's `failed()` callback. Failed imports remain terminal; corrected data must use a new external import ID.

Reservation writes run in transaction. Offer row is read with `SELECT ... FOR UPDATE`, then expiry and availability are checked again while lock is held. Concurrent transaction waits for lock, reads new remaining count, and gets `409` when final unit was already reserved. This prevents double booking.

## Tests

Pest 3 with the Laravel plugin keeps compatibility with PHP 8.2. Feature tests use `test(...)`, `$this->getJson(...)`, and `$this->postJson(...)` through Laravel’s HTTP kernel and a real test database. The booking journey exercises import, status, search, reservation, retry, and sold-out behavior together. It uses the synchronous test queue; production uses Redis. Job-specific tests cover failure and redelivery paths.

```bash
bin/test
```

This starts MySQL and Redis, creates `housing_api_test`, and runs Pest against that dedicated database. Pass Pest options through, for example `bin/test --filter=reservation`. The application database is untouched.

Feature coverage includes import validation and idempotency, successful and failed processing, offer upsert, SQL search filters and pagination, reservation decrement, expiry/stock conflicts, idempotent retries, and reference conflicts.

Feature tests fake dispatch or call jobs directly. They cover malformed inputs, duplicate offer IDs, rollback after a later offer fails, dispatch recovery, worker failure, and references reused against another offer. Live Redis worker verification is separate from the automated suite.

## Scope

No authentication, cancellation, hold expiration, FX conversion, or external supplier integration. One reservation consumes one unit. Search orders by the supplied integer price, as specified. No exchange-rate conversion is applied; meaningful monetary comparisons assume offers use the same currency. Supplier updates replace availability with their reported count. `sent_at` is recorded as supplier metadata; the assignment defines no stale-update rejection rule.

## Quality checks

```bash
docker compose exec app vendor/bin/pint --test
docker compose exec app composer validate --strict
```

Tests use isolated data and cover supplier-scoped identifiers, shared properties, mixed currencies, malformed payloads, storage bounds, and completed-job redelivery after stock changes. Scaffold example tests are omitted.

GitHub Actions runs Composer validation, Pint, and the same MySQL suite after publication. CI has not run remotely yet.

Locally verified on PHP 8.3.33, Laravel 12.69.2 and MySQL 8.4: 32 Pest tests (179 assertions), Pint, Composer validation, and a real Redis worker flow from HTTP import through reservation and sold-out rejection. Parallel reservation processes are not part of this verification.
