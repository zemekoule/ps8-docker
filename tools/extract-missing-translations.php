<?php
/**
 * Detekce nepřeložených (missing) a osiřelých (orphan) stringů modulu Packeta,
 * který používá legacy hashovaný překlad ($_MODULE).
 *
 * Replikuje PrestaShop Translate::getModuleTranslation key generation a porovná
 * stringy v kódu (PHP `l()` + Smarty `{l s=...}`) s klíči v cs.php / sk.php.
 *
 *   - missing = string v kódu bez záznamu v překladu  -> svítí anglicky
 *   - orphan  = záznam v překladu bez odpovídajícího l() v kódu
 *               -> typicky přejmenovaný / překlepnutý `source`
 *
 * Po čisté opravě má být missing = 0 i orphans = 0.
 *
 * Spuštění (uvnitř PS kontejneru, modul je mountovaný):
 *   docker exec ps82 php /var/www/dev-tools/extract-missing-translations.php \
 *       /var/www/html/modules/packetery
 *
 * Detaily mechanismu: .notes/module/docs/reference/translation-system.md
 *
 * Bez vedlejších efektů — pouze tiskne na stdout, nic nezapisuje.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php extract-missing-translations.php <module_dir>\n");
    exit(2);
}

$moduleDir = rtrim($argv[1], '/');
$moduleName = 'packetery';

/** PS key generation (Translate::getModuleTranslation). */
function psKey($source, $originalString)
{
    $string = preg_replace("/\\\*'/", "\\'", $originalString); // apostrof -> \'
    return strtolower('<{packetery}prestashop>' . $source) . '_' . md5($string);
}

/** Načte $_MODULE z překladového souboru. */
function loadTranslations($file)
{
    $_MODULE = [];
    if (is_file($file)) {
        include $file;
    }
    return $_MODULE;
}

/** Vyhodnotí PHP/Smarty string literál (vč. quotes) na runtime hodnotu. */
function evalLiteral($literal)
{
    $q = $literal[0];
    $inner = substr($literal, 1, -1);
    if ($q === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
    }
    // double-quoted: přeskoč, pokud obsahuje interpolaci proměnné (dynamický string)
    if (preg_match('/(?<!\\\\)\$/', $inner)) {
        return null;
    }
    return stripcslashes($inner);
}

$csKeys = loadTranslations($moduleDir . '/translations/cs.php');
$skKeys = loadTranslations($moduleDir . '/translations/sk.php');

// mapa md5(EN) -> existující překlad (libovolný source) pro nápovědu k převzetí
$reuseCs = [];
$reuseSk = [];
foreach ($csKeys as $k => $v) {
    if (preg_match('/_([0-9a-f]{32})$/', $k, $mm) && !isset($reuseCs[$mm[1]])) {
        $reuseCs[$mm[1]] = $v;
    }
}
foreach ($skKeys as $k => $v) {
    if (preg_match('/_([0-9a-f]{32})$/', $k, $mm) && !isset($reuseSk[$mm[1]])) {
        $reuseSk[$mm[1]] = $v;
    }
}

// --- sběr souborů ---
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS)
);
$found = []; // [ ['source'=>, 'string'=>, 'file'=>], ... ]
$scanKeys = [];

foreach ($rii as $f) {
    $path = $f->getPathname();
    if (strpos($path, '/vendor/') !== false) {
        continue;
    }
    if (strpos($path, '/node_modules/') !== false) {
        continue;
    }
    if (strpos($path, '/translations/') !== false) {
        continue;
    }
    $ext = strtolower($f->getExtension());

    if ($ext === 'php') {
        $tokens = token_get_all(file_get_contents($path));
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'l') {
                continue;
            }
            // následuje '('
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '(') {
                continue;
            }
            // 1. argument = string literál
            $k = $j + 1;
            while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                $k++;
            }
            if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $string = evalLiteral($tokens[$k][1]);
            if ($string === null) {
                continue;
            }
            // volitelný 2. argument = source
            $m = $k + 1;
            while ($m < $n && is_array($tokens[$m]) && $tokens[$m][0] === T_WHITESPACE) {
                $m++;
            }
            $source = $moduleName; // default = název modulu
            if ($m < $n && $tokens[$m] === ',') {
                $p = $m + 1;
                while ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p++;
                }
                if ($p < $n && is_array($tokens[$p]) && $tokens[$p][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $src = evalLiteral($tokens[$p][1]);
                    if ($src !== null && $src !== '') {
                        $source = $src;
                    }
                } else {
                    continue; // dynamický source -> nelze spolehlivě nakeyovat
                }
            }
            $source = strtolower($source);
            $found[] = ['source' => $source, 'string' => $string, 'file' => $path];
            $scanKeys[psKey($source, $string)] = true;
        }
    } elseif ($ext === 'tpl') {
        $code = file_get_contents($path);
        $base = strtolower(basename($path, '.tpl'));
        if (preg_match_all('/\{l\s+(?:[^}]*?\s)?s\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")(.*?)\}/s', $code, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $match) {
                if (strpos($match[0], 'mod=') === false) {
                    continue;
                }
                $string = evalLiteral($match[1]);
                if ($string === null) {
                    continue;
                }
                $found[] = ['source' => $base, 'string' => $string, 'file' => $path];
                $scanKeys[psKey($base, $string)] = true;
            }
        }
    }
}

