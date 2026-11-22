#!/bin/sh
set -eu

# Exercise the real Laravel bootstrap with database settings only in .env.
# Stop at queue:work so this check never starts a long-running worker.
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf "$fixture"' EXIT HUP INT TERM
php_binary=$(command -v php)

cp -R "$project_root/app" "$project_root/bootstrap" "$project_root/config" \
    "$project_root/routes" "$project_root/composer.json" "$project_root/artisan" "$fixture/"
ln -s "$project_root/vendor" "$fixture/vendor"
mkdir -p "$fixture/bin" "$fixture/storage/logs"
# Existing generated caches must not select the developer's database.
rm -f "$fixture/bootstrap/cache/config.php" "$fixture/bootstrap/cache/events.php" \
    "$fixture/bootstrap/cache/routes-v7.php"

cat > "$fixture/.env" <<'ENV'
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
CACHE_STORE=array
ENV

cat > "$fixture/bin/php" <<'SH'
#!/bin/sh
if [ "$1" = '-r' ]; then
    exec "$READINESS_PHP_BINARY" "$@"
fi
if [ "$1" = 'artisan' ] && [ "$2" = 'queue:work' ]; then
    echo 'Database readiness passed; queue worker reached.'
    exit 0
fi
if [ "$1" = 'artisan' ]; then
    case "$2" in
        config:clear|config:cache|route:cache|event:cache) exec "$READINESS_PHP_BINARY" "$@" ;;
    esac
fi
echo 'Unexpected PHP command in entrypoint check.' >&2
exit 1
SH
chmod +x "$fixture/bin/php"

(
    cd "$fixture"
    unset DB_CONNECTION DB_DATABASE DB_HOST DB_PORT DB_USERNAME DB_PASSWORD DB_URL
    unset APP_ENV APP_CONFIG_CACHE APP_ROUTES_CACHE APP_EVENTS_CACHE
    export READINESS_PHP_BINARY="$php_binary"
    export PATH="$fixture/bin:$PATH"
    export CONTAINER_ROLE=worker
    export APP_ENV=testing
    timeout 10 sh "$project_root/docker/entrypoint.sh"
    export APP_ENV=production
    export APP_KEY=base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=
    timeout 10 sh "$project_root/docker/entrypoint.sh"
    "$READINESS_PHP_BINARY" -r '
        $config = require "bootstrap/cache/config.php";
        exit($config["app"]["key"] === getenv("APP_KEY") ? 0 : 1);
    '
)
