.PHONY: up down migrate seed test cs stan bash app-shell queue-shell refresh fast-refresh worker-restart cache-clear dump-autoload app-bash

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

## Полный цикл: почистить кэши, перегреть автолоадер и перезапустить воркеры/горизонт
refresh: cache-clear dump-autoload worker-restart
	@echo "✅ Done: refresh"

## Быстрый цикл (без composer): только кэши + рестарт воркеров
fast-refresh: cache-clear worker-restart
	@echo "✅ Done: fast-refresh"

cache-clear:
	$(DC) exec -T app php artisan config:clear
	$(DC) exec -T app php artisan cache:clear
	$(DC) exec -T app php artisan route:clear
	$(DC) exec -T app php artisan view:clear
	$(DC) exec -T app php artisan optimize:clear

dump-autoload:
	$(DC) exec -T app composer dump-autoload -o

worker-restart:
	-$(DC) exec -T app php artisan queue:restart || true


app-bash:
	$(DC) exec app bash