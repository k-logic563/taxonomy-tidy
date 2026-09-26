#!/usr/bin/env sh

set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
command=${1:-}
project=${E2E_COMPOSE_PROJECT:-taxonomy-tidy-e2e}
base_url=${E2E_BASE_URL:-http://127.0.0.1:8081}
admin_user=${E2E_ADMIN_USER:-e2e-admin}
admin_password=${E2E_ADMIN_PASSWORD:-e2e-local-password}
admin_email=${E2E_ADMIN_EMAIL:-e2e-admin@example.test}
wp_service=${E2E_WP_SERVICE:-wp-cli}
db_service=${E2E_DB_SERVICE:-database}

case "$project" in
	taxonomy-tidy-e2e|taxonomy-tidy-e2e-[a-z0-9]* ) ;;
	* ) echo "E2E_COMPOSE_PROJECT must be taxonomy-tidy-e2e or start with taxonomy-tidy-e2e-." >&2; exit 1 ;;
esac

case "$base_url" in
	http://127.0.0.1:*|http://localhost:* ) ;;
	* ) echo "E2E_BASE_URL must use http://127.0.0.1 or http://localhost." >&2; exit 1 ;;
esac

case "$wp_service" in wp-cli ) ;; * ) echo "E2E_WP_SERVICE must be wp-cli." >&2; exit 1 ;; esac
case "$db_service" in database ) ;; * ) echo "E2E_DB_SERVICE must be database." >&2; exit 1 ;; esac

compose() {
	docker compose --project-directory "$project_dir" --project-name "$project" \
		-f "$project_dir/docker-compose.yml" -f "$project_dir/docker-compose.e2e.yml" "$@"
}

wp() {
	compose run --rm -e E2E_EXPECTED_URL="$base_url" "$wp_service" "$@"
}

wait_for_wordpress() {
	attempt=0
	until wp core version >/dev/null 2>&1; do
		attempt=$((attempt + 1))
		if [ "$attempt" -ge 60 ]; then
			echo "The E2E WordPress service did not become ready." >&2
			exit 1
		fi
		sleep 2
	done
}

setup() {
	compose run --rm composer install --no-interaction --prefer-dist
	compose up --detach "$db_service" wordpress
	wait_for_wordpress
	if ! wp core is-installed >/dev/null 2>&1; then
		wp core install --url="$base_url" --title="Taxonomy Tidy E2E" \
			--admin_user="$admin_user" --admin_password="$admin_password" \
			--admin_email="$admin_email" --skip-email
	fi
	wp option update home "$base_url"
	wp option update siteurl "$base_url"
	wp option update timezone_string Asia/Tokyo
	wp language core install ja --activate >/dev/null
	wp plugin activate taxonomy-tidy >/dev/null
	wp eval-file wp-content/plugins/taxonomy-tidy/tools/e2e-fixture.php reset
}

reset_fixture() {
	wait_for_wordpress
	wp eval-file wp-content/plugins/taxonomy-tidy/tools/e2e-fixture.php reset
}

state() {
	wait_for_wordpress
	wp eval-file wp-content/plugins/taxonomy-tidy/tools/e2e-state.php snapshot
}

clean() {
	case "$project" in taxonomy-tidy-e2e|taxonomy-tidy-e2e-[a-z0-9]* ) ;; * ) exit 1 ;; esac
	compose down --volumes --remove-orphans
}

case "$command" in
	setup ) setup ;;
	reset ) reset_fixture ;;
	state ) state ;;
	clean ) clean ;;
	* ) echo "Usage: bin/e2e.sh {setup|reset|state|clean}" >&2; exit 1 ;;
esac
