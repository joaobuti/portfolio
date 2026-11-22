#!/bin/sh
set -e

# One image, three roles. Baking the role into an env var rather than into
# three near-identical Dockerfiles means the worker is provably running the
# same code as the web container — which is exactly the guarantee you want
# when a job behaves differently in production than in a request.

role="${CONTAINER_ROLE:-app}"

wait_for_dependencies() {
    echo "› waiting for the configured database"
    # Use the same resolved configuration as migrations and workers. Values in
    # .env are loaded by Laravel, not exported into the shell's environment.
    until php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app->make("db")->connection()->getPdo();
    ' >/dev/null 2>&1; do
        sleep 1
    done
}

bootstrap_application() {
    if [ "${APP_ENV:-production}" != production ] && [ ! -f .env ]; then
        cp .env.example .env
    fi
    [ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist
    if [ "${APP_ENV:-production}" = production ]; then
        php artisan config:clear
    fi
    if ! php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        exit(is_string(config("app.key")) && config("app.key") !== "" ? 0 : 1);
    '; then
        if [ "${APP_ENV:-production}" = production ]; then
            echo 'APP_KEY must be supplied for production.' >&2
            exit 1
        fi
        php artisan key:generate --force
    fi
    if [ "${APP_ENV:-production}" = production ]; then
        # Runtime secrets and service addresses must be injected before caching.
        php artisan config:cache
        php artisan route:cache
        php artisan event:cache
    fi
}

case "$role" in
    app)
        bootstrap_application
        wait_for_dependencies
        php artisan migrate --force
        if [ "${APP_ENV:-production}" != production ]; then
            php artisan db:seed --force
        fi
        echo "› serving on http://localhost:8000"
        exec php artisan serve --host=0.0.0.0 --port=8000
        ;;

    worker)
        bootstrap_application
        wait_for_dependencies
        echo "› consuming queues: billing, observability, default"
        # --max-time recycles the worker hourly so a slow memory leak can never
        # accumulate. --tries=1 because retry policy belongs to the job class,
        # not to the worker's command line.
        exec php artisan queue:work redis \
            --queue=billing,observability,default \
            --tries=1 \
            --max-time=3600 \
            --sleep=1
        ;;

    scheduler)
        bootstrap_application
        wait_for_dependencies
        echo "› running the scheduler"
        exec php artisan schedule:work
        ;;

    *)
        echo "unknown CONTAINER_ROLE: $role" >&2
        exit 1
        ;;
esac
