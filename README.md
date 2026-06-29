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
Po dokončení: http://ps91.localhost (storefront), `/admin1234` (back office).

## Každodenní práce (start / stop / restart)
Scénáře, jak to ovládat. Data (`db/`, `src/`) se stop/startem **neztrácí** — maže je jen `make drop`.

**Co teď běží?**
```
make status
```

**Končím práci — dvě varianty:**
- *Nejjednodušší:* prostě zavři Docker Desktop. Kontejnery mají `restart: unless-stopped`,
  takže po dalším spuštění Dockeru **naběhnou samy** — nemusíš nic spouštět.
- *Explicitní stop (uvolní RAM hned):* `make down-all` (zastaví všechny verze + infra).

**Spouštím:**
- *Po restartu Dockeru, když jsi nedělal `down`:* nic — naběhlo samo, ověř `make status`.
- *Po `make down-all` (nebo poprvé):*
  ```
  make up              # infra + primární verze (ps91)
  make up PS=ps82      # přidá 8.2.x souběžně
  ```
  Neinstaluje se znovu (data jsou) → jen rychlý start.

**Jen jedna verze:** `make down PS=ps91` (stop) · `make up PS=ps91` (start).

> `make up` vždy nejdřív zvedne i infra (traefik/mysql/adminer/mailpit), takže stačí jeden příkaz.
> `make up` bez argumentu zvedne **jen primární** verzi — ostatní si nahoď ručně.

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
| `make drop PS=ps82` | **nevratně** smaže verzi (schéma + `src/` + image); primární odmítne |
| `make logs PS=ps91` | logy verze |
| `make shell PS=ps91` | bash v kontejneru (www-data) |
| `make php PS=ps91 ARGS="bin/console …"` | php CLI |
| `make xdebug PS=ps91 ARGS="…"` | php CLI s Xdebug session |
| `make check` / `make fix` | QA modulu (`composer check:all` / `fix:all`) |
| `make translations` | nepřeložené (missing) + osiřelé (orphan) CZ/SK stringy modulu na aktuální větvi |
| `make api-stage` / `make api-prod` | přepne SOAP/WSDL cíl modulu na Packeta stage / zpět na prod (per verze přes `PS=`) |

### Přidání nové verze
1. zkopíruj existující `compose.<tag>.yml` → nový tag, uprav `PS_VERSION`, `PS_ZIP_URL`,
   `DB_NAME`, `PS_DOMAIN`, Traefik labely a `src/<tag>` mount.
2. `make up PS=<tag>`.

## Modul
Žije v `modules/prestashop/packetery` (vlastní git repo `Zasilkovna/prestashop`),
bind-mountnutý do **všech** verzí — edituješ jednou, projeví se ve všech běžících
instancích. **Změny modulu commituješ do modulového repa**, ne sem.

### Kontrola překladů (`make translations`)
Modul používá starý hashovaný systém překladů (`$_MODULE`). `make translations`
projde `l()` volání v PHP i `{l s=…}` v šablonách, spočítá klíče stejně jako jádro
PrestaShopu a porovná je s `cs.php`/`sk.php`:

- **missing** — string v kódu bez záznamu v překladu → na frontu/v adminu svítí anglicky;
- **orphan** — záznam v překladu, který žádný `l()` negeneruje → typicky přejmenovaný
  nebo překlepnutý `source` argument (osiřelý překlad se přestane trefovat).

Scan čte **živý working tree**, takže výsledek vždy odpovídá větvi, na které zrovna jsi
(`make translations` proti `DEFAULT_PS`, `make translations PS=ps91` proti jiné verzi —
verze PS na výsledek nemá vliv, kontejner je jen interpreter).

## SOAP/WSDL cíl modulu (`make api-stage` / `api-prod`)
Modul standardně volá Packeta SOAP API na **produkční** WSDL URL (natvrdo v kódu modulu).
Pro testování proti **stage** prostředí ho lze lokálně přepnout bez editace zdrojáku — přes
override konstantu `_PACKETERY_SOAP_WSDL_URL_` v `config/defines_custom.inc.php` dané verze
(PES-3249; PS hook mimo modul, mimo distribuci).

