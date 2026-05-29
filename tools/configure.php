<?php
/**
 * Configure engine (fáze 2) — post-install nastavení PS + modulu Packeta.
 *
 * Bootne PrestaShop, načte deklarativní YAML profil (base + per-verze override),
 * a aplikuje nastavení přes VEŘEJNÉ API (ObjectModely + modulový DI), idempotentně.
 * ŽÁDNÝ raw SQL. Secrets (API heslo, eshop id) z ENV, ne z profilu.
 *
 * Spouští se v kontejneru: php /var/www/dev-tools/configure.php --profile=<tag> [--dry-run]
 * (obvykle přes `bin/configure` / `make configure PS=<tag>`).
 *
 * F2-1 = skeleton: boot + load/merge profilu + DI + dispatch framework.
 * Reálné kroky (module/locations/carriers/products) se doplní v F2-2..F2-5.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$opts    = getopt('', ['profile:', 'dry-run']);
$tag     = $opts['profile'] ?? getenv('PS_TAG') ?: null;
$dryRun  = isset($opts['dry-run']);
if (!$tag) {
    fwrite(STDERR, "✗ chybí --profile=<tag>\n");
    exit(1);
}

echo "=== configure: $tag" . ($dryRun ? " (dry-run)" : "") . " ===\n";

/* ---- 1-2. Boot PrestaShop + modulový DI (sdílený bootstrap) ---------------- */
[$module, $di] = require __DIR__ . '/_bootstrap.php';
echo "▸ PS bootnut: " . _PS_VERSION_ . "\n";
echo "▸ modulový DI: " . get_class($di) . "\n";

/* ---- 3. Profil (base ← per-verze override) -------------------------------- */
$profile = load_profile(__DIR__ . '/profiles', $tag);
echo "▸ profil načten: sekce [" . implode(', ', array_keys($profile)) . "]\n";

/* ---- 4. Secrets z ENV (ne z profilu) -------------------------------------- */
$secrets = [
    'apipass'  => getenv('PACKETERY_APIPASS') ?: '',
    'eshop_id' => getenv('PACKETERY_ESHOP_ID') ?: '',
];
echo "▸ secrets: apipass=" . mask($secrets['apipass']) . ", eshop_id=" . mask($secrets['eshop_id']) . "\n";

/* ---- 5. Kontext pro kroky -------------------------------------------------- */
$ctx = (object) [
    'tag'     => $tag,
    'profile' => $profile,
    'secrets' => $secrets,
    'di'      => $di,
    'module'  => $module,
    'dryRun'  => $dryRun,
];

/* ---- 6. Dispatch kroků (pořadí = DAG závislostí) -------------------------- */
// Každý krok = [název => callable(ctx)]. Doplní se v F2-2..F2-5.
$steps = [
    'module-essentials' => 'step_module_essentials', // F2-2: API heslo, eshop id, COD platby
    'locations'         => 'step_locations', // F2-3: zóny → země (enforce exact set)
    'carriers'          => 'step_carriers', // F2-4: PS dopravci → cron download → přiřazení
    'products'          => 'step_products', // F2-5: seed produktů (+ adult)
];

$failed = 0;
foreach ($steps as $name => $fn) {
    if ($fn === null) {
        echo "  · $name — TODO (zatím neimplementováno)\n";
        continue;
    }
    try {
        echo "▸ $name\n";
        $fn($ctx);
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "  ✗ $name selhal: " . $e->getMessage() . "\n");
    }
}

echo $failed ? "✗ configure: $failed krok(ů) selhalo\n" : "✓ configure hotovo\n";
exit($failed ? 1 : 0);

/* ============================ kroky ======================================== */

/**
 * F2-2 — esenciální nastavení modulu: API heslo + eshop id (z ENV secrets) + COD platby.
 * Vše přes veřejné API: ConfigHelper::update (static) a PaymentRepository (DI). Idempotentní.
 */
function step_module_essentials(object $ctx): void
{
    // API heslo (z .env, ne z profilu)
    if ($ctx->secrets['apipass'] !== '') {
        if (!$ctx->dryRun) {
            \Packetery\Tools\ConfigHelper::update('PACKETERY_APIPASS', $ctx->secrets['apipass']);
        }
        echo "    PACKETERY_APIPASS ← .env (" . strlen($ctx->secrets['apipass']) . " zn.)\n";
    } else {
        echo "    PACKETERY_APIPASS — přeskočeno (prázdné v .env)\n";
    }

    // Označení odesílatele (z .env)
    if ($ctx->secrets['eshop_id'] !== '') {
        if (!$ctx->dryRun) {
            \Packetery\Tools\ConfigHelper::update('PACKETERY_ESHOP_ID', $ctx->secrets['eshop_id']);
        }
        echo "    PACKETERY_ESHOP_ID ← .env\n";
    } else {
        echo "    PACKETERY_ESHOP_ID — přeskočeno (prázdné v .env)\n";
    }

    // COD platby — module_name plateb = dobírka (z profilu)
    $cod = $ctx->profile['cod_payments'] ?? [];
    if ($cod) {
        $repo = $ctx->di->get(\Packetery\Payment\PaymentRepository::class);
        foreach ($cod as $moduleName) {
            if (!$ctx->dryRun) {
                $repo->setOrInsert(1, $moduleName);
            }
            echo "    COD: $moduleName → is_cod=1\n";
        }
    } else {
        echo "    COD: žádné platby v profilu\n";
    }
}

