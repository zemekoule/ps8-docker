#!/bin/bash
# =============================================================================
# Docker Utility Menu – verze 2.6 (spolehlivé porty přes docker port)
# =============================================================================

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

# === Detekce docker compose ===
if command -v docker compose >/dev/null 2>&1; then
  DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  DC="docker-compose"
else
  echo -e "${RED}Chyba:${NC} Nenalezen 'docker compose' ani 'docker-compose'."
  exit 1
fi

trap "echo -e '\n${YELLOW}Ukončuji...${NC}'; exit 0" SIGINT

# === Barevný stav kontejneru ===
color_status() {
    status="$1"
    case "$status" in
        Up*) echo -e "${GREEN}$status${NC}" ;;
        Exited*) echo -e "${RED}$status${NC}" ;;
        Restarting*) echo -e "${YELLOW}$status${NC}" ;;
        *) echo -e "${CYAN}$status${NC}" ;;
    esac
}

# === Otevření URL v prohlížeči ===
open_url() {
    url="$1"
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$url"
    elif command -v open >/dev/null 2>&1; then
        open "$url"
    elif command -v start >/dev/null 2>&1; then
        start "$url"
    else
        echo "Nelze otevřít prohlížeč. Zkuste ručně: $url"
    fi
}

# === Získání externího portu kontejneru přes docker port ===
get_external_port() {
    container="$1"
    internal_port="$2"
    docker port "$container" "$internal_port" 2>/dev/null | awk -F: '{print $2}'
}

# === Otevření služby podle portu ===
open_service() {
    container="$1"
    internal_port="$2"
    port=$(get_external_port "$container" "$internal_port")
    if [ -n "$port" ]; then
        open_url "http://localhost:$port"
    else
        echo "Port pro službu '$container' nebyl nalezen nebo kontejner neběží."
    fi
}

# === Hlavní smyčka menu ===
while true; do
  clear
  echo -e "${CYAN}=== Docker Utility Menu ===${NC}"

  containers=$($DC ps --all --format "{{.Names}} {{.Status}}")
  running_count=$($DC ps -q | wc -l)
  echo -e "${YELLOW}Běžících kontejnerů: ${GREEN}$running_count${NC}"

  if [ -n "$containers" ]; then
      echo
      echo "Stav kontejnerů:"
      while read -r line; do
          name=$(echo $line | awk '{print $1}')
          status=$(echo $line | cut -d' ' -f2-)
          printf "  %-20s %s\n" "$name" "$(color_status "$status")"
      done <<< "$containers"
      echo
  fi

  # --- Menu ---
  echo -e "${CYAN}--- Kontejnery ---${NC}"
  echo "1) Spustit kontejnery (${DC} up -d)"
  echo "2) Zastavit všechny běžící kontejnery"
  echo "3) Restartovat všechny kontejnery (down-all + up)"
  echo "4) Build kontejnerů (${DC} build)"

  echo -e "${CYAN}\n--- Shell / PHP ---${NC}"
  echo "5) Spustit PHP příkaz ve web-php-8.4"
  echo "6) PHP příkaz s Xdebug (XDEBUG_SESSION=1)"
  echo "7) Bash jako www-data"
  echo "8) Bash jako root"
  echo "9) Composer check all"

  echo -e "${CYAN}\n--- Informace / Logy ---${NC}"
  echo "10) Zobrazit běžící kontejnery"
  echo "11) Zobrazit logy všech kontejnerů (Ctrl+C pro návrat)"

  # --- Dynamické zobrazení služeb s porty ---
  prestashop_port=$(get_external_port "prestashop" 80)
  adminer_port=$(get_external_port "ps8-docker-adminer-1" 8080)
  mailpit_port=$(get_external_port "mailpit" 8025)

  echo -e "${CYAN}\n--- Služby / Services ---${NC}"
  echo "12) Prestashop (http://localhost:${prestashop_port:-?})"
  echo "13) Adminer   (http://localhost:${adminer_port:-?})"
  echo "14) Mailpit   (http://localhost:${mailpit_port:-?})"

  echo "q) Ukončit"
  echo
  read -p "Zadejte volbu [1–14/q]: " choice
  echo -e "\n"

  case $choice in
    1) $DC up -d ;;
    2)
      containers_ids=$(docker container ls -q)
      if [ -n "$containers_ids" ]; then
          docker container stop $containers_ids
      else
          echo "Žádné kontejnery nejsou spuštěné."
      fi
      ;;
    3) ../down-all.sh && ./up.sh ;;
    4) $DC build ;;
    5)
      echo -e "${YELLOW}Zadejte PHP příkaz:${NC}"
      read -e php_cmd
      $DC exec prestashop su --shell /bin/bash www-data --command "php $php_cmd"
      ;;
    6)
      echo -e "${YELLOW}Zadejte PHP příkaz s Xdebug:${NC}"
      read -e php_cmd
      $DC exec prestashop su --shell /bin/bash www-data --command "XDEBUG_SESSION=1 php \"$php_cmd\""
      ;;
    7) $DC exec prestashop su --shell /bin/bash www-data ;;
    8) $DC exec prestashop bash ;;
    9) $DC exec prestashop bash -c "cd /var/www/packetery-dev/ && composer check:all" ;;
    10) $DC ps ;;
    11)
      trap - SIGINT
      $DC logs -f
      trap "echo -e '\n${YELLOW}Ukončuji...${NC}'; exit 0" SIGINT
      ;;
    12) open_service "prestashop" 80 ;;
    13) open_service "ps8-docker-adminer-1" 8080 ;;
    14) open_service "mailpit" 8025 ;;
    q|Q) echo -e "${GREEN}Na shledanou!${NC}"; break ;;
    *) echo "Neplatná volba." ;;
  esac

  echo
  read -n1 -p "Pokračujte libovolnou klávesou..." _
done