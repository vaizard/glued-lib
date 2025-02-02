<?php
declare(strict_types=1);
namespace Glued\Lib\Controllers;

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
     * @var \Glued\Lib\Sql
     */
    protected $actions;

    /**
     * @var array of ssh tunnelling ports to close
     */
    public array $closePorts;


    /**
     * Constructor, initializes the class with a given container, setting up the deployments
     * and actions SQL handlers and initializing the deployment array.
     *
     * @param ContainerInterface $container The container instance for dependency injection.
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
        $this->deployments = new \Glued\Lib\Sql($this->pg, 'if__deployments');
        $this->actions = new \Glued\Lib\Sql($this->pg, 'if__actions');
        $this->deployment = [];
        $this->closePorts = [];

    }


    protected function getDeployments($request, $response): mixed
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        //$this->deployments->where('service', '=', $qp['service']);
        //$this->deployments->selectModifier = "jsonb_build_object('uri', concat('{$this->settings['glued']['baseuri']}{$this->settings['routes']['be_if']['pattern']}svc/', doc->>'service', '/v1/', doc->>'uuid'), 'nonce', nonce, 'created_at', created_at, 'updated_at', updated_at) || ";
        //$data = $this->deployments->getAll();
        $data = [ $route ];
        return $response->withJson($data);
    }

/**
     * Retrieves the deployment details including actions for a given deployment UUID.
     *
     * @param string $deploymentUUID The UUID of the deployment to retrieve.
     * @return mixed The deployment details as an associative array if found, false otherwise.
     */
    protected function getDeployment($deploymentUUID): mixed
    {
        $query = <<<EOT
        SELECT 
            jsonb_build_object('nonce', id.nonce, 'created_at', id.created_at, 'updated_at', id.updated_at) || 
            id.{$this->deployments->dataColumn} || 
            jsonb_build_object('actions', COALESCE(jsonb_agg(ia.doc - 'deployment') FILTER (WHERE ia.doc IS NOT NULL), '[]'::jsonb))
            AS doc
        FROM glued.if__deployments id 
        LEFT JOIN glued.if__actions ia ON id.uuid = ia.deployment 
        WHERE id.uuid = :uuid
        GROUP BY id.doc, id.nonce, id.created_at, id.updated_at;
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
     * Retrieves or creates the `action` UUID of a given { deployment UUID, request path, and request method }
     * combination.
     *
     * @param string $deploymentUUID The UUID of the deployment.
     * @param string $requestPath The request path.
     * @param string $requestMethod The request method.
     * @return mixed The action UUID if found or created, or null otherwise.
     */
    public function getActionUUID($deploymentUUID, $requestPath, $requestMethod): mixed
    {
        if (empty($this->deployment)) { $this->getDeployment($deploymentUUID); }
        $uuid = array_column(array_filter($array['actions'] ?? [], fn($action) => $action['path'] === $path && $action['method'] === $method), 'uuid')[0] ?? null;
        if (empty($uuid)) {
            $action = [
                'deployment' => $deploymentUUID,
                'method' => $requestMethod,
                'path' => $requestPath
            ];
            $uuid = $this->actions->create($action, true, true);
        }
        return $uuid;
    }

    /**
     * Cache valid action responses
     *
     * This method inserts a new record into the `if__actions_valid_response_cache` table. If a record with the same
     * nonce already exists, it updates the `req_at` timestamp and increments the `res_replays` counter.
     *
     * The `nonce` is a MD5 hash of the concatenation of `action_uuid`, `req_payload`, `req_params`, `res_payload`
     * and `res_id`.
     *
     * @param string $actionUUID The UUID of the action.
     * @param string $reqParams The request parameters as a string (leave empty if none provided).
     * @param string $reqPayload The request payload as a JSON formatted string (leave empty if none provided).
     * @param string $resPayload The response payload in JSON format (leave empty if none provided).
     * @param string $fid The foreign ID of the request (leave empty if none provided).
     *
     * @return mixed Returns the UUID of the `action_uuid`, `req_payload`, `req_params`, `res_payload` combination.
     *
     * @throws \PDOException If there is a database error.
     */
    public function cacheValidActionsResponse(string $actionUUID, array $reqParams = [], array $reqPayload = [], array $resPayload = [], string $fid = ""): string
    {
        $reqParams = json_encode($reqParams);
        $reqPayload = json_encode($reqPayload);
        $resPayload = json_encode($resPayload);
        $sql = <<<EOL
        INSERT INTO {$this->deployments->schema}.if__actions_valid_response_cache 
        (action_uuid, req_payload, req_params, res_payload, fid) 
        VALUES 
        (:actionUUID, :reqPayload::jsonb, :reqParams::jsonb, :resPayload::jsonb, :fid) 
        ON CONFLICT (nonce) DO UPDATE SET                           
            req_at = CURRENT_TIMESTAMP,
            res_replays = if__actions_valid_response_cache.res_replays + 1 
        RETURNING encode(nonce,'hex')
        EOL;
        $stmt = $this->deployments->pdo->prepare($sql);
        $stmt->bindParam(':actionUUID', $actionUUID);
        $stmt->bindParam(':reqPayload', $reqPayload);
        $stmt->bindParam(':reqParams', $reqParams);
        $stmt->bindParam(':resPayload', $resPayload);
        $stmt->bindParam(':fid', $fid);
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    /**
     * Returns a list of endpoints within a deployments and creates/updates actions (the deployment, request path
     * and request method triplets stored in the if__actions table).
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The request arguments.
     * @return Response The response object with JSON-encoded result.
     */
    public function methods(Request $request, Response $response, array $args = []): Response
    {
        $actionUUID = $this->getActionUUID($args['deployment'], (string) $request->getUri()->getPath(), (string) $request->getMethod());
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
                $this->actions->create($action, true, true);
            }
            $res[] = [
                'label' => $r['label'],
                'dscr' => $r['dscr'],
                'uri' => $this->settings['glued']['baseuri'].str_replace('{deployment}', $args['deployment'], $r['pattern']),
                'uuid' => $actionUUID
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
