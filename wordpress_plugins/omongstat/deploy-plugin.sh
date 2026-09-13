#!/usr/bin/env bash
set -Eeuo pipefail

CONTAINER="${WORDPRESS_CONTAINER:-wordpress-web}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
BUILD_DIR="$SCRIPT_DIR/assets/admin"

# Keep --build compatible with existing commands; every deployment now builds.
if [[ $# -gt 1 || ( $# -eq 1 && "${1:-}" != '--build' ) ]]; then
    echo 'Usage: deploy-plugin.sh [--build]' >&2
    exit 1
fi

if [[ ! -f "$SCRIPT_DIR/omongstat.php" ]]; then
    echo 'Plugin root missing' >&2
    exit 1
fi

if [[ ! -f "$PROJECT_ROOT/package.json" ]]; then
    echo "ERROR: Build project not found: $PROJECT_ROOT/package.json" >&2
    exit 1
fi

for tool in npm node docker tar; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "ERROR: Required command not found: $tool" >&2
        exit 1
    fi
done

if ! container_running="$(docker inspect -f '{{.State.Running}}' "$CONTAINER")"; then
    echo "ERROR: Cannot inspect $CONTAINER. Check Docker access and the container name." >&2
    exit 1
fi

if [[ "$container_running" != true ]]; then
    echo "ERROR: WordPress container is not running: $CONTAINER" >&2
    exit 1
fi

echo "Building React admin assets: $PROJECT_ROOT"
if npm --prefix "$PROJECT_ROOT" run build; then
    echo "Build completed: $BUILD_DIR"
else
    build_status=$?
    echo "ERROR: React build failed (exit $build_status). Deployment cancelled; the installed plugin was not changed." >&2
    echo "Check the build output above. If dependencies are missing, run npm ci in $PROJECT_ROOT." >&2
    exit "$build_status"
fi

if [[ ! -s "$BUILD_DIR/.vite/manifest.json" ]]; then
    echo "ERROR: Build manifest is missing or empty: $BUILD_DIR/.vite/manifest.json" >&2
    echo 'Deployment cancelled; the installed plugin was not changed.' >&2
    exit 1
fi

if ! node "$PROJECT_ROOT/scripts/validate-package.mjs" "$SCRIPT_DIR"; then
    echo 'ERROR: Unsafe or incomplete plugin package. Deployment cancelled.' >&2
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
    if (is_link("$directory/$file") || !is_file("$directory/$file")) {
        exit(1);
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $item) {
    if ($item->isLink()) {
        exit(1);
    }
}
$manifest = json_decode(file_get_contents("$directory/assets/admin/.vite/manifest.json"), true);
$entry = $manifest["src/main.tsx"] ?? null;
if (!$entry) {
    exit(1);
}

foreach (array_merge([$entry["file"]], $entry["css"] ?? []) as $file) {
    if (!is_string($file) || !preg_match("~^assets/[a-zA-Z0-9_-]+\\.(js|css)$~D", $file)
        || is_link("$directory/assets/admin/$file") || !is_file("$directory/assets/admin/$file")) {
        exit(1);
    }
}
' "$STAGING"
docker exec "$CONTAINER" sh -eu -c '
staging=$1
target=/var/www/html/wp-content/plugins/omongstat
backup_root=/var/backups/omongstat
mkdir -p "$backup_root"
chmod 700 "$backup_root"
backup="$backup_root/$(basename "$staging").previous"
lock=/var/www/html/wp-content/plugins/.omongstat.deploy.lock
if ! mkdir "$lock"; then
    echo "Another deployment is running" >&2
    exit 1
fi
trap '\''rmdir "$lock"'\'' EXIT
# Relocate backups produced by older versions without deleting them.
for previous in /var/www/html/wp-content/plugins/.omongstat.stage.*.previous; do
    if test -d "$previous" && test ! -L "$previous"; then
        destination="$backup_root/$(basename "$previous")"
        if test -e "$destination"; then
            echo "Backup already exists: $destination" >&2
            exit 1
        fi
        mv "$previous" "$destination"
    fi
done
# Root owns deployed code; PHP receives read access through its group.
chown -R root:www-data "$staging"
find "$staging" -type d -exec chmod 750 {} \;
find "$staging" -type f -exec chmod 640 {} \;
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
