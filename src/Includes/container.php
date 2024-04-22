<?php
declare(strict_types=1);

use DI\Container;
use Psr\Log\NullLogger;
use Grasmash\YamlExpander\YamlExpander;
use Symfony\Component\Yaml\Yaml;

$container->set('settings', function () {
    // Initialize
    $class_sy = new Yaml;
    $class_ye = new YamlExpander(new NullLogger());
    $ret = [];
    $routes = [];
    $seed = [
        'HOSTNAME' => $_SERVER['SERVER_NAME'] ?? gethostbyname(php_uname('n')),
        'ROOTPATH' => __ROOT__,
        'USERVICE' => basename(__ROOT__)
    ];
    $refs['env'] = array_merge($seed, $_ENV);

    // Load and parse the yaml configs. Replace yaml references with $_ENV and $seed ($_ENV has precedence)
    $files[] = __ROOT__ . '/vendor/vaizard/glued-lib/src/defaults.yaml';
    $files = array_merge($files, glob($refs['env']['DATAPATH'] . '/*/config/*.y*ml'));
    foreach ($files as $file) {
        $yaml = file_get_contents($file);
        $array = $class_sy->parse($yaml, $class_sy::PARSE_CONSTANT);
        $ret = array_merge($ret, $class_ye->expandArrayProperties($array, $refs));
    }

    // Read the routes
    $files = glob($ret['glued']['datapath'] . '/*/cache/routes.y*ml');
    foreach ($files as $file) {
        $yaml = file_get_contents($file);
        $array = $class_sy->parse($yaml);
        $routes = array_merge($routes, $class_ye->expandArrayProperties($array)['routes']);
    }

    $ret['routes'] = $routes;
    return $ret;
});

$container->set('pg', function (Container $c) {
    $cnf = $c->get('settings')['pg'];
    $dsn = "pgsql:host={$cnf['host']};dbname={$cnf['database']};options='--client_encoding={$cnf['charset']}'";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $conn = new PDO(
            $dsn,
            $cnf['username'],
            $cnf['password'],
            $options);
        $conn->exec("SET search_path TO {$cnf['schema']}");
    } catch (PDOException $e) {
        throw new \Exception($e->getMessage());
    }
    return $conn;
});