# Multi-verze PS dev stack. Tag verze = ps<major><minor> (ps82, ps91).
# `make up` nahodí primární (DEFAULT_PS z .env), `make up PS=ps91` jinou.
include .env
export

PS ?= $(DEFAULT_PS)

.PHONY: up build infra down down-all drop status logs shell shell-root mysql php xdebug check fix configure carriers e2e e2e-install e2e-report

up:         ; bin/up $(PS)
configure:  ; bin/configure $(PS) $(ARGS)        # post-install nastavení (fáze 2); ARGS="--dry-run" jen vypíše
carriers:   ; bin/carriers $(PS) $(if $(COUNTRY),--country=$(COUNTRY)) $(if $(REFRESH),--refresh)   # výpis dostupných Zásilkovna dopravců
build:      ; docker compose -p $(PS) -f compose.$(PS).yml build $(PS)   # rebuild image po editaci Dockerfile
infra:      ; docker compose up -d traefik mysql adminer mailpit
down:       ; docker compose -p $(PS) -f compose.$(PS).yml down
drop:       ; bin/drop $(PS)
status:     ; bin/status
logs:       ; docker logs -f --tail=100 $(PS)
shell:      ; docker exec -it $(PS) su www-data -s /bin/bash
shell-root: ; docker exec -it $(PS) bash
mysql:      ; docker exec -it dev_db mariadb -u root -pasdf

# php CLI v dané verzi:           make php ARGS="bin/console …"
php:        ; docker exec -it $(PS) su --shell /bin/bash www-data --command "php $(ARGS)"
# php CLI s Xdebug session:       make xdebug ARGS="bin/console …"
xdebug:     ; docker exec -it $(PS) su --shell /bin/bash www-data --command "XDEBUG_SESSION=1 php $(ARGS)"
# QA modulu (composer skripty v dev toolingu):
check:      ; docker exec $(PS) bash -c "cd /var/www/packetery-dev/ && composer check:all"
fix:        ; docker exec $(PS) bash -c "cd /var/www/packetery-dev/ && composer fix:all"

# E2E testy (Playwright) modulu Packeta — běží na hostu pod Node z .nvmrc.
#   make e2e                      # všechny testy proti DEFAULT_PS
#   make e2e PS=ps91              # proti jiné verzi
#   make e2e ARGS="packet-submit-flow --headed"   # konkrétní test / přepínače
# Plán + známá omezení: e2e/prestashop/README.md
e2e:
	@cd e2e/prestashop && export NVM_DIR="$$HOME/.nvm" && . "$$NVM_DIR/nvm.sh" && nvm use >/dev/null && \
	  PS=$(PS) npx playwright test $(ARGS)

# Jednorázová příprava prostředí (Node deps + prohlížeč). Spusť po klonu / změně deps.
e2e-install:
	@cd e2e/prestashop && export NVM_DIR="$$HOME/.nvm" && . "$$NVM_DIR/nvm.sh" && nvm install >/dev/null && nvm use >/dev/null && \
	  npm install && npx playwright install chromium

# Otevře HTML report z posledního běhu v prohlížeči.
e2e-report:
	@cd e2e/prestashop && export NVM_DIR="$$HOME/.nvm" && . "$$NVM_DIR/nvm.sh" && nvm use >/dev/null && \
	  npx playwright show-report

# Shodí všechny běžící verze (každá vlastní projekt) + infra.
down-all:
	@for f in compose.*.yml; do \
	  [ "$$f" = "compose.base-ps.yml" ] && continue; \
	  tag="$${f#compose.}"; tag="$${tag%.yml}"; \
	  [ -z "$$(docker ps -aq --filter label=com.docker.compose.project=$$tag)" ] && continue; \
	  docker compose -p "$$tag" -f "$$f" down; \
	done
	@docker compose down
