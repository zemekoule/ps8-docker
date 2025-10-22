#!/bin/bash

docker compose exec prestashop bash -c "cd /var/www/packetery-dev/ && composer check:all"