<?php
declare(strict_types=1);
namespace Glued\Lib;

use Composer\Script\Event;
use Dotenv\Dotenv;
use Glued\Lib\Crypto;
use Grasmash\YamlExpander\YamlExpander;
use PharData;
use Psr\Log\NullLogger;
use ParagonIE\CSPBuilder\CSPBuilder;
use Symfony\Component\Yaml\Yaml;

define("__ROOT__", getcwd());

class ComposerHooks
{
    public static function preInstall(Event $event): void
    {
        echo "[NOTE] INSTALLING GLUED";
    }

    public static function postPackageInstall(Event $event): void
    {
        $installedPackage = $event->getComposer()->getPackage();
        echo "[NOTE] GLUED INSTALLED";
    }

    public static function genKey(Event $event): void
    {
        $crypto = new Crypto();
        echo $crypto->genkey_base64() . PHP_EOL . PHP_EOL;
    }

    public static function openApiToRoutes($openApiFile, $routesFile = false): bool | string
    {
        $openapiArray = yaml_parse_file($openApiFile);
        $routes = [];
        foreach ($openapiArray['paths'] as $path => $details) {
            $methods = [];
            foreach ($details as $method => $data) {
                if ($method !== 'x-glued-pathname' && $method !== 'x-glued-provides') {
                    $methodValue = $data['x-glued-method'];
                    if ($methodValue !== null) {
                        $methods[$method] = $methodValue;
                    }
                }
            }
            if ($methods == []) { throw new \Exception("x-glued-method missing for {$path}"); }
            if (empty($details['x-glued-pathname'])) { throw new \Exception("x-glued-pathname missing for {$path}"); }

            $serverUrl = $openapiArray['servers'][0]['url'];
            $parsedUrl = parse_url($serverUrl);

            if (isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                $absolutePath = $parsedUrl['path'] ?? '/';
                $server = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
            } else {
                $absolutePath = $serverUrl;
                $server = null;
            }

            $firstOp = null;
            foreach ($details as $child) {
                if (is_array($child) && isset($child['summary'])) {
                    $firstOp = $child;
                    break;
                }
            }

            $routes[$details['x-glued-pathname']] = [
                'pattern'  => $absolutePath . $path,
                'label'    => $firstOp['summary']     ?? '',
                'dscr'     => $firstOp['description'] ?? '',
                'provides' => $details['x-glued-provides'] ?? throw new \Exception("x-glued-provides key missing for {$path}"),
                'service'  => $openapiArray['info']['x-glued-service'] ?? throw new \Exception("x-glued-service key missing for {$path}"),
                'methods'  => $methods,
                'server'   => $server
            ];
        }
        if (!$routesFile) {
            return yaml_emit(['routes' => $routes], YAML_UTF8_ENCODING);
        } else {
            yaml_emit_file($routesFile, ['routes' => $routes], YAML_UTF8_ENCODING);
            return true;
        }
    }

