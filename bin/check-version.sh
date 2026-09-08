#!/usr/bin/env bash
#
# Guard against a release WordPress would refuse to serve: the git tag, the
# plugin header Version, the IBRAAI_VERSION constant and the readme Stable tag
# must all be the same. The directory reads Stable tag from trunk and then
# serves that tag folder, so a mismatch silently publishes the wrong code, or
# nothing at all.
#
# Usage: bin/check-version.sh [expected-version]
#        (defaults to the plugin header version, so it doubles as a local check)

set -euo pipefail

cd "$(dirname "$0")/.."

plugin_file="ibracodes-ai-assistant.php"

header_version=$(grep -m1 -E '^\s*\*\s*Version:' "$plugin_file" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
constant_version=$(grep -m1 -E "define\('IBRAAI_VERSION'" "$plugin_file" | sed -E "s/.*'IBRAAI_VERSION',[[:space:]]*'([^']+)'.*/\1/")
stable_tag=$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '[:space:]')
expected="${1:-$header_version}"

printf 'expected (tag):      %s\n' "$expected"
printf 'plugin header:       %s\n' "$header_version"
printf 'IBRAAI_VERSION:      %s\n' "$constant_version"
printf 'readme stable tag:   %s\n' "$stable_tag"

status=0
for pair in "header:$header_version" "constant:$constant_version" "stable-tag:$stable_tag"; do
    name="${pair%%:*}"
    value="${pair#*:}"
    if [ "$value" != "$expected" ]; then
        printf '::error::%s is %s but %s was expected\n' "$name" "$value" "$expected"
        status=1
    fi
done

# The changelog is what users read before updating; an unlisted version is
# almost always a forgotten entry rather than a deliberate omission.
if ! grep -qE "^= ${expected//./\\.} =" readme.txt; then
    printf '::error::readme.txt has no changelog entry for %s\n' "$expected"
    status=1
fi

if [ "$status" -eq 0 ]; then
    printf '\nVersion %s is consistent across the plugin header, the constant and readme.txt.\n' "$expected"
fi

exit "$status"
