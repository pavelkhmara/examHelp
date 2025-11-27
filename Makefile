SHELL := /usr/bin/env bash
.PHONY: up down init migrate seed test cs stan bash app-shell queue queue-shell refresh fast-refresh worker-restart logs lint cache-clear dump-autoload app-bash mysql-buffers ctx ctx-models ctx-db ctx-models-db ctx-nova ctx-file ctx-file-auto ctx-help qs qw qwl

DC = docker compose

up:
	$(DC) up -d --build

down:
	$(DC) down -v

init:
	docker compose pull
	docker compose up -d --wait
	docker compose exec app composer install -n
	docker compose exec app php artisan key:generate
	docker compose exec app php artisan migrate:fresh --seed

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

queue:
	docker compose exec app php artisan queue:work --tries=1 --queue=default

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

logs:
	docker compose logs -f app

# Queue status - показывает какие джобы сейчас выполняются (snapshot)
qs:
	@echo "=================================================================="
	@echo "  QUEUE STATUS: $$(date '+%Y-%m-%d %H:%M:%S')"
	@echo "=================================================================="
	@echo ""
	@echo "[WORKERS STATUS]"
	@running=0; total=10; \
	for i in 1 2 3 4 5 6 7 8 9 10; do \
		if $(DC) ps queue-worker-$$i 2>/dev/null | grep -q "Up"; then \
			running=$$((running + 1)); \
		fi; \
	done; \
	echo "   Active: $$running/$$total workers"
	@echo ""
	@echo "[RUNNING JOBS (reserved)]"
	@jobs=$$($(DC) exec -T redis redis-cli ZRANGE laravel_database_queues:default:reserved 0 -1 2>/dev/null); \
	if [ -z "$$jobs" ]; then \
		echo "   (none)"; \
	else \
		echo "$$jobs" | while read -r job; do \
			class=$$(echo "$$job" | sed -n 's/.*"displayName":"\([^"]*\)".*/\1/p' | head -1); \
			task_id=$$(echo "$$job" | sed -n 's/.*s:6:\\"taskId\\";i:\([0-9]*\);.*/\1/p' | head -1); \
			exam_id=$$(echo "$$job" | sed -n 's/.*s:6:\\"examId\\";s:[0-9]*:\\"\([0-9a-f-]*\)\\";.*/\1/p' | head -1); \
			if [ -n "$$class" ]; then \
				printf "   >> "; \
				if [ -n "$$task_id" ]; then \
					printf "$$class (task: $$task_id"; \
					[ -n "$$exam_id" ] && printf ", exam: $$exam_id"; \
					printf ")\n"; \
				else \
					echo "$$class"; \
				fi; \
			fi; \
		done; \
	fi
	@echo ""
	@pending=$$($(DC) exec -T redis redis-cli LLEN laravel_database_queues:default 2>/dev/null || echo 0); \
	echo "[PENDING]: $$pending jobs"
	@echo ""
	@failed=$$($(DC) exec -T redis redis-cli ZCARD laravel_database_queues:default:failed 2>/dev/null || echo 0); \
	delayed=$$($(DC) exec -T redis redis-cli ZCARD laravel_database_queues:default:delayed 2>/dev/null || echo 0); \
	echo "[FAILED]: $$failed  |  [DELAYED]: $$delayed"
	@echo ""
	@mem=$$($(DC) exec -T redis redis-cli INFO memory 2>/dev/null | grep "used_memory_human" | cut -d: -f2 | tr -d '\r'); \
	[ -n "$$mem" ] && echo "[REDIS MEMORY]: $$mem" || echo "[REDIS MEMORY]: N/A"
	@echo ""

# Queue watch - мониторит активные джобы в реальном времени (авто-обновление)
qw:
	@echo "Queue Watch - press Ctrl+C to exit"
	@echo "Refreshing every 2 seconds..."
	@echo ""
	@while true; do \
		clear; \
		echo "=================================================================="; \
		echo "  LIVE QUEUE MONITOR: $$(date '+%Y-%m-%d %H:%M:%S')"; \
		echo "=================================================================="; \
		echo ""; \
		jobs=$$($(DC) exec -T redis redis-cli ZRANGE laravel_database_queues:default:reserved 0 -1 2>/dev/null); \
		if [ -z "$$jobs" ]; then \
			echo "[RUNNING JOBS]: None - queue is idle"; \
		else \
			echo "[RUNNING JOBS]:"; \
			echo "$$jobs" | while read -r job; do \
				class=$$(echo "$$job" | sed -n 's/.*"displayName":"\([^"]*\)".*/\1/p' | head -1); \
				task_id=$$(echo "$$job" | sed -n 's/.*s:6:\\"taskId\\";i:\([0-9]*\);.*/\1/p' | head -1); \
				exam_id=$$(echo "$$job" | sed -n 's/.*s:6:\\"examId\\";s:[0-9]*:\\"\([0-9a-f-]*\)\\";.*/\1/p' | head -1); \
				if [ -n "$$class" ]; then \
					printf "   >> "; \
					if [ -n "$$task_id" ]; then \
						printf "$$class (task: $$task_id"; \
						[ -n "$$exam_id" ] && printf ", exam: $$exam_id"; \
						printf ")\n"; \
					else \
						echo "$$class"; \
					fi; \
				fi; \
			done; \
		fi; \
		echo ""; \
		pending=$$($(DC) exec -T redis redis-cli LLEN laravel_database_queues:default 2>/dev/null || echo 0); \
		echo "[PENDING]: $$pending jobs"; \
		echo ""; \
		echo "Press Ctrl+C to exit..."; \
		sleep 2; \
	done

