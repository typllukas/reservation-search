#!/usr/bin/env bash
# The figures in the README are measured here.
# MariaDB is timed by SHOW PROFILES and Elasticsearch by took, both server time without Docker or the network.
set -eu
RUNS=${RUNS:-7}
DC=${DC:-"docker compose"}
DB_NAME=${DB_NAME:-reservations}
DB_USER=${DB_USER:-root}
DB_PASSWORD=${DB_PASSWORD:-root}

sql()  { $DC exec -T mariadb mariadb -u"$DB_USER" -p"$DB_PASSWORD" -N -B "$DB_NAME" 2>/dev/null; }
one()  { printf '%s;\n' "$1" | sql | head -1; }
es()   { $DC exec -T elasticsearch curl -s -H 'Content-Type: application/json' "$@"; }
json() { python3 -c "import sys, json; print(json.load(sys.stdin)['$1'])"; }

# an interrupted run must not leave the index behind
drop_fulltext_index() { one "DROP INDEX ft_guest_name ON guest" >/dev/null 2>&1 || true; }
trap drop_fulltext_index EXIT

# median server time in ms, over RUNS repetitions of the same query in one session
time_sql() {
  { echo "SET profiling=1; SET profiling_history_size=100;"
    for _ in $(seq "$RUNS"); do printf '%s;\n' "$1"; done
    echo "SHOW PROFILES;"
  } | sql | awk -F'\t' 'NF==3 && $3 ~ /^SELECT/ {print $2}' | sort -n \
    | awk '{a[NR]=$1} END {if (NR) printf "%.1f", a[int((NR+1)/2)]*1000; else print "?"}'
}
time_es() {
  for _ in $(seq "$RUNS"); do
    es -XPOST "localhost:9200/reservations/_search" -d "$1" | json took
  done | sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]}'
}
row() { printf '  %-28s %6s ms   %s\n' "$1" "$2" "$3"; }

RESERVATION_COUNT=$(one 'SELECT COUNT(*) FROM reservation')
GUEST_COUNT=$(one 'SELECT COUNT(*) FROM guest')
DOCUMENT_COUNT=$(es 'localhost:9200/reservations/_count' | json count)
BUFFER_POOL_GB=$(one 'SELECT ROUND(@@innodb_buffer_pool_size/1073741824,1)')

echo "volume:  $RESERVATION_COUNT reservations · $GUEST_COUNT guests · index $DOCUMENT_COUNT documents"
echo "buffer pool: $BUFFER_POOL_GB GB · Elasticsearch heap 1 GB · median of $RUNS runs"
echo

echo "═══ CLASS A — searching for a name in text ═══"
LIKE_Q="SELECT r.id FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE g.name LIKE '%novak%' ORDER BY r.arrival LIMIT 20"
row "LIKE '%novak%'" "$(time_sql "$LIKE_Q")" "$(one "SELECT COUNT(*) FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE g.name LIKE '%novak%'") matches"

one "CREATE FULLTEXT INDEX ft_guest_name ON guest(name)" >/dev/null
M1="SELECT r.id FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE MATCH(g.name) AGAINST('novak' IN NATURAL LANGUAGE MODE) ORDER BY r.arrival LIMIT 20"
row "MATCH AGAINST 'novak'" "$(time_sql "$M1")" "$(one "SELECT COUNT(*) FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE MATCH(g.name) AGAINST('novak' IN NATURAL LANGUAGE MODE)") matches"
M2="SELECT r.id FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE MATCH(g.name) AGAINST('novak*' IN BOOLEAN MODE) ORDER BY r.arrival LIMIT 20"
row "MATCH AGAINST 'novak*'" "$(time_sql "$M2")" "$(one "SELECT COUNT(*) FROM reservation r JOIN guest g ON g.id=r.guest_id WHERE MATCH(g.name) AGAINST('novak*' IN BOOLEAN MODE)") matches"
drop_fulltext_index

ES_A='{"size":20,"_source":false,"query":{"match":{"guest.name":{"query":"novak","fuzziness":"AUTO"}}}}'
ES_A_COUNT=$(es -XPOST 'localhost:9200/reservations/_count' -d '{"query":{"match":{"guest.name":{"query":"novak","fuzziness":"AUTO"}}}}' | json count)
row "Elasticsearch" "$(time_es "$ES_A")" "$ES_A_COUNT matches"
echo

echo "═══ CLASS B — filtered list without text ═══"
HEX=$(one "SELECT HEX(id) FROM hotel LIMIT 1")
LIST="SELECT id FROM reservation WHERE hotel_id=UNHEX('$HEX') AND arrival BETWEEN '2026-03-01' AND '2026-06-30' AND status='confirmed' ORDER BY arrival LIMIT 20"
row "MariaDB (composite index)" "$(time_sql "$LIST")" "$(one "SELECT COUNT(*) FROM reservation WHERE hotel_id=UNHEX('$HEX') AND arrival BETWEEN '2026-03-01' AND '2026-06-30' AND status='confirmed'") matches"

ULID=$($DC exec -T php php -r 'require "vendor/autoload.php"; echo \Symfony\Component\Uid\Ulid::fromBinary(hex2bin($argv[1]))->toBase32();' "$HEX")
ES_B="{\"size\":20,\"_source\":false,\"sort\":[{\"arrival\":\"asc\"}],\"query\":{\"bool\":{\"filter\":[{\"term\":{\"hotel.id\":\"$ULID\"}},{\"term\":{\"status\":\"confirmed\"}},{\"range\":{\"arrival\":{\"gte\":\"2026-03-01\",\"lte\":\"2026-06-30\"}}}]}}}"
ES_B_COUNT=$(es -XPOST 'localhost:9200/reservations/_count' -d "{\"query\":{\"bool\":{\"filter\":[{\"term\":{\"hotel.id\":\"$ULID\"}},{\"term\":{\"status\":\"confirmed\"}},{\"range\":{\"arrival\":{\"gte\":\"2026-03-01\",\"lte\":\"2026-06-30\"}}}]}}}" | json count)
row "Elasticsearch" "$(time_es "$ES_B")" "$ES_B_COUNT matches"
