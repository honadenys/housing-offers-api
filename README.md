# Housing Offers API

Laravel 12 API for importing housing offers, finding the cheapest available offer per property, and making reservations. Uses PHP 8.3, MySQL 8.4, and Redis.

Assignment: [docs/assignment.md](docs/assignment.md).

## Setup

Requires Docker Compose and Make.

```bash
make setup
```

This copies `.env.example` if needed, builds the image, installs dependencies, generates the application key, runs migrations, seeds `supplier-a` and `supplier-b`, and starts the API, queue worker, and scheduler.

API: http://localhost:8088. Set `APP_PORT` in `.env` to use another port.

```bash
make up         # Start services
make down       # Stop services, keep database data
make install    # Install dependencies after pulling changes
make migrate    # Run migrations
make seed       # Seed suppliers
make worker     # Start the Redis queue worker
make logs       # Follow logs
```

After changing job code, restart the long-running processes:

```bash
docker compose restart worker scheduler
```

## Tests

```bash
make test
make test ARGS='--filter=reservation'
make check
```

Pest uses a separate MySQL database, `housing_api_test`. `make check` also runs Composer validation, Pint, PHPStan, Rector (dry run), and Deptrac. Run individual checks with `make lint`, `make phpstan`, `make rector`, or `make deptrac`.

## Import idempotency

The database enforces unique `(supplier_id, external_import_id)` values. Repeating an import returns the existing record without queuing it again. The job is dispatched after commit and processes offers in a transaction.

Offers are unique by `(supplier_id, external_id)` and properties by `code`. Later imports update existing offers; older `sent_at` values are ignored. Completed imports are not processed again if the job is redelivered.

## Concurrent reservations

Reservations run inside a transaction. The offer is locked with `SELECT ... FOR UPDATE` before checking expiry and stock, creating the reservation, and decrementing availability. A second request waits for the lock, then sees the updated stock and receives `409` if the last unit was taken.

Repeating the same `client_reference` with the same offer and customer data returns the existing reservation without consuming another unit. Conflicting reuse returns `409`.
