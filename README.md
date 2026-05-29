# PrestaShop multi-verze dev stack

Dev prostředí pro vývoj modulu **Packeta** proti více verzím PrestaShopu současně
(PS 8.2.x + PS 9.1.x na startu). Jeden checkout modulu sdílený do všech verzí,
sdílený MariaDB (schéma per verze), Adminer + Mailpit, routing přes Traefik.

## Prerekvizity
- Docker + `docker compose`
- přístup ke `git@github.com:Zasilkovna/prestashop.git` (modul)

## Rychlý start
```bash
bin/setup        # naklonuje modul, připraví .env, nahodí primární verzi
make status      # přehled verzí + URL
```
Po dokončení: http://ps82.localhost (storefront), `/admin1234` (back office).

## Verze
Verze = krátký tag `ps<major><minor>` (`ps82` = PS 8.2.x, `ps91` = PS 9.1.x classic).
Každá verze je jeden soubor `compose.<tag>.yml` (plná verze + ZIP URL + doména jsou data v něm).
Primární verze je v `.env` (`DEFAULT_PS`).

| příkaz | co dělá |
|---|---|
| `make up` | nahodí **primární** verzi (`DEFAULT_PS`) |
| `make up PS=ps91` | nahodí jinou verzi (běží **souběžně**) |
| `make status` | definované vs. běžící verze + URL |
| `make down PS=ps91` | shodí verzi (data zůstanou) |
| `make drop PS=ps91` | **nevratně** smaže verzi (schéma + `src/` + image); primární odmítne |
| `make logs PS=ps91` | logy verze |
| `make shell PS=ps91` | bash v kontejneru (www-data) |
| `make php PS=ps91 ARGS="bin/console …"` | php CLI |
| `make xdebug PS=ps91 ARGS="…"` | php CLI s Xdebug session |
| `make check` / `make fix` | QA modulu (`composer check:all` / `fix:all`) |

### Přidání nové verze
1. zkopíruj existující `compose.<tag>.yml` → nový tag, uprav `PS_VERSION`, `PS_ZIP_URL`,
   `DB_NAME`, `PS_DOMAIN`, Traefik labely a `src/<tag>` mount.
2. `make up PS=<tag>`.

## Modul
Žije v `modules/prestashop/packetery` (vlastní git repo `Zasilkovna/prestashop`),
bind-mountnutý do **všech** verzí — edituješ jednou, projeví se ve všech běžících
instancích. **Změny modulu commituješ do modulového repa**, ne sem.

## Služby
| služba | URL |
|---|---|
| storefront | http://`<tag>`.localhost |
| back office | http://`<tag>`.localhost/`<PS_FOLDER_ADMIN>` |
| Adminer | http://localhost:8081 (vidí všechna schémata `prestashop_<tag>`) |
| Mailpit | http://localhost:8025 |
| MariaDB | localhost:3308 (root / asdf) |

## PhpStorm + Xdebug
PHP → Servers, jeden server per verze:
- name `ps82.local` / `ps91.local` (sedí s `PHP_IDE_CONFIG` v `compose.<tag>.yml`)
- host `ps82.localhost` / `ps91.localhost`, port 80
- path mapping: `modules/prestashop/packetery` → `/var/www/html/modules/packetery`,
  `src/<tag>` → `/var/www/html`

## Architektura (proč takhle)
Image = jen PHP runtime (`images/php81/Dockerfile`), PrestaShop core se **nepeče** —
instaluje se přes CLI proti `src/<tag>` (přidání verze na stejném PHP = bez `docker build`).
Detailní plán: `.notes/plans/multi-version-dev-stack.md`.
