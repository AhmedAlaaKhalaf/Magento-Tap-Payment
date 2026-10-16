<?php
declare(strict_types=1);

/**
 * Standalone runs: point MAGENTO_ROOT at a Magento 2.4 codebase (defaults to
 * .dev/magento). Inside a Magento project, Magento's own unit test config
 * (dev/tests/unit/phpunit.xml.dist) picks these tests up without this file.
 */
$magentoRoot = getenv('MAGENTO_ROOT') ?: dirname(__DIR__, 2) . '/.dev/magento';
$autoload = $magentoRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Magento codebase not found. Set MAGENTO_ROOT (looked in {$magentoRoot}).\n");
    exit(1);
}

$loader = require $autoload;
$loader->addPsr4('Tap\\Payment\\', dirname(__DIR__, 2) . '/');
require_once $magentoRoot . '/vendor/magento/framework/Phrase/__.php';
