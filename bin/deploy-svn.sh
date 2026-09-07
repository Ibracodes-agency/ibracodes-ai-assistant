#!/usr/bin/env bash
# Deploys a release of IbraCodes AI Assistant to the WordPress.org SVN repository.
#
# Usage: bin/deploy-svn.sh <version> [assets-dir]
#   version     the version being released, must equal "Stable tag" in readme.txt
#   assets-dir  folder holding icon-*.png, banner-*.png, screenshot-*.png
#               (default: ../ibracodes-ai-assistant-svn/assets next to this checkout)
#
# Requires: svn, wp (with the dist-archive package), and a WordPress.org account
# that owns the plugin. The SVN URL is issued by WordPress.org after approval.
set -euo pipefail

SLUG="ibracodes-ai-assistant"
SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"
VERSION="${1:?version required, for example 0.2.0}"
PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ASSETS_DIR="${2:-${PLUGIN_DIR}/../${SLUG}-svn/assets}"
WORK="$(mktemp -d)"

stable="$(grep -E '^Stable tag:' "${PLUGIN_DIR}/readme.txt" | awk '{print $3}')"
if [ "${stable}" != "${VERSION}" ]; then
    echo "readme.txt says Stable tag: ${stable}, not ${VERSION}" >&2
    exit 1
fi

echo "Building the distribution archive"
wp dist-archive "${PLUGIN_DIR}" "${WORK}/" --plugin-dirname="${SLUG}" >/dev/null
zip_file="$(ls "${WORK}"/*.zip)"
unzip -q "${zip_file}" -d "${WORK}/dist"

echo "Checking out ${SVN_URL}"
svn checkout --quiet "${SVN_URL}" "${WORK}/svn"

echo "Syncing trunk"
rsync -a --delete --exclude .svn "${WORK}/dist/${SLUG}/" "${WORK}/svn/trunk/"

if [ -d "${ASSETS_DIR}" ]; then
    echo "Syncing assets from ${ASSETS_DIR}"
    mkdir -p "${WORK}/svn/assets"
    rsync -a --delete --exclude .svn "${ASSETS_DIR}/" "${WORK}/svn/assets/"
else
    echo "No assets dir at ${ASSETS_DIR}; skipping banner, icon and screenshots" >&2
fi

cd "${WORK}/svn"
svn add --force --quiet trunk assets 2>/dev/null || true
svn status | awk '$1 == "!" {print $2}' | xargs -r svn delete --quiet
if [ ! -d "tags/${VERSION}" ]; then
    svn copy --quiet trunk "tags/${VERSION}"
fi
# screenshots and banners must be served with the right type
find assets -name '*.png' -exec svn propset --quiet svn:mime-type image/png {} + 2>/dev/null || true
find assets -name '*.jpg' -exec svn propset --quiet svn:mime-type image/jpeg {} + 2>/dev/null || true

svn status
echo
read -r -p "Commit ${VERSION} to WordPress.org? [y/N] " answer
if [ "${answer}" = "y" ]; then
    svn commit -m "Release ${VERSION}"
    echo "Released ${VERSION}"
else
    echo "Not committed. Working copy left at ${WORK}/svn"
fi
