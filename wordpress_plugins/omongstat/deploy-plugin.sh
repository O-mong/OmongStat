#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${WORDPRESS_CONTAINER:-wordpress-web}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

if [[ "${1:-}" == '--build' ]]; then
    npm --prefix "$SCRIPT_DIR/../.." run build
elif [[ $# -gt 0 ]]; then
    echo 'Usage: deploy-plugin.sh [--build]' >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/omongstat.php" ]]; then
    echo 'Plugin root missing' >&2
    exit 1
fi

if [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER")" != true ]]; then
    echo 'WordPress container is not running' >&2
    exit 1
fi

# A unique staging directory avoids touching another deployment in progress.
STAGING="$(docker exec "$CONTAINER" mktemp -d /var/www/html/wp-content/plugins/.omongstat.stage.XXXXXX)"

cleanup() {
    docker exec "$CONTAINER" sh -c 'test ! -d "$1" || rm -r -- "$1"' sh "$STAGING" || true
}

trap cleanup EXIT

tar -C "$SCRIPT_DIR" -cf - omongstat.php uninstall.php includes assets bin |
    docker exec -i "$CONTAINER" tar -xf - -C "$STAGING"

docker exec "$CONTAINER" php -r '
$directory = $argv[1];
$required = ["omongstat.php", "assets/js/collector.js", "assets/admin/.vite/manifest.json"];

foreach ($required as $file) {
    if (!is_file("$directory/$file")) {
        exit(1);
    }
}

$manifest = json_decode(file_get_contents("$directory/assets/admin/.vite/manifest.json"), true);
$entry = $manifest["src/main.tsx"] ?? null;
if (!$entry) {
    exit(1);
}

foreach (array_merge([$entry["file"]], $entry["css"] ?? []) as $file) {
    if (!is_file("$directory/assets/admin/$file")) {
        exit(1);
    }
}
' "$STAGING"
docker exec "$CONTAINER" sh -eu -c '
staging=$1
target=/var/www/html/wp-content/plugins/omongstat
backup="$staging.previous"
lock=/var/www/html/wp-content/plugins/.omongstat.deploy.lock
if ! mkdir "$lock"; then
    echo "Another deployment is running" >&2
    exit 1
fi
trap '\''rmdir "$lock"'\'' EXIT
chown -R www-data:www-data "$staging"
if test -e "$target"; then
    mv "$target" "$backup"
fi
if ! mv "$staging" "$target"; then
    if test -e "$backup"; then
        mv "$backup" "$target"
    fi
    exit 1
fi
echo "Backup retained: $backup"
' sh "$STAGING"
echo "Completed: $CONTAINER:/var/www/html/wp-content/plugins/omongstat"
