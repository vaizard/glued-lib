<?php
declare(strict_types=1);

use DI\Container;
use Psr\Log\NullLogger;
use Grasmash\YamlExpander\YamlExpander;
use Symfony\Component\Yaml\Yaml;
use Glued\Lib\IfUtils;
use Glued\Lib\Crypto;
use Glued\Lib\Utils;
use Opis\JsonSchema\Validator;
use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Phpfastcache\Helper\Psr16Adapter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Monolog\Handler\TelegramBotHandler;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\DeduplicationHandler;
use Ramsey\Uuid\Uuid;

$container->set('fscache', function () {
    try {
        $path = $_ENV['DATAPATH'] . '/' . basename(__ROOT__) . '/cache/psr16';
        CacheManager::setDefaultConfig(new ConfigurationOption([
            "path" => $path,
            "itemDetailedDate" => false,
        ]));
        return new Psr16Adapter('files');
    } catch (Exception $e) {
        throw new \Exception("Path {$path} not writable. Rerun `composer configure`. {$e->getMessage()}", $e->getCode());
    }
});

$container->set('memcache', function () {
    CacheManager::setDefaultConfig(new ConfigurationOption([
        "defaultTtl" => 60,
    ]));
    return new Psr16Adapter('apcu');
});

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

$container->set('uuid', function () {
    return Uuid::uuid4()->toText();
});

$container->set('pg', function (Container $c) {
    $cnf = $c->get('settings')['pgsql'];
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

$container->set('ifutils', function (Container $c) {
    return new IfUtils($c->get('pg'), $c->get('settings'));
});

$container->set('my', function (Container $c) {
    $db = $c->get('settings')['mysql'];
    $mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
    $mysqli->set_charset($db['charset']);
    $mysqli->query("SET collation_connection = " . $db['collation']);
    return $mysqli;
});

$container->set('logger', function (Container $c)
{
    // Create the main logger, add the UidProcessor to generate a unique id to each log record
    $settings = $c->get('settings')['logger'];
    $logger = new Logger($settings['name']);
    $processor = new UidProcessor();
    $logger->pushProcessor($processor);

    // Create handler: stream to file
    $handlers['stream'] = new StreamHandler($settings['path'], $settings['level']);
    // Create handler: telegram
    $tg = $c->get('settings')['notify']['network']['telegram'] ?? null;

    if (!is_null($tg['channels'][0]['dsn'])) {
        // Extract the query string from the DSN
        $chatid = $tg['dst'][0];
        $apikey = parse_url($tg['channels'][0]['dsn'], PHP_URL_USER) . ":" . parse_url($tg['channels'][0]['dsn'], PHP_URL_PASS);
        // Create the TelegramBotHandler
        $handlers['tg'] = new TelegramBotHandler(
            apiKey: $apikey,
            channel: $chatid,
            level: $settings['level'],
            parseMode: 'Markdown',
            delayBetweenMessages: true
        );
    }

    // Avoid app failure in case of logger failure (i.e. don't throw an exception if the stream logger path
    // is not writable. Use telegram, e-mail, etc. Deduplicate same error messages to prevent worst spamming.
    $handler = new FallbackGroupHandler($handlers);
    $handler = new DeduplicationHandler($handler);
    $logger->pushHandler($handler);

    if (!is_writable($settings['path'])) { $logger->error('Log path not writable.', [$settings['path']]); }
    return $logger;
});

$container->set('notify', function (Container $c) {
    // TODO cleanup codebase from Crypto initialization
    return new Glued\Lib\Notify($c->get('settings'), $c->get('logger'));
});

$container->set('transform', function () {
    return new ArrayTransformer();
});

$container->set('validator', function () {
    return new Validator;
});

$container->set('routecollector', $app->getRouteCollector());

$container->set('responsefactory', $app->getResponseFactory());

$container->set('utils', function (Container $c) {
    return new Utils($c->get('settings'), $c->get('routecollector'));
});

$container->set('crypto', function () {
    return new Crypto();
});

$container->set('reqfactory', function () {
    return Psr17FactoryDiscovery::findRequestFactory();
});

$container->set('urifactory', function () {
    return Psr17FactoryDiscovery::findUriFactory();
});

