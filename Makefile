.PHONY: build run stop logs db-wait assets items migrate init shell test

build:
	docker compose build

run:
	docker compose up -d

stop:
	docker compose down

logs:
	docker compose logs -f

db-wait:
	@echo "Waiting for MariaDB..."
	@i=0; until docker compose exec -T database sh -lc 'mariadb-admin ping -h localhost -uroot -p"$$MARIADB_ROOT_PASSWORD" --silent'; do \
		i=$$((i + 1)); test $$i -lt 31 || { echo "MariaDB did not become ready."; exit 1; }; sleep 2; \
	done

UNAME_S := $(shell uname -s)
WIN_CURDIR := $(shell pwd -W 2>/dev/null || pwd)
WSL_CURDIR := $(shell wsl -d Ubuntu -- wslpath -a "$(WIN_CURDIR)" 2>/dev/null | tr -d '\r')

assets:
ifeq ($(findstring MINGW,$(UNAME_S)),MINGW)
	wsl -d Ubuntu -- bash -lc "cd \"$(WSL_CURDIR)\" && bash ./scripts/fetch-assets.sh"
else
	bash ./scripts/fetch-assets.sh
endif

migrate: db-wait
	docker compose exec -T fv-replowed-slipstream php artisan migrate --seed --force

ITEMS_SQL ?= farmvilledb_trimmed.sql

items: db-wait
	@test -f "$(ITEMS_SQL)" || { echo "Missing $(ITEMS_SQL). Set ITEMS_SQL to the FarmVille items SQL dump."; exit 1; }
	docker compose exec -T database sh -lc 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" "$$MARIADB_DATABASE"' < "$(ITEMS_SQL)"

init: build run
	@echo "Stack started. Import the base items database with 'make items ITEMS_SQL=/path/to/farmvilledb_trimmed.sql', then run 'make migrate'."

shell:
	docker compose exec fv-replowed-slipstream bash

test:
	docker compose exec -T fv-replowed-slipstream php artisan test
