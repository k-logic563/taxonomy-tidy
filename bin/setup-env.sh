#!/usr/bin/env sh

set -eu

if [ -f composer.lock ]; then
	docker compose run --rm composer install --no-interaction --prefer-dist
else
	echo "composer.lock not found; generating it with the Composer container."
	docker compose run --rm composer update --no-interaction --prefer-dist
fi
docker compose up --detach database wordpress

attempt=0
until docker compose run --rm wp-cli core version >/dev/null 2>&1; do
	attempt=$((attempt + 1))
	if [ "${attempt}" -ge 30 ]; then
		echo "WordPress did not become ready in time." >&2
		exit 1
	fi
	sleep 2
done

if ! docker compose run --rm wp-cli core is-installed >/dev/null 2>&1; then
	docker compose run --rm wp-cli core install \
		--url="http://localhost:${WP_PORT:-8080}" \
		--title="Taxonomy Tidy Development" \
		--admin_user="${WP_ADMIN_USER:-admin}" \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" \
		--admin_email="${WP_ADMIN_EMAIL:-admin@example.test}" \
		--skip-email
fi

docker compose run --rm wp-cli plugin activate taxonomy-tidy
