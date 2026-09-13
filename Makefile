.PHONY: setup composer-lock dependencies up stop restart reset activate deactivate test phpcs check

setup:
	bin/setup-env.sh

composer-lock:
	docker compose run --rm composer update --no-interaction --prefer-dist

dependencies:
	docker compose run --rm composer install --no-interaction --prefer-dist

up:
	docker compose up --detach database wordpress

stop:
	docker compose down

restart:
	docker compose restart database wordpress

reset:
	docker compose down --volumes --remove-orphans

activate:
	docker compose run --rm wp-cli plugin activate taxonomy-tidy

deactivate:
	docker compose run --rm wp-cli plugin deactivate taxonomy-tidy

test:
	docker compose run --rm test

phpcs:
	docker compose run --rm composer run phpcs

check: phpcs test
