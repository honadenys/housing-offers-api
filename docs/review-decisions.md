# Review decisions

| Review item | Decision |
| --- | --- |
| 1.1 City filter before ranking | Applied, including the `city=0` regression. |
| 1.2 Simple pagination | Applied; test verifies one ranked query without a count query. |
| 1.3 Date-first index | Added `(check_in, check_out, expires_at)`. Retained property-first index for property lookups. An index does not guarantee the optimizer will use it on every dataset. |
| 2.1 Batch imports | Applied to properties and offers, at most 500 offers per batch, inside one transaction. A 501-offer test checks bounded query count and rollback across batches. |
| 2.2 Stale updates | Applied per offer. Supplier row lock protects comparison and write; `sent_at`, then import ID, determines the winning version. |
| 3.1 Typed search inputs | Applied as a readonly value object with immutable dates. |
| 3.2 HTTP pagination links | Moved query preservation to the action; page number is an explicit service input. |
| 4.1 Atomic decrement alternative | Retained `FOR UPDATE`. Conditional updates also acquire InnoDB row locks; switching is not automatically a contention improvement. Real parallel HTTP-process tests prove last-unit safety, retry idempotency, and expiry rechecks. |
| 5.1 Object storage | Deferred. Durable database payloads keep acceptance atomic. External storage adds orphan cleanup and recovery paths without a measured payload-storage bottleneck. |
| 5.2 Currency comparisons | Added optional uppercase `currency` filter. Mandatory currency would break the supplied endpoint example. No FX rates or conversion policy were provided; unfiltered ordering remains raw price. |

The source review's 10–100x speedups and millisecond estimates are not benchmark results. No such performance claims are made here. Query count and behavior are verified; production load and dataset-specific query plans require separate measurement.

## Validation and code cleanup

- Native Laravel date rules replace manual date parsing and comparisons.
- Search rejects past check-in dates and more than 30 guests, as requested.
- Import currency must already be uppercase; it is no longer silently normalized.
- Conditional native date rules guard malformed sibling fields. Laravel's date comparison can throw when the referenced date is an array; HTTP regressions verify 422 responses.
- Import resources use the model's enum cast directly.
- Removed commented-out imports and redundant explanatory comments.
- Opaque identifiers use case-sensitive database comparisons, matching batch lookup keys.

## Verification boundaries

`bin/test` runs feature and parallel-process integration tests on MySQL. Concurrency tests query MySQL lock-wait metadata before releasing the test-held lock; merely starting two processes would not prove overlap. The test database is forced separately from the application database.

GitHub publication and hosted CI remain separate from local verification. The supplied review documents are retained as inputs; this file records the implemented decisions.

Latest local checks: 44 Pest tests / 251 assertions passed on MySQL, including three parallel-process scenarios. Pint and Composer validation passed. A live Redis-worker flow passed through import, currency-filtered search, reservation, an older import arriving afterward, retry, and sold-out rejection. A full suite rerun preserved the application database's seeded suppliers.
