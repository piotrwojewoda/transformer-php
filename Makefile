.PHONY: help up down build test test-unit test-integration test-functional coverage stan test-xdebug migrate migrate-test db-create db-drop shell worker-training worker-inference

help:                           ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'

up:                             ## docker compose up -d --build
	docker compose up -d --build

down:                           ## docker compose down
	docker compose down

build:                          ## docker compose build
	docker compose build

test:                           ## Run the full PHPUnit suite
	docker compose exec -T php vendor/bin/phpunit

test-unit:                      ## Run unit tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite unit

test-integration:               ## Run integration tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite integration

test-functional:                ## Run functional (WebTest) tests only
	docker compose exec -T php vendor/bin/phpunit --testsuite functional

coverage:                       ## Run tests with coverage
	docker compose exec -T php vendor/bin/phpunit --coverage-text --coverage-clover=var/coverage/clover.xml

stan:                           ## Run PHPStan
	docker compose exec -T php vendor/bin/phpstan analyse --memory-limit=1G

stan-baseline:                  ## Regenerate PHPStan baseline
	docker compose exec -T php vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G

test-xdebug:                    ## Smoke-test that Xdebug attaches during a unit test
	docker compose exec -T -e XDEBUG_CONFIG="client_host=host.docker.internal client_port=9003" php php -d xdebug.start_with_request=yes vendor/bin/phpunit --filter testSmoke --testsuite unit

migrate:                        ## Run database migrations
	docker compose exec -T php bin/console doctrine:migrations:migrate --no-interaction

migrate-test:                   ## Run migrations on test DB
	docker compose exec -T php bin/console doctrine:migrations:migrate --env=test --no-interaction

db-create:                      ## Create the database
	docker compose exec -T php bin/console doctrine:database:create

db-drop:                        ## Drop the database
	docker compose exec -T php bin/console doctrine:database:drop --force

shell:                          ## Open a shell in the PHP container
	docker compose exec php /bin/bash

worker-training:                ## Consume async_training messages (verbose)
	docker compose exec -T php php bin/console messenger:consume async_training -vv

worker-inference:               ## Consume async_inference messages (verbose)
	docker compose exec -T php php bin/console messenger:consume async_inference -vv
