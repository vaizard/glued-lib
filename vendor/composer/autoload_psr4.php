<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);

return array(
    'voku\\helper\\' => array($vendorDir . '/voku/anti-xss/src/voku/helper'),
    'voku\\' => array($vendorDir . '/voku/portable-ascii/src/voku', $vendorDir . '/voku/portable-utf8/src/voku'),
    'Symfony\\Polyfill\\Php72\\' => array($vendorDir . '/symfony/polyfill-php72'),
    'Symfony\\Polyfill\\Mbstring\\' => array($vendorDir . '/symfony/polyfill-mbstring'),
    'Symfony\\Polyfill\\Intl\\Normalizer\\' => array($vendorDir . '/symfony/polyfill-intl-normalizer'),
    'Symfony\\Polyfill\\Intl\\Grapheme\\' => array($vendorDir . '/symfony/polyfill-intl-grapheme'),
    'Symfony\\Polyfill\\Iconv\\' => array($vendorDir . '/symfony/polyfill-iconv'),
    'Slim\\' => array($vendorDir . '/slim/slim/Slim'),
    'Selective\\Transformer\\' => array($vendorDir . '/selective/transformer/src'),
    'Psr\\Log\\' => array($vendorDir . '/psr/log/src'),
    'Psr\\Http\\Server\\' => array($vendorDir . '/psr/http-server-handler/src', $vendorDir . '/psr/http-server-middleware/src'),
    'Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-message/src', $vendorDir . '/psr/http-factory/src'),
    'Psr\\Container\\' => array($vendorDir . '/psr/container/src'),
    'ParagonIE\\ConstantTime\\' => array($vendorDir . '/paragonie/constant_time_encoding/src'),
    'ParagonIE\\CSPBuilder\\' => array($vendorDir . '/paragonie/csp-builder/src'),
    'Monolog\\' => array($vendorDir . '/monolog/monolog/src/Monolog'),
    'Middlewares\\Utils\\' => array($vendorDir . '/middlewares/utils/src'),
    'Middlewares\\' => array($vendorDir . '/middlewares/csp/src', $vendorDir . '/middlewares/trailing-slash/src'),
    'JsonSchema\\' => array($vendorDir . '/justinrainbow/json-schema/src/JsonSchema'),
    'Glued\\Lib\\' => array($baseDir . '/src'),
    'FastRoute\\' => array($vendorDir . '/nikic/fast-route/src'),
    'Ergebnis\\Json\\SchemaValidator\\' => array($vendorDir . '/ergebnis/json-schema-validator/src'),
    'Ergebnis\\Json\\Printer\\' => array($vendorDir . '/ergebnis/json-printer/src'),
    'Ergebnis\\Json\\Normalizer\\' => array($vendorDir . '/ergebnis/json-normalizer/src'),
    'Ergebnis\\Composer\\Normalize\\' => array($vendorDir . '/ergebnis/composer-normalize/src'),
);