**1. Doplň stage URL do `.env`** (jednorázově):
```
PACKETERY_SOAP_STAGE_WSDL_URL=…   # WSDL URL stage prostředí
```
> ⚠️ Stage je za **VPN** — bez ní se na URL nedostaneš (modul při volání spadne na timeout).

**2. Přepínej** (per verze přes `PS=`, default `DEFAULT_PS`; bez restartu kontejneru):
```
make api-stage            # ps91 → stage
make api-prod             # ps91 → zpět na prod (override se odstraní)
make api-stage PS=ps82    # jiná verze
```
Platí pro **veškerou** SOAP komunikaci modulu naráz. `api-prod` jen smaže override blok — soubor se
vrátí do původního stavu.

## Konfigurace modulu (`make configure`)
Čerstvě nainstalovaná verze má modul aktivní, ale **nenastavený** — chybí Packeta
API přihlášení (heslo, eshop id) i dopravci, a shop nese **demo data z instalace**
(spousta zemí/zón, ukázkové produkty). `make configure` to srovná do stavu vhodného
pro testování modulu — idempotentně, přes veřejné API PrestaShopu (žádný raw SQL),
opakovaně spustitelné.

**1. Doplň secrets do `.env`** (jednorázově, z Packeta účtu):
```
PACKETERY_APIPASS=…        # API heslo
PACKETERY_ESHOP_ID=…       # eshop id
```
> ⚠️ Dev env volá **produkční** Packeta API — používej reálné dev credentials.

**2. Spusť konfiguraci** proti běžící verzi:
```
make configure PS=ps82
make configure PS=ps82 ARGS="--dry-run"   # jen vypíše, co by udělal (nic nezmění)
```

Co engine nastaví (pořadí = závislosti):
- **module-essentials** — API heslo, eshop id, povolené COD platby
- **locations** — sřízne zóny/země na profil (CZ, SK); ostatní demo země deaktivuje *(enforce exact set)*
- **carriers** — stáhne dostupné Zásilkovna dopravce (cron) + přidá PS dopravce s mapováním *(aditivní dle `packeta_id`)*
- **products** — nahradí demo produkty vlastními testovacími *(enforce exact set — smaže existující, vytvoří deklarované)*

### Profil (co se nastaví)
Cílový stav je deklarativní YAML v `tools/profiles/`:
- `base.yml` — sdílený profil (zóny, dopravci, produkty, COD platby)
- `ps<tag>.yml` — per-verze override nad base

Listy zón/zemí/produktů jsou **„enforce exact set"** (co je v profilu = cílový stav,
zbytek se srovná). **Dopravci jsou výjimka — aditivní** (přidáváš postupně dle `packeta_id`).

### `make carriers` — discovery dopravců
Vypíše dostupné Zásilkovna dopravce (read-only) — odsud bereš `packeta_id` do profilu:
```
make carriers PS=ps82
make carriers PS=ps82 COUNTRY=cz
make carriers PS=ps82 REFRESH=1     # čerstvý feed
```

## Služby
| služba | URL |
|---|---|
| storefront | http://`<tag>`.localhost |
| back office | http://`<tag>`.localhost/`<PS_FOLDER_ADMIN>` |
| Adminer | http://localhost:8081 (vidí všechna schémata `prestashop_<tag>`) |
| Mailpit | http://localhost:8025 |
| MariaDB | localhost:3308 (root / asdf) |

## E-maily (Mailpit)
Mailpit je **sdílený SMTP sink** pro všechny verze — vše odeslané skončí v http://localhost:8025
(žádné maily neodejdou ven). Nastav v adminu každé verze, kterou chceš testovat:

Admin → **Pokročilé parametry → E-mail** (v PS 9 pod **Nástroje → E-maily**):
- zvol **„Nastavit vlastní parametry SMTP"**
- **SMTP server:** `mailpit`  ← container name na sdílené síti, **ne** `localhost`
- **Port:** `1025`
- **Šifrování:** žádné
- **uživatel / heslo:** prázdné (Mailpit přijme cokoliv)

