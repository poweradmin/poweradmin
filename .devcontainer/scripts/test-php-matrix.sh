#!/usr/bin/env bash
# Run the unit test suite on every supported PHP version (8.2, 8.3, 8.4, 8.5).
#
# Usage: .devcontainer/scripts/test-php-matrix.sh [version ...]
#        composer tests:matrix
#
# Local only. Uses the devcontainer images poweradmin-devcontainer-fpm:<version>
# (build them with the devcontainer compose stack). The container mounts this
# checkout, so run `composer install` on the host first: the dev dependencies
# (phpunit and friends) must be present in ./vendor. Pass versions to run a subset.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
versions=("$@")
if [ "${#versions[@]}" -eq 0 ]; then
    versions=(8.2 8.3 8.4 8.5)
fi

if [ ! -x "$root/vendor/bin/phpunit" ]; then
    echo "vendor/bin/phpunit not found: run 'composer install' first (dev dependencies required)." >&2
    exit 2
fi

log_dir="$(mktemp -d)"
summary=()
failed=0

for version in "${versions[@]}"; do
    image="poweradmin-devcontainer-fpm:${version}"
    log="$log_dir/php-${version}.log"
    echo "=== PHP ${version} (${image}) ==="
    if docker run --rm --user "$(id -u):$(id -g)" -v "$root:/app" -w /app \
        "$image" php -d memory_limit=-1 vendor/bin/phpunit --testsuite unit --do-not-cache-result >"$log" 2>&1; then
        status="PASS"
    else
        status="FAIL"
        failed=1
    fi
    result="$(grep -E '^(OK|FAILURES!|ERRORS!|Tests:)' "$log" | tr '\n' ' ' || true)"
    [ -n "$result" ] || result="$(tail -n 3 "$log" | tr '\n' ' ')"
    line="PHP ${version}: ${status} ${result}"
    summary+=("$line")
    echo "$line"
done

echo
echo "=== Summary (logs in $log_dir) ==="
printf '%s\n' "${summary[@]}"
exit "$failed"
