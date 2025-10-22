#!/bin/bash
docker compose exec web-php-8.4 su --shell /bin/bash www-data --command "php \"$@\""