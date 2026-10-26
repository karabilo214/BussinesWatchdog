#!/bin/sh
set -eu

BASE="${1:?usage: probe.sh <base-url> <seconds> [path...]}"
SECONDS_TO_RUN="${2:?usage: probe.sh <base-url> <seconds> [path...]}"
shift 2
[ $# -gt 0 ] || set -- /health/ready /app/ /en
OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT
END=$(( $(date +%s) + SECONDS_TO_RUN ))

for path in "$@"; do
    (
        while [ "$(date +%s)" -lt "$END" ]; do
            code=$(curl -sk -o /dev/null --max-time 20 -w '%{http_code}' "$BASE$path" || true)
            echo "$(date +%T) $code" >> "$OUT/$(echo "$path" | tr -c 'A-Za-z0-9' '_')"
            sleep 0.1
        done
    ) &
done
wait

failed=0
for path in "$@"; do
    file="$OUT/$(echo "$path" | tr -c 'A-Za-z0-9' '_')"
    total=$(wc -l < "$file" | tr -d ' ')
    bad=$(grep -vc ' 200$' "$file" || true)
    echo "$path requests=$total not_200=$bad"
    grep -v ' 200$' "$file" | head -5 | sed 's/^/  /'
    [ "$bad" -eq 0 ] || failed=1
done
exit $failed
