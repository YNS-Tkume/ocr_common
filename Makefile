HTTPD_CONTAINER = kt-minatoku_httpd
PHP_CONTAINER = kt-minatoku_php
MYSQL_CONTAINER = kt-minatoku_mysql
REQUIREMENTS = docker docker-compose vi npm node

check:
	$(foreach REQUIREMENT, $(REQUIREMENTS), \
		$(if $(shell command -v $(REQUIREMENT) 2> /dev/null), \
			$(info `$(REQUIREMENT)` has been found. OK!), \
			$(error Please install `$(REQUIREMENT)` before running setup.) \
		) \
	)

setup: check
	cp ./.env.local ./.env
	cp docker-compose.dev.yml docker-compose.override.yml
	vi ./.env
	vi docker-compose.override.yml
	docker-compose up -d --build
	docker exec $(HTTPD_CONTAINER) chmod -R 777 /var/www/dev-mina-toku/storage
	docker exec $(PHP_CONTAINER) composer install --prefer-dist
	docker exec $(PHP_CONTAINER) php artisan key:generate
	docker exec $(PHP_CONTAINER) php artisan migrate --seed
	make clear-cache

setup-test:
	docker cp ./docker/mysql/create-testing-database.sh $(MYSQL_CONTAINER):/tmp/
	docker exec $(MYSQL_CONTAINER) bash /tmp/create-testing-database.sh
	docker exec $(PHP_CONTAINER) php artisan migrate:fresh --env=testing

clear-cache:
	docker exec ${PHP_CONTAINER} php artisan optimize:clear

migrate:
	docker exec ${PHP_CONTAINER} php artisan migrate

seed:
	docker exec ${PHP_CONTAINER} php artisan db:seed --class=DatabaseSeeder

update-setup:
	cp docker-compose.dev.yml docker-compose.override.yml
	docker-compose up -d --build

remove-setup:
	docker-compose down

setup-tables:
	docker exec $(PHP_CONTAINER) php artisan migrate:fresh --seed

setup-test-tables:
	docker exec $(PHP_CONTAINER) php artisan migrate:fresh --env=testing

check-code:
	make check-cs
	make check-stan
	make check-md
	make code-reviewer

check-cs:
	docker exec $(PHP_CONTAINER) composer cs-check-code

check-stan:
	docker exec $(PHP_CONTAINER) composer phpstan

check-md:
	docker exec $(PHP_CONTAINER) composer md

fix-code-standard:
	docker exec $(PHP_CONTAINER) vendor/bin/phpcbf --standard=PSR12 app/

code-reviewer:
	docker exec $(PHP_CONTAINER) composer code-review

fix-new-line-before-return:
	docker exec $(PHP_CONTAINER) php artisan fix-newline-issue

bash:
	docker exec -it $(PHP_CONTAINER) bash

test-feature:
	docker exec -it $(PHP_CONTAINER) php artisan test --testsuite=Feature

test-unit:
	docker exec -it $(PHP_CONTAINER) php artisan test --testsuite=Unit

test-all:
	docker exec -it $(PHP_CONTAINER) php artisan test
