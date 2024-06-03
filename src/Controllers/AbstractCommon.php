<?php
declare(strict_types=1);
namespace Glued\Lib\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class AbstractCommon
{
    /**
     * @var ContainerInterface
     */
    protected $c;


    /**
     * AbstractController constructor. We're passing the whole container to the constructor to be
     * able to do stuff like $this->c->db->method(). This is considered bad pracise that makes
     * the whole app more memory hungry / less efficient. Dependency injection should be rewritten
     * to take advantage of PHP-DI's autowiring.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->c = $container;
    }


    /**
     * __get is a magic method that allows us to always get the correct property out of the 
     * container, allowing to write $this->db->method() instead of $this->c->db->method()
     * @param  string $property Container property
     */
    public function __get($property)
    {
        if ($this->c->get($property)) {
            return $this->c->get($property);
        }
    }


    public function getOpenapi(Request $request, Response $response, array $args = []): Response
    {
        // Directory to look for paths
        $path = "{$this->settings['glued']['datapath']}/{$this->settings['glued']['uservice']}/cache" ;
        $filesWhitelist = ["openapi.json", "openapi.yaml", "openapi.yml"]; // Potential file names

        foreach ($filesWhitelist as $file) {
            $fullPath = rtrim($path, '/') . '/' . $file;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                $response->getBody()->write($content);
                $contentType = 'application/json';
                if (pathinfo($fullPath, PATHINFO_EXTENSION) === 'yaml' || pathinfo($fullPath, PATHINFO_EXTENSION) === 'yml') { $contentType = 'application/x-yaml'; }
                return $response->withHeader('Content-Type', $contentType);
            }
        }
        throw new \Exception("OpenAPI specification not found", 404);
    }


    public function getHealth(Request $request, Response $response, array $args = []): Response {
        try {
            $check['service'] = basename(__ROOT__);
            $check['timestamp'] = microtime();
            $check['healthy'] = true;
            $check['status']['postgres'] = $this->pg->query("select true as test")->fetch()['test'] ?? false;
            $check['status']['auth'] = $_SERVER['X-GLUED-AUTH-UUID'] ?? 'anonymous';
        } catch (Exception $e) {
            $check['healthy'] = false;
            return $response->withJson($check);
        }
        return $response->withJson($check);
    }

}
