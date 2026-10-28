#!/usr/bin/env bash
# Time API v2 endpoints against the large perf data set and compare each with its budget.
#
# Usage: .devcontainer/scripts/perf-timings.sh [port ...]
#        default ports: 8080 8081 8082 (SQL backend); 8083-8085 are the API backend
#
# Local only. Seed first with `.devcontainer/scripts/import-test-data.sh --perf`, and
# remove the data afterwards with `--perf-clean`: the devcontainer databases are shared.
# Budgets live in .devcontainer/perf-budgets.tsv. Exits 1 when a budgeted endpoint is
# slower than its budget or does not answer with a JSON success; 'xfail' rows are reported only.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
budgets="$root/.devcontainer/perf-budgets.tsv"
admin_key="${PERF_ADMIN_KEY:-test-api-key-for-automated-testing-12345}"
owner_key="${PERF_OWNER_KEY:-perf-owner-key}"
ports=("$@")
if [ "${#ports[@]}" -eq 0 ]; then
    ports=(8080 8081 8082)
fi
command -v php > /dev/null || { echo "php is required on PATH" >&2; exit 2; }

body=$(mktemp)
trap 'rm -f "$body"' EXIT

# Prints "<code> <seconds> <bytes>": the fastest of three successful runs, or the first
# failed run when none succeeded. A PHP fatal error can answer 200 with an HTML body, so
# a run only counts as successful when the body is a JSON reply with success: true.
time_request() {
    local url=$1 key=$2 best="" bytes="" fail="" out c s b
    for _ in 1 2 3; do
        out=$(curl -s -o "$body" --max-time 120 -w '%{http_code} %{time_total} %{size_download}' -H "X-API-Key: $key" "$url" || true)
        read -r c s b <<< "$out"
        if [ "$c" = 200 ] && ! php -r '$d = json_decode((string)file_get_contents($argv[1]), true); exit(($d["success"] ?? false) === true ? 0 : 1);' "$body"; then
            c=ERR
        fi
        if [ "$c" != 200 ]; then
            [ -z "$fail" ] && fail="${c:-000} ${s:-0} ${b:-0}"
            continue
        fi
        if [ -z "$best" ] || awk -v s="$s" -v b="$best" 'BEGIN { exit !(s < b) }'; then
            best=$s bytes=$b
        fi
    done
    if [ -n "$best" ] && [ -z "$fail" ]; then
        echo "200 $best $bytes"
    else
        echo "$fail"
    fi
}

within() {
    awk -v s="$1" -v b="$2" 'BEGIN { exit !(s <= b) }'
}

failures=0
for port in "${ports[@]}"; do
    base="http://localhost:$port/api/v2"
    backend=sql
    if [ "$port" -ge 8083 ] && [ "$port" -le 8085 ]; then
        backend=api
    fi
    big=$(curl -s --max-time 30 -H "X-API-Key: $owner_key" "$base/zones?name=big.perf.test" \
        | php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d["data"]["zones"][0]["id"] ?? "";' || true)
    if [ -z "$big" ]; then
        echo "$port: big.perf.test is not visible to perf_owner (instance down or not seeded with import-test-data.sh --perf)" >&2
        failures=$((failures + 1))
        continue
    fi

    echo "== :$port ($backend backend)"
    while IFS=$'\t' read -r budget rowbackend keyname path note || [ -n "$budget" ]; do
        case "$budget" in ''|'#'*) continue ;; esac
        path=${path%$'\r'}
        note=${note%$'\r'}
        case "$keyname" in
            admin) key=$admin_key ;;
            owner) key=$owner_key ;;
            *) echo "skipping malformed budget row: $budget $rowbackend $keyname $path" >&2; continue ;;
        esac
        limit=${budget#xfail:}
        case "$limit" in
            ''|*[!0-9.]*) echo "skipping budget row without a number of seconds: $budget $path" >&2; continue ;;
        esac
        if [ "$rowbackend" != any ] && [ "$rowbackend" != "$backend" ]; then
            continue
        fi
        read -r code secs bytes <<< "$(time_request "$base${path//\{big\}/$big}" "$key")"

        # xfail:<seconds> is a known problem; XPASS means it now meets that budget
        if [ "${budget%%:*}" = xfail ]; then
            status=XFAIL
            if [ "$code" = 200 ] && within "$secs" "$limit"; then
                status=XPASS
            fi
        elif [ "$code" = 200 ] && within "$secs" "$budget"; then
            status=PASS
        else
            status=FAIL
            failures=$((failures + 1))
        fi
        printf '%-5s %6.2fs / %-9s %3s %10s B  %-6s %s  (%s)\n' "$status" "$secs" "$budget" "$code" "$bytes" "$keyname" "$path" "$note"
    done < "$budgets"
done

echo
if [ "$failures" -gt 0 ]; then
    echo "$failures endpoint(s) over budget or failing"
    exit 1
fi
echo "All budgeted endpoints within budget"
