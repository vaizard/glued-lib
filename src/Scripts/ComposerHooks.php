<?php
declare(strict_types=1);
namespace Glued\Lib\Scripts;
use Composer\Script\Event;
use Dotenv\Dotenv;
use Glued\Lib\Crypto;


class ComposerHooks
{
    public static function preInstall(Event $event) {
        echo "++++++ INSTALLING GLUED";
        exit;
    }

    public static function postPackageInstall(Event $event) {
        $installedPackage = $event->getComposer()->getPackage();
        echo "++++++ GLUED INSTALLED";
        // any tasks to run after the package is installed
    }

    public static function configTool(Event $event) {
        echo "++++++ STARTING THE COFIGURATION TESTING AND SETUP TOOL" . PHP_EOL . PHP_EOL;
        $composer = $event->getComposer();
        $crypto = new Crypto;
        define("__ROOT__", getcwd());

        // Load .env file, don't override existing $_ENV values
        // If GLUED_PROD = 1, rely purely on $_ENV and don't load
        // the .env file (which is intended only for development)
        // to improve performance.
        if (!isset($_ENV['GLUED_PROD'])) {
            echo "loading dotenv";
            $dotenv = Dotenv::createImmutable(__ROOT__, '.env');
            $dotenv->Load();
        }

        (!isset($_ENV['datapath'])) && die('[FAIL] datapath env variable not set' . PHP_EOL . PHP_EOL);

        $paths[] = $_ENV['datapath'].'/'.basename(__ROOT__).'/cache/psr16';
        echo "++++++ Ensure paths exist and are writable" . PHP_EOL;

        $oldumask = umask(0);
        foreach ($paths as $path) {
          if (!is_dir($path)) {
            echo "[WARN] '.$path.' not found. Attempting to create ... ";
              if (!mkdir($path, 0777, true)) {
                  die('failed'. PHP_EOL .'[FAIL] Failed to create directories.' . PHP_EOL . PHP_EOL);
              }
              //chmod($path, 0777);
            echo "ok" . PHP_EOL;
          }
        }
        echo "[PASS]" . PHP_EOL . PHP_EOL;
	umask($oldumask);

    }
        
    

}
