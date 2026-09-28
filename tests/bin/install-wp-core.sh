#!/usr/bin/env bash
# Download WordPress core for the integration tests (no database setup needed;
# the CI job provides MySQL). Usage: install-wp-core.sh [version|latest] [dir]
set -euo pipefail

WP_VERSION="${1:-latest}"
WP_CORE_DIR="${2:-/tmp/wordpress}"

if [ "$WP_VERSION" = "latest" ]; then
  URL="https://wordpress.org/latest.tar.gz"
else
  URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

mkdir -p "$WP_CORE_DIR"
curl -fsSL "$URL" | tar --strip-components=1 -zxf - -C "$WP_CORE_DIR"
echo "WordPress ${WP_VERSION} installed to ${WP_CORE_DIR}"