# Queue watch logs - показывает логи воркеров (старое поведение)
qwl:
	docker compose logs -f --tail=20 queue-worker-1 queue-worker-2 queue-worker-3 queue-worker-4 queue-worker-5 queue-worker-6 queue-worker-7 queue-worker-8 queue-worker-9 queue-worker-10

lint:
	docker compose exec app vendor/bin/pint --test

app-bash:
	$(DC) exec app bash

mysql-buffers:
	@echo "Applying MySQL buffer settings..."
	@$(DC) exec mysql sh -c 'mysql -uroot -p$$MYSQL_ROOT_PASSWORD -e "SET GLOBAL sort_buffer_size = 16777216; SET GLOBAL read_rnd_buffer_size = 8388608; SELECT \"MySQL buffers configured successfully\" as status, @@GLOBAL.sort_buffer_size / 1024 / 1024 AS sort_buffer_MB, @@GLOBAL.read_rnd_buffer_size / 1024 / 1024 AS read_rnd_buffer_MB;"'



# ===== Context pack shortcuts =====
#   ARTISAN_CMD ?= docker compose exec -T app php artisan
#   CTX_SCRIPT  ?= ./context-pack.sh
#   CTX_OUT     ?= repo-context.md

ARTISAN_CMD ?= docker compose exec -T app php artisan
CTX_SCRIPT  ?= ./context-pack.sh
CTX_OUT     ?= repo-context.md

# Full pack (everything)
ctx:
	env OUT=$(CTX_OUT) ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) all

# Only Models (table, fillable, casts, relations)
ctx-models:
	env OUT=$(basename $(CTX_OUT)).models.md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) models

# Only DB (tables, columns with types/null/default, FKs)
ctx-db:
	env OUT=$(basename $(CTX_OUT)).db.md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) db

# Models + DB (recommended for schema focus)
ctx-models-db:
	env OUT=$(basename $(CTX_OUT)).models-db.md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) models+db

# Nova resources (class, $model, fields() head)
ctx-nova:
	env OUT=$(basename $(CTX_OUT)).nova.md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) nova

# Single file + related (use: make ctx-file FILE=path/to/File.php)
ctx-file:
	@if [ -z "$$FILE" ]; then echo "Usage: make ctx-file FILE=app/Services/LanguageApp/ExamResearchService.php"; exit 1; fi
	env OUT=$(basename $(CTX_OUT)).file.$(notdir $(FILE)).md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) file "$$FILE"

# Debug variant
ctx-file-debug:
	@if [ -z "$$FILE" ]; then echo "Usage: make ctx-file-debug FILE=..."; exit 1; fi
	env DEBUG=1 OUT=$(basename $(CTX_OUT)).file.$(notdir $(FILE)).md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) file $(FILE)

# Single file (auto-resolve: path | App\\Class | basename)
ctx-file-auto:
	@if [ -z "$$FILE" ]; then echo "Usage: make ctx-file-auto FILE=ExamResearchService.php|App\\Services\\...|app/.../File.php"; exit 1; fi
	env OUT=$(basename $(CTX_OUT)).file.$(notdir $(FILE)).md ARTISAN_CMD="$(ARTISAN_CMD)" bash $(CTX_SCRIPT) file "$$FILE"

# Help
ctx-help:
	@echo "Context pack targets:"
	@echo "  make ctx                 # full context"
	@echo "  make ctx-models          # only models"
	@echo "  make ctx-db              # only database (live schema)"
	@echo "  make ctx-models-db       # models + database"
	@echo "  make ctx-nova            # Nova resources"
	@echo "  make ctx-file FILE=...       # one file + related (exact path)"
	@echo "  make ctx-file-auto FILE=...  # same, but FILE can be App\\Class or just basename"
	@echo ""
	@echo "Vars: CTX_OUT (default: repo-context.md), CTX_SCRIPT (default: ./context-pack.sh), ARTISAN_CMD"