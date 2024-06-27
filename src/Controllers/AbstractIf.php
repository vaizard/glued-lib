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
     * @param string $resId The response ID (leave empty if none provided).
     * @param string $resPayload The response payload in JSON format (leave empty if none provided).
     *
     * @return mixed Returns the UUID of the `action_uuid`, `req_payload`, `req_params`, `res_payload` combination.
     *
     * @throws \PDOException If there is a database error.
     */
    public function cacheValidActionsResponse(string $actionUUID, string $reqParams = "", string $reqPayload = "", string $fid = ""): string
    {
        $sql = "INSERT INTO {$this->deployments->schema}.if__actions_valid_response_cache 
            (action_uuid, req_payload, req_params, res_payload, res_id) 
            VALUES 
            (:actionUUID, :reqPayload, :reqParams, :resPayload, :resId) 
            ON CONFLICT (nonce) DO UPDATE SET 
                req_at = CURRENT_TIMESTAMP,
                res_replays = if__actions_valid_response_cache.res_replays + 1 
            WHERE if__actions_valid_response_cache.nonce = EXCLUDED.nonce
            RETURNING uuid";
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



}