Pak „Poslat testovací e-mail" → dorazí do Mailpit UI.
Pozn.: maily ze všech verzí padají do **jedné** schránky — verze poznáš podle
názvu obchodu v subjektu i odesílateli (`[ps82]` vs `[ps91]`). Název nastaví
`bin/up` z `PS_SHOP_NAME` ve fragmentu verze.

## PhpStorm + Xdebug
Cíl: breakpoint v modulu se zastaví při načtení stránky. Stack-side je Xdebug hotový
(`xdebug.ini`: mode=debug, client_host=host.docker.internal, port 9003, trigger). V PhpStormu:

**1. Debug port** — Settings → PHP → Debug → Xdebug → **Debug port `9003`**, ✓ „Can accept external connections".

**2. Server (jeden per verze)** — Settings → PHP → Servers → „+":
- **Name:** `ps82.local` / `ps91.local` ← musí **přesně** sedět s `PHP_IDE_CONFIG` v `compose.<tag>.yml` (jinak se nespáruje mapping)
- **Host:** `ps82.localhost` / `ps91.localhost` · **Port:** `80` · Debugger: Xdebug
- ✓ **Use path mappings** (dvě):
  - `src/<tag>` → `/var/www/html`
  - `modules/prestashop/packetery` → `/var/www/html/modules/packetery`

**3.** V toolbaru zapni **„Start Listening for PHP Debug Connections"** (chytá všechny verze naráz).

**4. Breakpoint + trigger** — dej breakpoint do modulu. Máme `start_with_request=trigger`, takže
Xdebug se **musí vyvolat** (jinak se nic nestane — nejčastější chyba):
- browser extension **„Xdebug helper"** → Debug (IDE key PHPSTORM), nebo
- přidej do URL **`?XDEBUG_TRIGGER=1`** (např. `http://ps82.localhost/?XDEBUG_TRIGGER=1`)

Načti stránku → PhpStorm skočí na breakpoint. (CLI: `make xdebug PS=… ARGS="…"` posílá trigger sám.)

### Indexace / výkon (důležité)
PrestaShop v dev módu pořád přegenerovává `var/cache` (tisíce souborů) — když je `src/<tag>`
na bind mountu, PhpStorm to vidí a **neustále reindexuje** (pomalé IDE i načítání stránek).

➜ V PhpStormu označ **`src/<tag>/var`** každé verze jako **Excluded**
(pravý klik na složku → *Mark Directory as → Excluded*).

`src/<tag>` (PS core) **nech indexovaný** — autocomplete a navigace do PS API
(`Cart`, `Order`, `Module`, `Db`…) je pro vývoj modulu důležitá. Excluduj jen `var`.

## E2E testy (Playwright)
Proklikávají reálný admin flow modulu Packeta a ověří výsledek v UI i v DB.
Běží na hostu proti běžícímu stacku.

```bash
make e2e-install                          # jednorázově: Node 22 (nvm), deps, prohlížeč
make e2e                                  # všechny testy proti primární verzi
make e2e PS=ps91                          # proti jiné verzi
make e2e ARGS="packet-submit-flow --headed"   # konkrétní test, viditelný prohlížeč
make e2e-report                           # HTML report z posledního běhu
```

> ⚠️ **Volá PRODUKČNÍ Packeta API** — každý běh vytvoří a zruší reálnou zásilku.
> Testy běží sériově a po sobě uklízejí.

Detail (fixture, teardown, struktura, známá omezení): [`e2e/prestashop/README.md`](e2e/prestashop/README.md).

## Architektura (proč takhle)
Image = jen PHP runtime (`images/php81/Dockerfile`), PrestaShop core se **nepeče** —
instaluje se přes CLI proti `src/<tag>` (přidání verze na stejném PHP = bez `docker build`).
Detailní plán: `.notes/plans/multi-version-dev-stack.md`.
