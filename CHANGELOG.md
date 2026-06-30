# Changelog — PrestaShop multi-verze dev stack

Změny **dev stacku** (ps8-docker), novější nahoře. Stack se neverzuje, záznamy jsou
chronologické. Modul Packeta má vlastní `CHANGE_LOG.txt` ve svém repu — sem nepatří.

## 2026-06-30
- **`make ps-upgrade`** — in-place upgrade PS na nejnovější verzi v rámci majoru (přes
  autoupgrade modul). Data zůstávají, major se nenabízí, automatická záloha + rollback.
- **Přepínatelný dev režim** — `make dev-on` / `dev-off` / `dev-status` (i admin UI).
  `PS_DEV_MODE=0` v `.env`; čerstvá instalace dostane dev zapnutý automaticky.
- **Diagnostika autoupgrade** — `make au-new-version` / `au-requirements` / `au-modules` /
  `au-backups`.
- Autoupgrade modul je nově součástí instalace PS8/9 (doplňuje `bin/download`).
- Fix `make configure`: `tools/configure.php` volal `setPacketeryCarrier()` se 7 argumenty
  místo 8 (chybějící `is_cod`); nově se odvozuje z `disallows_cod` ve feedu. (#13)

## 2026-06-29
- Výchozí verze `make up` přepnuta na **ps91** (PS 9.1) přes `DEFAULT_PS`. (#12)

## 2026-06-23
- **`make translations`** — detekce chybějících / osiřelých i18n stringů modulu. (#11)

## 2026-06-22
- **`make api-stage` / `make api-prod`** — přepínání SOAP/WSDL cíle modulu (stage / prod). (#10)

## 2026-06-18
- Osobní vrstva (`.notes`, `CLAUDE.md`, skilly) přesunuta do `.private/` overlay; public repo
  ji gitignoruje. (#9)

## 2026-06-02
- **Multi-verze dev stack**: tag-per-verze (`compose.<tag>.yml`), sdílená infra (Traefik,
  MariaDB, Adminer, Mailpit), `make up/down/drop/status`, `make configure` / `carriers`
  (post-install nastavení). (#8)

## 2026-05-29
- PhpStorm + Xdebug setup. (#7)
