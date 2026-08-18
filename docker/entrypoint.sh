#!/bin/sh
set -e

# One image, three roles. Baking the role into an env var rather than into
# three near-identical Dockerfiles means the worker is provably running the
# same code as the web container — which is exactly the guarantee you want
# when a job behaves differently in production than in a request.

role="${CONTAINER_ROLE:-app}"

wait_for_dependencies() {
    echo "› waiting for postgres at ${DB_HOST:-postgres}:${DB_PORT:-5432}"
    until php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));" 2>/dev/null; do
        sleep 1
    done
}

bootstrap_application() {
    [ -f .env ] || cp .env.example .env
    [ -d vendor ] || composer install --no-interaction --prefer-dist
    grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
}

case "$role" in
    app)
        bootstrap_application
        wait_for_dependencies
        php artisan migrate --force --seed
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
