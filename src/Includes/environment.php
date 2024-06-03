<?php
/** @noinspection PhpUndefinedVariableInspection */
declare(strict_types=1);

$settings = $container->get('settings');
foreach ($settings['php']['ini'] as $key => $value) {
    ini_set($key, $value);
}

error_reporting($settings['php']['error_reporting'] ?? 1);
date_default_timezone_set($settings['glued']['timezone']);

$event = $container->get('events');