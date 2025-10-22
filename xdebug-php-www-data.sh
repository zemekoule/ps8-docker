#!/bin/bash
docker compose exec prestashop su --shell /bin/bash www-data --command "XDEBUG_SESSION=1 php \"$@\""