# Multi-verze PS dev stack. Tag verze = ps<major><minor> (ps82, ps91).
# `make up` nahodí primární (DEFAULT_PS z .env), `make up PS=ps91` jinou.
include .env
export

PS ?= $(DEFAULT_PS)

.PHONY: up infra down down-all drop status logs shell shell-root mysql php xdebug check fix

up:         ; bin/up $(PS)
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

# Shodí všechny verze (každá vlastní projekt) + infra.
down-all:
	@for f in compose.*.yml; do \
	  [ "$$f" = "compose.base-ps.yml" ] && continue; \
	  tag="$${f#compose.}"; tag="$${tag%.yml}"; \
	  docker compose -p "$$tag" -f "$$f" down; \
	done
	@docker compose down
