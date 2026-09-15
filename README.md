# reservation-search

[![check](https://github.com/typllukas/reservation-search/actions/workflows/check.yml/badge.svg)](https://github.com/typllukas/reservation-search/actions/workflows/check.yml)

Back office search over a million reservations and their guests in a hotel system. Learning project
for Elasticsearch, not a product. Plain Symfony 7.4 on PHP 8.5, MariaDB as the source of truth,
Elasticsearch 9.1 as the only read path for search, PHPStan level 10.

For reception and the reservations desk: one box over guest name, reservation number, phone, e-mail,
hotel and note, then facets narrow the list. Czech names drive the design: diacritics folded, one
typo allowed.

## Measurements

Same data in both stores, 1,000,006 rows, median of 7 runs. Method and full tables below.

- name search, one typo allowed: **1 ms** in Elasticsearch, 222 ms with `LIKE '%novak%'` in MariaDB
- filtered list, no text (one hotel, one status, arrival range, `LIMIT 20`): **0.2 ms** in MariaDB
  with a composite index, 1 ms in Elasticsearch. MariaDB is faster here and the application still
  sends it to Elasticsearch, because search has one read path.

![Demo page: search "nova", filtered to confirmed apartment reservations. 16 617 hits of 1 000 006,
facets with counts, highlighted matches, Elasticsearch query body next to the
results](docs/screenshot.png)

## What it is not

- Not availability search (dates × rooms × rate plans) - it's different issue
- Not a front end. The demo page is one static HTML file, no build step, no framework. It exists so
  the search can be seen.
- Not a product. No authentication, no administration, no multi-tenancy, no complex write paths.

## Endpoints

```
GET   /api/reservations               search, facets, paging
GET   /api/reservations/{id}/explain  score of one hit, from `_explain` (dev only)
GET   /api/reservations/query-preview the Elasticsearch query preview (dev only)
GET   /api/guests/suggest             guest name suggestions
PATCH /api/reservations/{id}          change status and payment (simple write path)
```

## What it does

- **Full text over Czech names.** Folding and typo tolerance, so `novak` finds `Novák` and `nowak`
  finds it too.
- **Facets** on status, source, hotel and room kind. Counts survive filtering, so a selection can be
  clicked off again.
- **Paging:** one opaque cursor, two strategies inside it, `search_after` on a stable sort, `from`
  on relevance.
- **Index stays in sync.** A status change is searchable in the same request. Nightly reindex
  rebuilds everything behind an alias, no downtime: ids streamed, 1000 reservations hydrated per
  batch, peak 75 MB over the million rows against the PHP default limit of 128 MB.
- **Million row data set** from a deterministic generator: Czech and foreign names, notes in Czech
  and English, phone prefixes by origin. The measurements below run on it.

## Running it

Everything runs in Docker, nothing on the host, so the only thing you need installed is Docker with
the Compose plugin. GNU make is not a requirement, it only shortens the commands.

```bash
make setup   # containers, dependencies, schema, 10 000 reservations, both indices
```

- demo page: `http://127.0.0.1:8081`, search `novak`, those cases are guaranteed by the generator
- Kibana Dev Tools: `http://127.0.0.1:5602`

The same without make:

```bash
docker compose up -d --wait
docker compose exec php composer install
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -e APP_DEBUG=0 php php bin/console reservation-search:dev:generate-data \
    --hotels=220 --guests=20000 --reservations=10000
docker compose exec -e APP_DEBUG=0 php php bin/console reservation-search:index:reindex all
```

`APP_DEBUG=0` on the last two because Doctrine's debug middleware keeps every query it runs. Add
`-u "$(id -u):$(id -g)"` to the `exec` lines to keep the written files owned by you, which is what
the Makefile does.

The million rows the measurements were taken on:

- `RESERVATIONS=1000000 make reset`
- the generator appends, so `reset` drops the schema and seeds from scratch
- the generator is the slow part, around half an hour. Indexing the million afterwards is 2.5
  minutes, peak 75 MB.

### Checks

`make check` is the whole gate and CI runs the same steps:

- phpcs on the Doctrine standard
- mapping check, fails on any indexed field that no query reads
- PHPStan level 10
- Rector
- PHPUnit, including integration tests against a real Elasticsearch

Four project PHPStan rules in `tools/Architecture/`:

- three hold the write path together: a reservation changes in one class only, that class alone
  announces the change to the index, every caller of it flushes first. PHP enforces none of this,
  and a second writer leaves the index stale with no error.
- one keeps queries in the query builder, because a DQL string stops being checked as soon as it is
  not constant.
- each rule has a test. It analyses a sample file with both the calls the rule must report and the
  calls it must leave alone. Expected violations are pinned to line numbers.

`tools/CodingStandard/` holds one phpcs sniff, short chains stay on one line.

Rest of the targets in `make help`, two worth knowing:

- `make check-drift` newest rows in the database that the index does not hold under the same id
- `make es-indices` versioned indices behind the two aliases

Retention is a command, no schedule shipped with it:

```bash
docker compose exec php php bin/console \
    reservation-search:cron:purge-reservations --before="midnight -5 years" --dry-run
```

It deletes reservations whose stay ended before that date, `--dry-run` only counts them. It goes
through the application and not through SQL, because a tombstone has to be written first. That is
how a rebuilt index learns the row is gone.

## Measured against MariaDB

1,000,006 reservations, median of 7 runs, `innodb_buffer_pool_size` raised to 2 GB so the comparison
is not rigged. MariaDB time from `SHOW PROFILES`, Elasticsearch from `took`, both server time
without the network. Reproduce with `./bench.sh`.

Name in text. The match counts differ because the queries mean different things:

| query | time | matches | what it finds |
|---|---|---|---|
| `LIKE '%novak%'` | 222 ms | 19,152 | Novák and Nováková, it is a substring |
| `MATCH AGAINST 'novak'` | 19 ms | 9,134 | Novák only, the whole word has to match |
| `MATCH AGAINST 'novak*'` | 35 ms | 19,152 | Novák and Nováková, word prefix |
| **Elasticsearch, one typo allowed** | **1 ms** | 14,375 | Novák **and Nowak**, which no query above reaches |

Filtered list, no text: one hotel, one status, an arrival range, sorted, `LIMIT 20`:

| query | time | matches |
|---|---|---|
| **MariaDB with a composite index** | **0.2 ms** | 530 |
| Elasticsearch | 1 ms | 530 |

The second table is the interesting one. MariaDB with the right index wins, and by more than the
numbers show, because `took` does not resolve below a millisecond. So this project does not claim
Elasticsearch belongs in front of every query. A comparison where it won every row would not be
worth reading.

### The application's own queries

The 1 ms above is `bench.sh`: `size 20`, `_source: false`, no facets, no highlight, no sort. The
application sends more than that, so these are its real queries, taken from
`/api/reservations/query-preview`. Median of 7 again, single node, 1 GB heap, one user, warm cache.
End to end is wall clock from inside the php container and includes Symfony in dev mode, around
10 ms on top of the Elasticsearch time.

- `novak` with facets, highlight and sort: **15 ms** in Elasticsearch, 33 ms end to end. The same
  query without the aggregations takes 7 ms, so the facets cost about half of it.
- typeahead on `nov`: **11 ms** end to end. It runs on every keystroke, so this is the number that
  decides whether the box is usable.
- empty search, facets over the whole million with nothing filtering them: **195 ms**. Slowest path
  in the application and the first thing the demo page does.
- relevance paging at the edge of the window, `from=9980`: **51 ms** against 14 ms at `from=0`.
- index on disk: **290 MB** for the million reservations with their rooms, 3.4 MB for the 20 000
  guests. At a few million reservations a year this is the number to multiply, not the query times.

## Decisions

- **Diacritics alone are not a reason for Elasticsearch.** The column collation is accent
  insensitive, `LIKE '%novak%'` finds `Novák` too. The real differences are precision, speed at
  volume and relevance order.
- **Fuzziness on one field, not on the whole query.** Applied to everything, `novak` returned 50,076
  hits instead of 14,375. A hotel named `Penzion Nová Ves` tokenizes to `nova`, one edit away, so 7
  hits in 10 had nothing to do with the guest.
- **A facet over a `nested` field has two silent traps.** Without the wrapper it returns empty
  buckets and no error. With it, it counts rooms and not reservations. Only `reverse_nested` gives
  the right number.
- **One write funnel, the index is a projection.** Drift has one direction only and a full reindex
  is the fix.
- **The `sync` transport is a trade.** It buys read your writes, it gives up the outbox. One
  mechanism cannot do both.
- **No data fixtures.** A search test needs documents in an index, not rows in a table, so fixtures
  would only move the work: load the rows, then reindex per test class. Each test class builds its
  few entities, runs them through the same `ReservationDocumentFactory` as the application and
  indexes them into its own index. It drops that index afterwards. No shared state between test
  classes.
- **Document shape is typed on write, read back through `ResponseBody`.** `GuestDocumentFactory`
  builds the document from typed getters, so PHPStan checks the literal against the declared shape.
  The same array coming back from the index proves nothing, so the read side has no
  `@phpstan-var GuestDocument` on it. That annotation silences the analyser without checking
  anything. A missing field then comes out as a `TypeError` on a DTO argument instead of naming the
  key.
- **One identifier, two formats.** MariaDB stores the ULID as `BINARY(16)`, that key sits in every
  row, index and foreign key. JSON has no binary type, so the index holds base32 and the conversion
  happens once, in the document factory. `CHAR(26)` on both sides would unify it and cost 10 bytes
  per identifier everywhere.

## Not here, on purpose

Vector search, learning to rank, reporting `date_histogram`, point in time paging, a read/write
alias pair, authentication, a real front end. Budget decisions, not oversights, and I can say what
each would cost.

The index name is not configurable either. The cluster is `ELASTICSEARCH_DSN` and the test suite
builds its own index name from a parameter, but nothing outside the container renames
`reservations`. The alias is this application's table name, not its connection string. Making it
configurable costs one environment variable plus the paragraph about what happens when the value is
wrong: Elasticsearch creates the missing index on the first write and only the search shows it.

## Known limits

- `asciifolding` maps `Müller` to `muller`, so that guest is not found as *Mueller*. Doing it right
  needs the language and names do not carry it.
- The note is stemmed as Czech and, through the sub-field `note.english`, as English. Each query
  word goes through both analyzers and matches in the one it belongs to, no language detection. A
  German note matches only its exact word form.
- A guest with no reservation is suggested by the typeahead and has nothing to show.
- A phone is searched as typed and with `420` in front, so a Czech number is found both ways. A
  foreign number in its national form is not, and if a Czech guest holds the same national number,
  that is who comes back. The row shows the full number. Finding where the code ends inside a stored
  number needs a prefix table, which is what libphonenumber is for.
- A phone fragment under 6 digits is not searched as a phone at all. Under
  `minimum_should_match: 1` a short prefix adds documents instead of ranking them: before that
  guard, `Novák 4` returned 863,962 rows of the million and every facet count described them.
- More words widen the result instead of narrowing it. Each text branch matches any of the words, so
  `Novák` finds 24,394 reservations and `Novák Praha` finds 157,651. Requiring every word reads
  better here but loses the guest whose hotel the operator only half remembers.
- Closed dimensions (status, source, room kind) are sized to their own case count and cannot
  truncate. Hotels are open ended: top 20 of 220, and the response carries the reservations left
  outside them, so the client knows it is a shortlist. A hotel selected from outside the top 20
  still filters, but has no count next to it. A second aggregation on the selected keys would fix
  that.
- `IndexDefinitionInterface` describes two things: the mapping, and where the documents come from.
  Only the reindexer needs both halves, so the mapping check, the drift check and the test trait
  build a `ReservationIndexDefinition` with two repositories and an `EntityManager` they never use.
  Splitting it costs the reindexer one more constructor argument.
- Elasticsearch down means 503 from the API. No fallback to SQL, `LIKE` would silently return
  different results.
- Exact figures about money belong in the database, not in a projection that catches up afterwards.
- Two edits to one reservation in the same moment can leave the older one in the index, the write
  carries no version to compare. `updated_at` cannot be that version while the column is `DATETIME`
  and both edits share the same second, so this wants `DATETIME(3)` first.
- A purge interrupted between the rows and the index leaves documents for reservations that are
  gone. The tombstone and the delete share a transaction, so the database is never half purged.
  `make check-drift` reads the newest rows and the purge takes the oldest ones, so the reindex is
  what clears them.

Runs on Elasticsearch 9.1, but nothing here needs more than 7.10. That is where `case_insensitive`
on a prefix query arrived and it is the newest feature the search uses.
