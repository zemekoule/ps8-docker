# E2E testy modulu Packeta — PrestaShop

Playwright testy, které proklikají reálný admin flow modulu Packeta a ověří
výsledek v UI i v databázi. Běží na hostu proti běžícímu dev stacku.

- **Záměr a rozhodnutí:** [`../../.notes/plans/e2e-playwright-poc.md`](../../.notes/plans/e2e-playwright-poc.md)
- **Co flow dělá (znalost o modulu):** `modules/prestashop/packetery/.notes/e2e/feature-catalog/`

> ⚠️ **Dev env volá PRODUKČNÍ Packeta API.** Každé „Post parcel" vytvoří **reálnou
> zásilku**, každý „Cancel" ji reálně zruší. Testy proto běží sériově (`workers=1`,
> `retries=0`) a po sobě uklízejí (viz Teardown níže).

## První spuštění

Předpoklad: dev stack běží (`make up PS=ps82`) a fixture objednávka existuje
(viz [Fixture](#fixture)).

```bash
make e2e-install          # jednorázově: Node 22 (nvm), npm deps, prohlížeč
make e2e                  # spustí všechny testy proti DEFAULT_PS (ps91)
```

Další způsoby:

```bash
make e2e PS=ps91                                   # proti jiné verzi
make e2e ARGS="packet-submit-flow"                 # konkrétní test
make e2e ARGS="packet-submit-flow --headed"        # s viditelným prohlížečem
make e2e ARGS="--ui"                               # Playwright UI mode (ladění)
make e2e-report                                    # otevře HTML report z posledního běhu
```

Sledování naživo zpomaleně: `SLOWMO=1000 make e2e ARGS="packet-submit-flow --headed"`
(pauza v ms mezi akcemi; defaultně 0 = vypnuto).

Runtime běží pod Node z `.nvmrc` (22 LTS) — `make e2e` ho aktivuje přes `nvm use`,
tvůj výchozí Node se nemění. Konfigurace (admin URL, creds, DB) se čte z root
`.env` dev stacku; lze přebít env proměnnými (`PS`, `FIXTURE_ORDER_ID`, `DB_PORT`…).

## Fixture

Testy potřebují objednávku s Packeta carrierem. Default je **objednávka #7**
(carrier „výdejní místa"). Na čerstvé instalaci ji nelze „najít" — je nutné ji
jednorázově připravit. **Po každém `make drop` + `make up` se příprava opakuje.**

1. **Carrier** — `make configure PS=ps82` (Phase 2 engine nakonfiguruje Packeta dopravce).
2. **Objednávka** — přes frontend (`http://ps82.localhost`) projdi checkout s Packeta
   dopravcem a dokonči objednávku; nebo použij existující.
3. **Zjisti `id_order`** a buď použij #7, nebo nastav `FIXTURE_ORDER_ID`:
   ```bash
   make e2e ARGS="packet-submit-flow"        # FIXTURE_ORDER_ID=7 (default)
   FIXTURE_ORDER_ID=12 make e2e ARGS="packet-submit-flow"
   ```

Test fixture **jen resetuje** do clean stavu (tracking = NULL) v `beforeEach`,
nestaví ji.

## Teardown a úklid reálných zásilek

Hlavní test podá i zruší zásilku v jednom běhu. Navíc `afterEach`
(`ensurePacketCancelled`) zruší zásilku přes UI, **i kdyby test spadl mezi submit
a cancel** → produkční API zůstane čisté.

Kdyby přesto zásilka zůstala podaná (např. tvrdé přerušení běhu), spusť záchranný
úklid:

```bash
make e2e ARGS="cleanup"
```

## Struktura

```
e2e/prestashop/
├── playwright.config.ts        # workers=1, retries=0, baseURL z .env
├── tests/
│   ├── auth.setup.ts           # login → uloží session (.auth/, sdílí se napříč testy)
│   ├── packet-submit-flow.spec.ts   # hlavní flow: podání + zrušení zásilky
│   └── cleanup.spec.ts         # ruční záchranný úklid zbylé zásilky
└── helpers/
    ├── env.ts                  # konfigurace z root .env
    ├── db.ts                   # reset fixture + asserty proti DB (mysql2)
    └── flow.ts                 # navigace na objednávku (+CSRF), robustní cancel
```

## Známá omezení

- **Fixture po `drop`+`up`** se musí znovu připravit ručně (viz výše), dokud to
  nepřevezme rozšíření Phase 2 configure enginu.
- **Runtime = host.** Docker runtime (oficiální Playwright image) je pravděpodobný
  cílový stav pro CI — config je proto env-driven a `make e2e` je fasáda, aby byl
  přechod izolovaná změna. Viz plán R7.
- Zatím **jen `ps82`** a jeden flow (PoC). Multi-verze a další flows = další krok.