    public static function getSettings(): array
    {
        $ret = $routes = $config = [];
        $seed = ['ROOTPATH' => __ROOT__, 'USERVICE' => basename(__ROOT__)];

        // Load .env only when not in production
        if (!getenv('GLUED_PROD')) {
            Dotenv::createImmutable(__ROOT__)->safeLoad();
            echo "[INFO] GLUED_PROD not set, loading `" . __ROOT__ . "/.env`" . PHP_EOL;
        } else {
            echo "[INFO] GLUED_PROD set, ignoring `" . __ROOT__ . "/.env`" . PHP_EOL;
        }

        // Validate env
        $hostnames = php_uname('n') . ' ' . gethostbyname(php_uname('n')) . ' ' . ($_SERVER['SERVER_NAME'] ?? '');
        if (!getenv('HOSTNAME') || getenv('HOSTNAME') === '') {
            die('[FAIL] hostname env variable not set or is empty. Suggestions: ' . $hostnames . PHP_EOL . PHP_EOL);
        }
        if (!getenv('DATAPATH')) { die('[FAIL] DATAPATH env variable not set.' . PHP_EOL . PHP_EOL); }
        if (!getenv('IDENTITY')) { die('[FAIL] IDENTITY env variable not set.' . PHP_EOL . PHP_EOL); }

        // Restricted env map for expansion (add more keys if your YAML uses them)
        $refs['env'] = array_merge($seed, [
            'HOSTNAME'        => getenv('HOSTNAME') ?: '',
            'DATAPATH'        => getenv('DATAPATH') ?: '',
            'IDENTITY'        => getenv('IDENTITY') ?: '',
            'GLUED_PROD'      => getenv('GLUED_PROD') ?: '',
            'MYSQL_HOSTNAME'  => getenv('MYSQL_HOSTNAME') ?: '',
            'MYSQL_USERNAME'  => getenv('MYSQL_USERNAME') ?: '',
            'MYSQL_PASSWORD'  => getenv('MYSQL_PASSWORD') ?: '',
            'MYSQL_DATABASE'  => getenv('MYSQL_DATABASE') ?: '',
            'GEOIP'           => getenv('GEOIP') ?: '',
        ]);

        // Load defaults + all module configs
        $class_sy = new Yaml;
        $class_ye = new YamlExpander(new NullLogger());
        $files = [__ROOT__ . '/vendor/vaizard/glued-lib/src/defaults.yaml'];
        $files = array_merge($files, glob($refs['env']['DATAPATH'] . '/*/config/*.yaml'));

        foreach ($files as $file) {
            try {
                $yaml = file_get_contents($file);
                $array = $class_sy->parse($yaml, $class_sy::PARSE_CONSTANT);
                $config = array_replace_recursive($config, $class_ye->expandArrayProperties($array));
            } catch (\Exception $e) {
                echo "Problem processing file $file";
                print_r($e);
            }
        }

        $ret = $class_ye->expandArrayProperties($config, $refs);

        // Read the routes
        $files = glob($ret['glued']['datapath'] . '/*/cache/routes.yaml');
        foreach ($files as $file) {
            try {
                $yaml = file_get_contents($file);
                $array = $class_sy->parse($yaml);
                $routes = array_merge($routes, $class_ye->expandArrayProperties($array)['routes']);
            } catch (\Exception $e) {
                echo "Problem processing file $file";
                print_r($e);
            }
        }
        $ret['routes'] = $routes;
        return $ret;
    }

    public static function printSettings(): void {
        print_r(self::getSettings());
    }

    public static function generatePHPFPM(): void {
        // Stub for feature compatibility
    }

    public static function generateNginx(): void
    {
        $settings = self::getSettings();
        $comment = <<<EOT
        # NOTE that when this file is found under /etc/nginx
        # it is (re)generated by the glued-core installer.
        # All manual edits will be overwritten. Override by
        # changing defaults.yaml

        EOT;

        echo "[INFO] Generating common server name." . PHP_EOL;
        $output = <<<EOT
        server_name {$settings['glued']['hostname']};
        EOT;
        file_put_contents('/etc/nginx/snippets/server/generated_name.conf', $comment.$output);

        if (isset($settings['glued']['openapi']['hostname'])) {
            echo "[INFO] Generating openapi server name." . PHP_EOL;
            $output = <<<EOT
            server_name {$settings['glued']['openapi']['hostname']};
            EOT;
            file_put_contents('/etc/nginx/snippets/server/generated_openapi_name.conf', $comment . $output);
        }

        echo "[INFO] Generating nginx csp headers." . PHP_EOL;
        $csp_file = '/etc/nginx/snippets/server/generated_csp_headers.conf';
        if (file_exists($csp_file)) { unlink($csp_file); }
        $policy = CSPBuilder::fromData(json_encode($settings['nginx']['csp']));
        $policy->saveSnippet(
            $csp_file,
            CSPBuilder::FORMAT_NGINX,
            fn ($output) =>  $output = $comment.$output
        );

        echo "[INFO] Generating nginx ssl stapling headers." . PHP_EOL;
        $output = <<<EOT
        ssl_stapling {$settings['nginx']['ssl_stapling']['ssl_stapling']};
        ssl_stapling_verify {$settings['nginx']['ssl_stapling']['ssl_stapling_verify']};
        EOT;
        file_put_contents('/etc/nginx/snippets/server/generated_ssl_stapling.conf', $comment.$output);

        echo "[INFO] Generating nginx cors map." . PHP_EOL;

        $origins = $settings['nginx']['cors']['origin'];
        if (!is_array($origins)) {
            $origins = [$origins];
        }

        $output  = "map_hash_bucket_size 512;" . PHP_EOL;
        $output .= 'map $http_origin $origin_allowed {' . PHP_EOL;
        $output .= "    default 0; # Origin not allowed fallback" . PHP_EOL;
        foreach ($origins as $o) {
            $output .= "    " . $o . " 1; # Allowed origin" . PHP_EOL;
        }
        $output .= "    '' 2; # Special case for missing Origin header" . PHP_EOL;
        $output .= "}" . PHP_EOL . PHP_EOL;
        $output .= 'map $origin_allowed $origin {' . PHP_EOL;
        $output .= "    default '';" . PHP_EOL;
        $output .= '    1 $http_origin;' . PHP_EOL;
        $output .= "    2 '*';" . PHP_EOL;
        $output .= "}" . PHP_EOL;

        file_put_contents('/etc/nginx/conf.d/cors_map.conf', $comment.$output);

        echo "[INFO] Generating nginx cors headers." . PHP_EOL;
        $hdr_allow    = implode(', ', $settings['nginx']['cors']['headers.allow']);
        $hdr_expose   = implode(', ', $settings['nginx']['cors']['headers.expose']);
        $cred_allow   = $settings['nginx']['cors']['credentials'] ? 'true' : 'false';
        $max_age      = $settings['nginx']['cors']['cache'];
        $output = <<<EOT
        more_set_headers 'Access-Control-Allow-Origin: \$origin';
        more_set_headers 'Access-Control-Allow-Headers: {$hdr_allow}';
        more_set_headers 'Access-Control-Expose-Headers: {$hdr_expose}';
        more_set_headers 'Access-Control-Allow-Credentials: {$cred_allow}';
        more_set_headers 'Access-Control-Max-Age: {$max_age}';
        EOT;
        file_put_contents('/etc/nginx/snippets/server/generated_cors_headers.conf', $comment.$output);
    }

