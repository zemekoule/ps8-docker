<?php
/**
 * Sdílený bootstrap pro dev-tools skripty (configure.php, carriers.php).
 * Bootne PrestaShop a vrátí [Module, DI container]. Verzově nezávislé (1.7/8/9).
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require '/var/www/html/config/config.inc.php';

if (!Module::isInstalled('packetery')) {
    fwrite(STDERR, "✗ modul packetery NENÍ na této verzi nainstalován — nejdřív ho nainstaluj (admin → Moduly, příp. CLI).\n");
    exit(2);
}

$module = Module::getInstanceByName('packetery');
if (!$module || !isset($module->diContainer) || !is_object($module->diContainer)) {
    fwrite(STDERR, "✗ modul packetery / DI container nedostupný\n");
    exit(1);
}

return [$module, $module->diContainer];
