.PHONY: up down migrate seed test cs stan bash app-shell queue-shell

DC = docker compose

up:
	$(DC) up -d --build

down:
	$(DC) down -v

migrate:
	$(DC) exec app php artisan migrate

seed:
	$(DC) exec app php artisan db:seed

test:
	$(DC) exec app php artisan test

cs:
	$(DC) exec app ./vendor/bin/pint -v

stan:
	$(DC) exec app ./vendor/bin/phpstan analyse --memory-limit=1G

bash:
	$(DC) exec app bash

app-shell:
	$(DC) exec app sh

queue-shell:
	$(DC) exec queue-worker sh