    public static function getEnv(Event $event): void
    {
        if (!getenv('GLUED_PROD')) {
            Dotenv::createImmutable(__ROOT__)->safeLoad();
            echo "[INFO] GLUED_PROD not set, loading `" . __ROOT__ . "/.env`" . PHP_EOL;
        } else {
            echo "[INFO] GLUED_PROD set, ignoring `" . __ROOT__ . "/.env`" . PHP_EOL;
        }

        // Print selected vars (avoid relying on $_ENV dump)
        print_r([
            'HOSTNAME'   => getenv('HOSTNAME') ?: '',
            'DATAPATH'   => getenv('DATAPATH') ?: '',
            'IDENTITY'   => getenv('IDENTITY') ?: '',
            'GLUED_PROD' => getenv('GLUED_PROD') ?: '',
            'MYSQL_HOSTNAME' => getenv('MYSQL_HOSTNAME') ?: '',
            'MYSQL_DATABASE' => getenv('MYSQL_DATABASE') ?: '',
        ]);
    }

    public static function configTool(Event $event): void
    {
        $composer = $event->getComposer();
        echo "[NOTE] STARTING THE CONFIGURATION TESTING AND SETUP TOOL" . PHP_EOL . PHP_EOL;

        if (!getenv('GLUED_PROD')) {
            Dotenv::createImmutable(__ROOT__)->safeLoad();
            echo "[INFO] GLUED_PROD not set, loading `" . __ROOT__ . "/.env`" . PHP_EOL;
        } else {
            echo "[INFO] GLUED_PROD set, ignoring `" . __ROOT__ . "/.env`" . PHP_EOL;
        }

        if (!getenv('DATAPATH')) { die('[FAIL] DATAPATH env variable not set' . PHP_EOL . PHP_EOL); }
        if (!getenv('IDENTITY')) { die('[FAIL] IDENTITY env variable not set' . PHP_EOL . PHP_EOL); }

        $paths   = [];
        $paths[] = getenv('DATAPATH') . '/' . basename(__ROOT__) . '/cache/psr16';
        $paths[] = getenv('DATAPATH') . '/' . basename(__ROOT__) . '/cache/geoip';

        // Writable paths
        echo "[INFO] Ensuring paths exist and are writable" . PHP_EOL;
        $oldumask = umask(0);
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                echo "[WARN] $path not found. Attempting to create ... ";
                if (!mkdir($path, 0777, true)) {
                    die('failed.' . PHP_EOL . '[FAIL] Failed to create directories.' . PHP_EOL . PHP_EOL);
                }
                echo "ok." . PHP_EOL;
            }
        }

        $openApiFile = getenv('DATAPATH') . "/" . basename(__ROOT__) . "/cache/openapi.yaml";
        $routesFile  = getenv('DATAPATH') . "/" . basename(__ROOT__) . "/cache/routes.yaml";
        echo "[INFO] (re)building service routes cache from OpenAPI" . PHP_EOL;
        echo ">>>>>> OpenAPI source: " . $openApiFile . PHP_EOL;
        echo ">>>>>> Routes target: " . $routesFile . PHP_EOL;

        try {
            self::openApiToRoutes($openApiFile, $routesFile);
        } catch (\Exception $e) {
            echo "[FAIL] building {$routesFile}" . PHP_EOL;
            print_r($e);
        }
        echo "[PASS] routes rebuilt." . PHP_EOL;

        // MYSQL
        echo "[INFO] Ensuring MySQL connection works fine" . PHP_EOL;
        $link = mysqli_connect(
            getenv('MYSQL_HOSTNAME') ?: '',
            getenv('MYSQL_USERNAME') ?: '',
            getenv('MYSQL_PASSWORD') ?: '',
            getenv('MYSQL_DATABASE') ?: ''
        );
        if (!$link) {
            echo "[FAIL] Unable to connect to MySQL." . PHP_EOL;
            echo "[FAIL] Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "[FAIL] Debugging error: " . mysqli_connect_error() . PHP_EOL;
            die();
        }
        echo "[PASS] MySQL connection OK to " . mysqli_get_host_info($link) . PHP_EOL;
        mysqli_close($link);

        // Geolite2
        echo "[INFO] Setting up geoip" . PHP_EOL;
        $geoipKey = getenv('GEOIP');
        if ($geoipKey !== false && $geoipKey !== '') {
            echo "[INFO] Getting geolite2 database ..." . PHP_EOL;
            $data_uri = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=' . $geoipKey . '&suffix=tar.gz';
            $hash_uri = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=' . $geoipKey . '&suffix=tar.gz.sha256';
            $data_file = getenv('DATAPATH') . '/' . basename(__ROOT__) . '/cache/geoip/maxmind-geolite2-city.mmdb.tar.gz';
            $hash_file = $data_file . '.sha256';
            $hash_dist = '';

            if (file_exists($data_file) && (time() - filemtime($data_file) < 72000) && (filesize($data_file) > 4096)) {
                echo "[INFO] Skipping download, a recent copy of the database is already present." . PHP_EOL;
            } else {
                $stream = @fopen($data_uri, 'r');
                if ($stream) {
                    file_put_contents($data_file, $stream);
                    file_put_contents($data_file . '.sha256', @fopen($hash_uri, 'r'));
                } else {
                    echo "[FAIL] Download failed. Did you provide a correct geolite2 license key? Does your connection work?" . PHP_EOL;
                    die();
                }

                if (file_exists($hash_file)) {
                    $hash_dist = explode(" ", file_get_contents($hash_file), 2)[0];
                }
                if (file_exists($data_file)) {
                    $hash_file = hash_file('sha256', $data_file);
                }
                if (!(($hash_dist == $hash_file) && ($hash_file != ''))) {
                    echo "[FAIL] Hash verification failed. Is your internet connection secure?" . PHP_EOL;
                    die();
                }

                $phar = new PharData($data_file);
                $pattern = '.mmdb';
                foreach ($phar as $item) {
                    if ($item->isDir()) {
                        $dir = new PharData($item->getPathname());
                        foreach ($dir as $child) {
                            if (strpos(basename((string)$child), $pattern) !== false) {
                                $relpath = str_replace(basename($data_file) . '/', '', strstr((string)$child, basename($data_file)));
                                $basedir = getenv('DATAPATH') . '/' . basename(__ROOT__) . '/cache/geoip/';
                                $phar->extractTo($basedir, $relpath, true);
                                copy($basedir . $relpath, $basedir . str_replace('.tar.gz', '', basename($data_file)));
                                unlink($basedir . $relpath);
                                @rmdir(dirname($basedir . $relpath));
                            }
                        }
                    }
                }
            }
            echo "[PASS] Geoip configured." . PHP_EOL;
        } else {
            echo "[WARN] No geolite2 license key provided (use GEOIP env variable)." . PHP_EOL;
            echo "[WARN] Glued will probably run. But you should signup at https://www.maxmind.com/en/geolite2/signup" . PHP_EOL;
            echo "[WARN] login to your account, click on `My license key` and `Generate a new license key`" . PHP_EOL;
        }
        umask($oldumask);
    }
}
