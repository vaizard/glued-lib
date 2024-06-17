<?php
declare(strict_types=1);
namespace Glued\Lib\Controllers;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class AbstractIf extends AbstractService
{


    /**
     * @var \Glued\Lib\Sql
     */
    protected $deployment;


    /**
     * @var array
     */
    protected $q;


    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->deployment = new \Glued\Lib\Sql($this->pg, 'if__deployments');
        $this->q = [];
    }


    protected function getInterface($deploymentUUID, $interfaceConnector)
    {
        try {
            $d = $this->deployment->get($deploymentUUID);
            foreach ($d['interfaces'] as $interface) {
                if ($interface['connector'] === $interfaceConnector) {
                    return $interface;
                }
            };
        } catch (\Exception $e) { throw new \Exception("Deployment {$deploymentUUID} not found or data source gone. Available deployments are listed at {$this->settings["glued"]["baseuri"]}{$this->settings["routes"]["be_if_deployments"]["pattern"]}?service=s4s", 500); }
        throw new \Exception("Bad interface configuration / interface connector missing in {$deploymentUUID}",500);
    }


    public function methods(Request $request, Response $response, array $args = []): Response 
    {
        $actions = new \Glued\Lib\Sql($this->pg, 'if__actions');
        $service = explode("/", (string) $request->getUri()->getPath())[4];
        $filteredRoutes = array_filter($this->settings["routes"], function ($route) use ($service) {
            return isset($route["service"]) && strpos($route["service"], "if/{$service}") === 0;
        });
        foreach ($filteredRoutes as $r) {
            foreach ($r['methods'] as $method=>$call) {
                $action = [
                    'deployment' => $args['deployment'],
                    'method' => $method,
                    'path' => str_replace('{deployment}', $args['deployment'], $r['pattern'])
                ];
                $actions->create($action, true);
            }
            $res[] = [
                'label' => $r['label'],
                'dscr' => $r['dscr'],
                'uri' => $this->settings['glued']['baseuri'].str_replace('{deployment}', $args['deployment'], $r['pattern'])
            ];
        }
        return $response->withJson($res);
    }

}