// --- missing ---
$missing = [];
foreach ($found as $rec) {
    $key = psKey($rec['source'], $rec['string']);
    $missCs = empty($csKeys[$key]);
    $missSk = empty($skKeys[$key]);
    if (!$missCs && !$missSk) {
        continue;
    }
    $dedup = $rec['source'] . "\x00" . $rec['string'];
    if (!isset($missing[$dedup])) {
        $missing[$dedup] = [
            'source' => $rec['source'],
            'string' => $rec['string'],
            'missCs' => $missCs,
            'missSk' => $missSk,
            'files' => [],
        ];
    }
    $rel = str_replace($moduleDir . '/', '', $rec['file']);
    if (!in_array($rel, $missing[$dedup]['files'], true)) {
        $missing[$dedup]['files'][] = $rel;
    }
}

// --- orphans (klíče v cs.php, které žádný l() negeneruje) ---
$orphans = array_diff_key($csKeys, $scanKeys);

// --- výstup ---
$scanned = count(array_unique(array_map(function ($r) {
    return $r['source'] . "\x00" . $r['string'];
}, $found)));

echo "Scanned distinct (source,string): $scanned\n";
echo "Missing (CZ or SK): " . count($missing) . "\n";
echo "Orphans (in cs.php, no l() produces them): " . count($orphans) . "\n";
echo str_repeat('=', 78) . "\n";

if ($missing) {
    // seskup podle (flag, source, files) -> jedna hlavička, výpis všech EN stringů
    $groups = [];
    foreach ($missing as $m) {
        $flag = ($m['missCs'] ? 'CZ' : '') . ($m['missCs'] && $m['missSk'] ? '+' : '') . ($m['missSk'] ? 'SK' : '');
        sort($m['files']);
        $gkey = $flag . "\x00" . $m['source'] . "\x00" . implode('|', $m['files']);
        if (!isset($groups[$gkey])) {
            $groups[$gkey] = ['flag' => $flag, 'source' => $m['source'], 'files' => $m['files'], 'strings' => []];
        }
        $groups[$gkey]['strings'][] = $m['string'];
    }
    uasort($groups, function ($a, $b) {
        return [$a['source'], $a['flag']] <=> [$b['source'], $b['flag']];
    });

    echo "\n--- MISSING ---\n";
    foreach ($groups as $g) {
        echo "[{$g['flag']}] source={$g['source']}\n";
        foreach ($g['strings'] as $s) {
            $h = md5(preg_replace("/\\\*'/", "\\'", $s));
            $rc = $reuseCs[$h] ?? null;
            $rk = $reuseSk[$h] ?? null;
            $hint = '';
            if ($rc !== null || $rk !== null) {
                $hint = '   ↺ CS: ' . ($rc ?? '—') . ' | SK: ' . ($rk ?? '—');
            }
            echo "  EN: $s$hint\n";
        }
        echo "  files: " . implode(', ', $g['files']) . "\n\n";
    }
}

if ($orphans) {
    // seskup podle source (prefix v klíči) -> výpis překladů, které žádný l() negeneruje
    $ogroups = [];
    foreach ($orphans as $key => $value) {
        $src = preg_match('/>(.+)_[0-9a-f]{32}$/', $key, $mm) ? $mm[1] : '(?)';
        $ogroups[$src][] = $value;
    }
    ksort($ogroups);

    echo "\n--- ORPHANS ---\n";
    foreach ($ogroups as $src => $vals) {
        echo "source=$src\n";
        foreach ($vals as $v) {
            echo "  $v\n";
        }
        echo "\n";
    }
}

// Report tool — vrací 0 i když něco najde (výsledek čteš z výpisu, ne z exit kódu).
// Pro CI gate by se přidal samostatný --strict režim.
exit(0);
