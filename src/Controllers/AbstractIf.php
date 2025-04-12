<?php
declare(strict_types=1);
namespace Glued\Lib\Controllers;

use Selective\Transformer\ArrayTransformer;
use Slim\Routing\RouteContext;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class AbstractIf extends AbstractService
{
    /**
     * @var \Glued\Lib\Sql
     */
    protected $deployments;

    /**
     * @var array
     */
    protected array $deployment;


    /**
     * @var array of ssh tunnelling ports to close
     */
    public array $closePorts;


    /**
     * Constructor, initializes the class with a given container, setting up the deployments
     * SQL handler and initializing the deployment and closePorts array.
     *
     * @param ContainerInterface $container The container instance for dependency injection.
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->deployments = new \Glued\Lib\Sql($this->pg, 'if__deployments');
        $this->deployment = [];
        $this->closePorts = [];

    }


    public function getDeployments(Request $request, Response $response, array $args = []): Response
    {
        $path = explode('/', trim($request->getUri()->getPath(), '/'));
        if (implode('/', array_slice($path, 0, 3)) !== 'api/if/svc' || !isset($path[3])) {
            throw new \Exception('Invalid path');
        }
        $service =  $path[3];
        $this->deployments->where('service', '=', $service);
        $this->deployments->selectModifier = "jsonb_build_object('uri', concat('{$this->settings['glued']['baseuri']}{$this->settings['routes']['be_if']['pattern']}svc/', doc->>'service', '/v1/', doc->>'uuid')) || ";
        $data = $this->deployments->getAll();
        $xfi = new ArrayTransformer();
        $xfi->map('uri', 'uri', 'required')
            ->map('uuid', 'uuid', 'required')
            ->map('name', 'name', 'required')
            ->map('description', 'description');
        $data = $xfi->toArrays($data);
        return $response->withJson($data);
    }

    /**
     * Retrieves the deployment details for a given deployment UUID.
     *
     * @param string $deploymentUUID The UUID of the deployment to retrieve.
     * @return mixed The deployment details as an associative array if found, false otherwise.
     */
    protected function getDeployment($deploymentUUID): mixed
    {
        $query = <<<EOT
            SELECT 
                jsonb_build_object(
                    'nonce', id.nonce,
                    'created_at', id.created_at,
                    'updated_at', id.updated_at
                ) || 
                id.{$this->deployments->dataColumn} AS doc
            FROM glued.if__deployments id 
            WHERE id.uuid = :uuid;
        EOT;
        $stmt = $this->deployments->pdo->prepare($query);
        $stmt->bindParam(':uuid', $deploymentUUID);
        $stmt->execute();
        $res = $stmt->fetchColumn();
        if ($res) { return json_decode($res, true); }
        return false;
    }


    /**
     * Retrieves the specific interface configuration for a given deployment and interface connector.
     *
     * @param string $deploymentUUID The UUID of the deployment.
     * @param string $interfaceConnector The interface connector identifier.
     * @return array The interface configuration if found.
     * @throws \Exception If the deployment or interface configuration is not found.
     */
    protected function getInterface($deploymentUUID, $interfaceConnector)
    {
        try {
            $d = $this->deployments->get($deploymentUUID);
            foreach ($d['interfaces'] as $interface) {
                if ($interface['connector'] === $interfaceConnector) {
                    return $interface;
                }
            };
        } catch (\Exception $e) { throw new \Exception("Deployment {$deploymentUUID} not found or data source gone. Available deployments are listed at {$this->settings["glued"]["baseuri"]}{$this->settings["routes"]["be_if_deployments"]["pattern"]}?service=s4s", 500); }
        throw new \Exception("Bad interface configuration / interface connector missing in {$deploymentUUID}",500);
    }

    /**
     * Returns a list of endpoints within a deployments.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The request arguments.
     * @return Response The response object with JSON-encoded result.
     */
    public function methods(Request $request, Response $response, array $args = []): Response
    {
        $service = explode("/", (string) $request->getUri()->getPath())[4];
        $filteredRoutes = array_filter($this->settings["routes"], function ($route) use ($service) {
            return isset($route["service"]) && strpos($route["service"], "if/{$service}") === 0;
        });
        foreach ($filteredRoutes as $r) {
            $res[] = [
                'label' => $r['label'],
                'dscr' => $r['dscr'],
                'uri' => $this->settings['glued']['baseuri'].str_replace('{deployment}', $args['deployment'], $r['pattern']),
            ];
        }
        return $response->withJson($res);
    }

    public function __destruct()
    {
        foreach ($this->closePorts as $port) {
            $this->terminateProcessOnPort($port);
        }
    }

    public function getAvailablePort($startPort, $endPort) {
        for ($port = $startPort; $port <= $endPort; $port++) {
            if (!$this->isPortInUse($port)) {
                return $port;
            }
        }
        return false;
    }

    public function isPortInUse($port) {
        $connection = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    public function terminateProcessOnPort($port)
    {
        // Implementation to terminate the process on the given port
        // This will vary depending on your operating system and setup

        // Example for Unix-based systems:
        $command = "lsof -i tcp:$port | grep LISTEN | awk '{print $2}' | xargs kill -9";
        exec($command);
    }



    public function getSshTunnels(string $proxyHost, int $proxyPort, string $proxyUser, string $proxyPass, string $targetHost = '127.0.0.1', int $targetPort = 3306)
    {
        // List SSH tunnels and their details
        $command = sprintf(
            'bash -c \'lsof -P -n -c ssh -a -F p | while read -r pid; do ps -p ${pid:1} -o pid,args= | grep %s | grep %s ; done\'',
            escapeshellarg("{$targetHost}:{$targetPort}"),
            escapeshellarg($proxyHost)
        );
        exec($command, $output);
        $tunnels = [];
        foreach ($output as $line) {
            if (preg_match('/^\s*(\d+)\s+(.*)/', $line, $matches)) {
                if (preg_match('/-L\s+(\d+):' . preg_quote($targetHost, '/') . ':' . $targetPort . '/', $matches[2], $portMatch)) {
                    $tunnels[] = [
                        'port' => $portMatch[1], // Local port extracted from args
                        'pid' => $matches[1],    // PID
                        'args' => $matches[2],   // Full command arguments
                    ];
                }
            }
        }
        return $tunnels;
    }

    public function createSshTunnel(string $proxyHost, int $proxyPort, string $proxyUser, string $proxyPass, string $targetHost = '127.0.0.1', int $targetPort = 3306, $localPortMin = 49152, $localPortMax = 49352 )
    {

        $availablePort = $this->getAvailablePort($localPortMin, $localPortMax);
        ini_set("default_socket_timeout", 2);
        $command = sprintf(
            'sshpass -p %s ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=2 -o ServerAliveInterval=1 -f -N -L %d:%s:%d %s@%s -p %d',
            escapeshellarg($proxyPass),
            (int) $availablePort,
            escapeshellarg($targetHost),
            (int) $targetPort,
            escapeshellarg($proxyUser),
            escapeshellarg($proxyHost),
            (int) $proxyPort
        );
        exec($command, $output, $return_var);
        if ($return_var !== 0) { throw new \Exception("Failed to create SSH tunnel. Return code: $return_var", 500); }
        $this->closePorts[] = $availablePort;
        return $availablePort;
    }


}
