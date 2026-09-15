# reservation-search

[![check](https://github.com/typllukas/reservation-search/actions/workflows/check.yml/badge.svg)](https://github.com/typllukas/reservation-search/actions/workflows/check.yml)

Back office search over a million reservations and their guests in a hotel system. Learning project
for Elasticsearch, not a product. Plain Symfony 7.4 on PHP 8.5, MariaDB as the source of truth,
Elasticsearch 9.1 as the only read path for search, PHPStan level 10.

For reception and the reservations desk: one box over guest name, reservation number, phone, e-mail,
hotel and note, then facets narrow the list. Czech names drive the design: diacritics folded, one
typo allowed.

![Demo page: search "nova", filtered to confirmed apartment reservations. 16 617 hits of 1 000 006,
facets with counts, highlighted matches, Elasticsearch query body next to the
results](docs/screenshot.png)

## Why Elasticsearch

What MariaDB does not give here, or not in one query:

- hits ordered by how well they match, one score over guest name, hotel, note, number, e-mail and
  phone, each with its own weight
- typo tolerance: `novakk` and `novaj` find Novák, `LIKE '%novakk%'` and `MATCH AGAINST('novakk')`
  find nothing
- the hits and all four facet counts in one request, each facet counted as if its own selection were
  not applied. In SQL that is five queries
- the response marks what matched, `<mark>Novák</mark> Jan`
- the note is stemmed, `patro`, `patrech` and `patrům` are one token, plus an English sub-field for
  English notes

Measured, same 1,000,006 rows in both stores, median of 7 runs, tables below:

- name search with one typo: **1 ms**, against 222 ms for `LIKE '%novak%'`
- filtered list, no text: **0.2 ms** in MariaDB with a composite index, 1 ms here, under a
  millisecond apart and not worth a second read path

Not reasons: diacritics, the collation is accent insensitive anyway, and field weights, which MariaDB
does with several `MATCH` expressions summed.

At this size PostgreSQL would do the job, slower on the broad queries, where `ts_rank` reads every
candidate row and the facet counts are aggregations over the matches. It costs more once search is
tuned rather than typed in once: four ranking weight classes against a boost per field, highlighting
that re-parses the row on every query, an analyser change that reindexes the live table instead of
moving an alias.

## What it is not

- Not availability search (dates × rooms × rate plans), that is a different problem
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

- **Full text over Czech names.** Folding and typo tolerance, so `novak` finds `Novák` and `novakk`
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
the Compose plugin, optionally GNU Make for fast setup.

```bash
git clone https://github.com/typllukas/reservation-search.git
cd reservation-search
make setup   # containers, dependencies, schema, 10 000 reservations, both indices
```

Under two minutes on a fresh clone.

The million rows the measurements run on: `RESERVATIONS=1000000 make reset`, three and a half
minutes. The generator appends, so `reset` drops the schema and seeds from scratch.

- demo page: `http://127.0.0.1:8081`
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

### Worth trying in the demo page

- `novakk` or `prochaska`: the typo still puts Novák and Procházka on top
- `zielinski` finds Zieliński, folding works on foreign names too
- `790657612`, `790 657 612` and `+420790657612` are one and the same search
- `2026-000001` is a single reservation, `card` hits an English note, `parkovani` a Czech one
- click a status, then a room kind, then click them off, the counts follow the selection
- change a status in a reservation row: the list is searched again and the change is already in the
  index
- the `_search body` panel shows the query the page just sent. The `_explain` chip on a row breaks
  its score down (it only says something with text in the search box)

## Rules, tests and checks

- architectonical PHPStan rules: three guard the write path, one writer, one
  announcer, callers flush first
- 32 test classes, 148 methods. The integration and api tests run against a real Elasticsearch, each
  class building and dropping its own index
- PHPStan level 10, Rector, phpcs, all of it in CI
- two commands guard the index: one fails on an indexed field no query reads, the other reports rows
  the index does not hold

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

MariaDB wins the second table, by more than it shows, since `took` does not resolve below a
millisecond. Search still goes to Elasticsearch, which is the read model: a second one would put
filters, paging and relevance in two places, and under a millisecond does not pay for that.

### The application's own queries

The 1 ms above is `bench.sh`, a bare query. What the application really sends, median of 7, end to
end measured from inside the php container:

- `novak` with facets, highlight and sort: **15 ms**, 33 ms end to end. Without the aggregations
  7 ms, so the facets cost about half
- typeahead on `nov`: **11 ms** end to end, and it runs on every keystroke
- empty search, facets over the whole million: **195 ms**, the slowest path and the demo page's
  first request
- relevance paging at `from=9980`: **51 ms** against 14 ms at `from=0`
- index on disk: **290 MB** for the million reservations with their rooms, 3.4 MB for the guests

## Design notes

- fuzziness only on `guest.name`. Over the whole query `novak` gave 50,076 hits instead of 14,375,
  `Penzion Nová Ves` tokenizes to `nova`
- a facet over the `nested` rooms needs the wrapper and `reverse_nested`, otherwise empty buckets or
  room counts instead of reservation counts
- one write path, the index is a projection. Drift goes one way, a reindex fixes it
- `sync` transport: read your writes in the same request, no outbox

## Not here, on purpose

Budget decisions, not oversights:

- vector search and learning to rank
- reporting aggregations, a `date_histogram`
- point in time paging and a read/write alias pair
- authentication and a real front end
- a configurable index name. The cluster is `ELASTICSEARCH_DSN`, but the alias is this application's
  table name, not its connection string

## Known limits

- a foreign phone in its national form is not found. It is searched as typed and with `420` in
  front, and finding where a dialling code ends needs a prefix table, which is what libphonenumber
  is for
- a phone fragment under six digits is not searched as a phone. Before that guard `Novák 4` returned
  863,962 rows of the million
- more words widen the result instead of narrowing it, `Novák` 24,394 reservations against `Novák
  Praha` 157,651. Requiring every word would lose the guest whose hotel the operator half remembers
- hotels are open ended, so the facet shows the twenty commonest of 220. A hotel selected from
  outside them still filters, without a count beside it
- Elasticsearch down means 503. No fallback to SQL, `LIKE` would silently return different results
- two edits in the same second can leave the older one in the index. `updated_at` is `DATETIME` and
  cannot break the tie, so this wants `DATETIME(3)` first
- money is exact in the database, not in a projection that catches up afterwards

Runs on Elasticsearch 9.1, though nothing here needs more than 7.10, where `case_insensitive` on a
prefix query arrived.
