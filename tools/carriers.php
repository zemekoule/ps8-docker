<?php
/**
 * Discovery: vypíše dostupné Zásilkovna dopravce (read-only, nic nemutuje).
 *   php carriers.php [--country=de] [--refresh]
 * - --country=xx → všichni dopravci dané země
 * - bez --country → prvních N (default 10)
 * - --refresh → vynutí nový download z API (jinak použije cache; když je prázdná, stáhne)
 * Spouští se přes `bin/carriers` / `make carriers [PS=ps82] [COUNTRY=xx] [REFRESH=1]`.
 */
$opts    = getopt('', ['country:', 'refresh', 'limit:']);
$country = isset($opts['country']) && $opts['country'] !== '' ? strtoupper($opts['country']) : null;
$limit   = (int) ($opts['limit'] ?? 10);

[$module, $di] = require __DIR__ . '/_bootstrap.php';

$apiRepo = $di->get(\Packetery\ApiCarrier\ApiCarrierRepository::class);

// Zajistit stažené dopravce (cache); download při prázdné cache nebo --refresh.
$ids = $apiRepo->getCarrierIds();
if (empty($ids) || isset($opts['refresh'])) {
    if (getenv('PACKETERY_APIPASS')) {
        $errors = $di->get(\Packetery\Cron\Tasks\DownloadCarriers::class)->execute();
        echo empty($errors)
            ? "(dopravci staženi z API)\n"
            : "⚠ download: " . json_encode($errors, JSON_UNESCAPED_UNICODE) . "\n";
        $ids = $apiRepo->getCarrierIds();
    } else {
        fwrite(STDERR, "⚠ dopravci nejsou stažení a chybí PACKETERY_APIPASS v .env\n");
    }
}

if ($country) {
    $list = $apiRepo->getByCountries([$country]);
    echo "Zásilkovna dopravci pro {$country}: " . count($list) . "\n";
    print_carriers($list);
} else {
    echo "Prvních {$limit} dopravců (zadej COUNTRY=xx pro všechny dané země):\n";
    $rows = [];
    foreach (array_slice($ids, 0, $limit) as $idRow) {
        $id  = is_array($idRow) ? ($idRow['id'] ?? null) : $idRow;
        $row = $id !== null ? $apiRepo->getById($id) : null;
        if ($row) {
            $rows[] = (array) $row;
        }
    }
    print_carriers($rows);
}

function print_carriers(array $list): void
{
    foreach ($list as $c) {
        $c    = (array) $c;
        $id   = $c['id'] ?? $c['id_branch'] ?? '?';
        $name = $c['name'] ?? $c['name_branch'] ?? '?';
        $ctry = $c['country'] ?? '';
        echo "  - [{$id}] {$name}" . ($ctry ? " ({$ctry})" : "") . "\n";
    }
}
