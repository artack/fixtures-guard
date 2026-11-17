SHELL := /bin/bash

export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)
export DOCKER_COMPOSER_CACHE ?= $(HOME)/.cache/composer-docker

RUN := docker compose run --rm
UPDATE := composer update --prefer-dist --no-interaction --no-progress
INSTALL := composer install --prefer-dist --no-interaction --no-progress

.DEFAULT_GOAL := help

# every run shares vendor/ and composer.lock, and test has to end with PHP 8.5 and the highest dependencies
.NOTPARALLEL:

.PHONY: help build php85-cli php86-cli install update-tools phpcs phpcs-fix phpstan coverage \
	test test-php85-lowest test-php85-highest test-php86-lowest test-php86-highest verify

help: ## Show the targets
	@grep -E '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

$(DOCKER_COMPOSER_CACHE):
	mkdir -p $@

build: | $(DOCKER_COMPOSER_CACHE) ## Build the PHP 8.5 and 8.6 images
	docker compose build --pull

php85-cli: | $(DOCKER_COMPOSER_CACHE) ## Open a shell with PHP 8.5
	$(RUN) php85 sh

php86-cli: | $(DOCKER_COMPOSER_CACHE) ## Open a shell with PHP 8.6
	$(RUN) php86 sh

vendor/autoload.php: composer.json | $(DOCKER_COMPOSER_CACHE)
	$(RUN) php85 $(UPDATE)

tools/%/vendor/autoload.php: tools/%/composer.lock | $(DOCKER_COMPOSER_CACHE)
	$(RUN) php85 $(INSTALL) --working-dir tools/$*

install: | $(DOCKER_COMPOSER_CACHE) ## Install the highest dependencies with PHP 8.5
	$(RUN) php85 $(UPDATE)

update-tools: | $(DOCKER_COMPOSER_CACHE) ## Update php-cs-fixer and PHPStan
	$(RUN) php85 composer update:tools

phpcs: tools/php-cs-fixer/vendor/autoload.php ## Check the coding standard
	$(RUN) php85 composer phpcs

phpcs-fix: tools/php-cs-fixer/vendor/autoload.php ## Fix the coding standard
	$(RUN) php85 composer phpcs:fix

phpstan: vendor/autoload.php tools/phpstan/vendor/autoload.php ## Run PHPStan
	$(RUN) php85 composer phpstan

coverage: vendor/autoload.php ## Run PHPUnit with PHP 8.5 and fail below 100% coverage
	$(RUN) php85 composer coverage

test-php86-lowest: | $(DOCKER_COMPOSER_CACHE) ## PHPUnit with PHP 8.6 and the lowest dependencies
	$(RUN) php86 sh -c '$(UPDATE) --prefer-lowest --prefer-stable && composer phpunit'

test-php86-highest: | $(DOCKER_COMPOSER_CACHE) ## PHPUnit with PHP 8.6 and the highest dependencies
	$(RUN) php86 sh -c '$(UPDATE) && composer phpunit'

test-php85-lowest: | $(DOCKER_COMPOSER_CACHE) ## PHPUnit with PHP 8.5 and the lowest dependencies
	$(RUN) php85 sh -c '$(UPDATE) --prefer-lowest --prefer-stable && composer phpunit'

test-php85-highest: | $(DOCKER_COMPOSER_CACHE) ## PHPUnit with PHP 8.5 and the highest dependencies
	$(RUN) php85 sh -c '$(UPDATE) && composer phpunit'

# ends with PHP 8.5 and the highest dependencies, so vendor/ is back in its usual state
test: test-php86-lowest test-php86-highest test-php85-lowest test-php85-highest ## Run the whole matrix, one run after the other

verify: phpcs test phpstan coverage ## Run every check