/**
 * F2-3 — lokace: zóny + země, "enforce exact set" (deklaruješ → skript srovná).
 * Pořadí: zajistit deklarované zóny → srovnat země (active + id_zone) → smazat nedeklarované zóny.
 * Vše přes Zone/Country ObjectModely (veřejné PS API), idempotentní.
 */
function step_locations(object $ctx): void
{
    $loc           = $ctx->profile['locations'] ?? [];
    $declZones     = $loc['zones'] ?? [];
    $declCountries = $loc['countries'] ?? [];
    if (!$declZones && !$declCountries) {
        echo "    lokace: profil prázdný, přeskočeno\n";
        return;
    }

    // 1. Zajistit deklarované zóny → mapa name→id
    $zoneId = [];
    foreach (\Zone::getZones(false) as $z) {
        $zoneId[$z['name']] = (int) $z['id_zone'];
    }
    foreach ($declZones as $name) {
        if (!isset($zoneId[$name])) {
            if (!$ctx->dryRun) {
                $z = new \Zone();
                $z->name   = $name;
                $z->active = true;
                $z->add();
                $zoneId[$name] = (int) $z->id;
            }
            echo "    zóna vytvořena: $name\n";
        }
    }

    // 2. Země — enforce exact set (deklarované active + id_zone; ostatní deactivate)
    $idLang   = (int) \Configuration::get('PS_LANG_DEFAULT');
    $declIso  = [];
    foreach ($declCountries as $c) {
        $iso = strtoupper($c['iso']);
        $declIso[] = $iso;
        $id = (int) \Country::getByIso($iso);
        if (!$id) {
            echo "    ⚠ země $iso neexistuje, přeskočeno\n";
            continue;
        }
        if (!$ctx->dryRun) {
            $country = new \Country($id);
            $country->active  = true;
            if (isset($c['zone'], $zoneId[$c['zone']])) {
                $country->id_zone = $zoneId[$c['zone']];
            }
            $country->save();
        }
        echo "    země aktivní: $iso → zóna " . ($c['zone'] ?? '?') . "\n";
    }
    $deactivated = 0;
    foreach (\Country::getCountries($idLang, false) as $c) {
        if (!empty($c['active']) && !in_array(strtoupper($c['iso_code']), $declIso, true)) {
            if (!$ctx->dryRun) {
                $cc = new \Country((int) $c['id_country']);
                $cc->active = false;
                $cc->save();
            }
            $deactivated++;
        }
    }
    echo "    země deaktivováno (mimo profil): $deactivated\n";

    // 3. Smazat nedeklarované zóny (až po přeřazení zemí)
    $deletedZones = 0;
    foreach (\Zone::getZones(false) as $z) {
        if (!in_array($z['name'], $declZones, true)) {
            try {
                if (!$ctx->dryRun) {
                    (new \Zone((int) $z['id_zone']))->delete();
                }
                $deletedZones++;
            } catch (\Throwable $e) {
                echo "    ⚠ zóna '{$z['name']}' nešla smazat: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "    zón smazáno (mimo profil): $deletedZones\n";
}

/**
 * F2-4 (část 1) — stažení Zásilkovna dopravců (cron přes DI) + výpis dostupných.
 * PS dopravci (create/delete) + mapování = část 2 (až podle reálných dat).
 * Vyžaduje PACKETERY_APIPASS (volá prod API).
 */
function step_carriers(object $ctx): void
{
    if ($ctx->secrets['apipass'] === '') {
        echo "    carriers: přeskočeno (chybí PACKETERY_APIPASS v .env — cron download nelze)\n";
        return;
    }

    // 1. Cron download dostupných dopravců → ps_packetery_carriers (přes modulový DI, bez HTTP/tokenu)
    if (!$ctx->dryRun) {
        $task   = $ctx->di->get(\Packetery\Cron\Tasks\DownloadCarriers::class);
        $errors = $task->execute();
        if (!empty($errors)) {
            echo "    ⚠ download dopravců vrátil chyby: " . json_encode($errors, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "    cron download dopravců OK\n";
        }
    }

    // 2. Stručný souhrn (detailní výpis je v `make carriers [COUNTRY=xx]`)
    $isos = [];
    foreach ($ctx->profile['locations']['countries'] ?? [] as $c) {
        $isos[] = strtoupper($c['iso']);
    }
    if ($isos) {
        $repo  = $ctx->di->get(\Packetery\ApiCarrier\ApiCarrierRepository::class);
        $avail = $repo->getByCountries($isos);
        echo "    dostupných Zásilkovna dopravců pro [" . implode(',', $isos) . "]: " . count($avail)
            . " (výpis: make carriers COUNTRY=…)\n";
    }

    // 3. PS dopravci (create/delete) + mapování Zásilkovna↔PS — F2-4 část 2 (až podle reálných dat).
    echo "    (PS dopravci + mapování: F2-4 část 2 — až po ručním ověření)\n";
}

/**
 * F2-5 — seed testovacích produktů (enforce exact set): smazat existující, vytvořit deklarované.
 * Cena bez desetin/realistická, váha s/bez, příznak adult (per-produkt přes modulový repo).
 * Přes Product ObjectModel + StockAvailable (veřejné PS API), ne raw SQL.
 */
function step_products(object $ctx): void
{
    $declared = $ctx->profile['products'] ?? [];
    if (!$declared) {
        echo "    products: profil prázdný, přeskočeno\n";
        return;
    }
    $idLang  = (int) \Configuration::get('PS_LANG_DEFAULT');
    $homeCat = (int) \Configuration::get('PS_HOME_CATEGORY');

    // Potlač jen známý neškodný CLI warning (Product.php čte $context->controller->controller_type,
    // controller je v CLI null). Ostatní chyby projdou normálně.
    set_error_handler(static function ($no, $str) {
        return strpos($str, 'controller_type') !== false;
    }, E_WARNING);

    // 1. Enforce exact set — smazat existující produkty
    $existing = \Product::getProducts($idLang, 0, 100000, 'id_product', 'ASC');
    if (!$ctx->dryRun) {
        foreach ($existing as $row) {
            (new \Product((int) $row['id_product']))->delete();
        }
    }
    echo "    smazáno existujících produktů: " . count($existing) . "\n";

    // 2. Vytvořit deklarované
    $attrRepo = $ctx->di->get(\Packetery\Product\ProductAttributeRepository::class);
    foreach ($declared as $prod) {
        $name   = $prod['name'];
        $weight = array_key_exists('weight', $prod) ? (float) $prod['weight'] : null;
        if ($ctx->dryRun) {
            echo "    + $name (cena {$prod['price']}, váha " . ($weight ?? '—') . ", adult " . (!empty($prod['adult']) ? 'ano' : 'ne') . ")\n";
            continue;
        }
        $p                     = new \Product();
        $p->name               = [$idLang => $name];
        $p->link_rewrite       = [$idLang => \Tools::str2url($name)];
        $p->price              = (float) $prod['price'];
        $p->id_category_default = $homeCat;
        $p->active             = 1;
        if ($weight !== null) {
            $p->weight = $weight;
        }
        $p->add();
        $p->addToCategories([$homeCat]);
        \StockAvailable::setQuantity((int) $p->id, 0, 100);

        if (!empty($prod['adult'])) {
            $attrRepo->insert(['id_product' => (int) $p->id, 'is_adult' => 1]);
        }
        echo "    produkt: $name (cena {$prod['price']}, váha " . ($weight ?? '—') . ", adult " . (!empty($prod['adult']) ? 'ano' : 'ne') . ")\n";
    }

    restore_error_handler();
}

/* ============================ helpers ====================================== */

/** Načte base.yml a zmerguje s <tag>.yml (override vyhrává; asoc. pole rekurzivně, listy nahradí). */
function load_profile(string $dir, string $tag): array
{
    $base = parse_yaml("$dir/base.yml");
    $over = file_exists("$dir/$tag.yml") ? parse_yaml("$dir/$tag.yml") : [];
    return deep_merge($base, $over);
}

function parse_yaml(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
        fwrite(STDERR, "✗ Symfony Yaml nedostupný — nelze parsovat $path\n");
        exit(1);
    }
    return (array) (\Symfony\Component\Yaml\Yaml::parseFile($path) ?? []);
}

/** Asociativní klíče se mergují rekurzivně; sekvenční (list) hodnoty override NAHRADÍ (enforce exact set). */
function deep_merge(array $base, array $over): array
{
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && is_assoc($v) && is_assoc($base[$k])) {
            $base[$k] = deep_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function is_assoc(array $a): bool
{
    return $a !== [] && array_keys($a) !== range(0, count($a) - 1);
}

function mask(string $s): string
{
    if ($s === '') {
        return '(prázdné)';
    }
    return strlen($s) <= 4 ? '****' : substr($s, 0, 2) . '…' . substr($s, -2) . ' (' . strlen($s) . ' zn.)';
}
