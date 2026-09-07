# Raw results

The unedited output of the run written up in
[`docs/LOAD_TEST_REPORT.md`](../../../docs/LOAD_TEST_REPORT.md), 29 August 2026.

* `suite-a/` — one `artisan serve` process (single-threaded).
* `suite-b/` — seven `artisan serve` workers, one MySQL database (real parallelism).
* `confirmation/` — the 150-concurrency re-run that classified refusals by the
  `X-RateLimit-Rejected` header rather than by redirect target.
* `complete-race.json` — four simultaneous staff "Complete" clicks per order,
  three orders (the stock-deduction race).

Each `tier-N.json` carries per-request timings and outcomes. Each
`tier-N.integrity.tsv` is the matching database check, columns in this order:

```
tier  orders_created  distinct_order_numbers  order_items_created
orders_with_wrong_line_count  orders_with_wrong_total
stock_movements_added  inventory_delta
```

All test data was deleted afterwards; see §8 of the report.
