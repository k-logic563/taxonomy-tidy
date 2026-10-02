.PHONY: setup composer-lock dependencies translations up stop restart reset activate deactivate seed-demo seed-large seed-clean test phpcs lint-js check dist

setup:
	bin/setup-env.sh

composer-lock:
	docker compose run --rm composer update --no-interaction --prefer-dist

dependencies:
	docker compose run --rm composer install --no-interaction --prefer-dist

translations:
	docker compose run --rm --no-deps wp-cli i18n make-pot /var/www/html/wp-content/plugins/term-steward /var/www/html/wp-content/plugins/term-steward/languages/term-steward.pot --domain=term-steward --exclude=vendor,tests,docker,docs,build,dist
	docker compose run --rm --no-deps wp-cli i18n update-po /var/www/html/wp-content/plugins/term-steward/languages/term-steward.pot /var/www/html/wp-content/plugins/term-steward/languages/term-steward-ja.po
	docker compose run --rm --no-deps wp-cli i18n make-mo /var/www/html/wp-content/plugins/term-steward/languages/term-steward-ja.po /var/www/html/wp-content/plugins/term-steward/languages/term-steward-ja.mo

up:
	docker compose up --detach database wordpress

stop:
	docker compose down

restart:
	docker compose restart database wordpress

reset:
	docker compose down --volumes --remove-orphans

activate:
	docker compose run --rm wp-cli plugin activate term-steward

deactivate:
	docker compose run --rm wp-cli plugin deactivate term-steward

seed-demo: up
	docker compose run --rm wp-cli eval-file wp-content/plugins/term-steward/tools/seed.php demo

seed-large: up
	docker compose run --rm wp-cli eval-file wp-content/plugins/term-steward/tools/seed.php large

seed-clean: up
	docker compose run --rm wp-cli eval-file wp-content/plugins/term-steward/tools/seed.php clean

test:
	docker compose run --rm test

phpcs:
	docker compose run --rm composer run phpcs

lint-js:
	docker compose run --rm node sh -c "npm ci --no-audit --no-fund && npm run lint:js"

check: phpcs lint-js test

dist:
	bin/build-dist.sh
