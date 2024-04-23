<?php
declare(strict_types=1);
namespace Glued\Lib;
/**
 * IfUtils class provides unified query strings for If plugins and the If controller itself.
 */
class IfUtils
{
    protected $pg;
    protected $settings;
    public function __construct($pg, $settings) {
        $this->pg = $pg;
        $this->settings = $settings;
    }

    public function getDeploymentsJson($service = false, $uuid = false): string {
        $base = $this->settings['glued']['baseuri'] . $this->settings['routes']['be_if']['path'] . '/svc/';
        $q = "SELECT json_agg(json_build_object('uuid', uuid, 'conn', conn, 'meta', meta, 'uri', concat('{$base}',service,'/latest/',uuid))) AS deployments FROM if__deployments WHERE 1=1 ";
        if ($service) { $q .= " AND service = :service "; }
        if ($uuid) { $q .= " AND uuid = :uuid "; }
        $stmt = $this->pg->prepare($q);
        if ($service) { $stmt->bindValue(':service', $service, \PDO::PARAM_STR); }
        if ($uuid) { $stmt->bindValue(':uuid', $uuid, \PDO::PARAM_STR); }
        $stmt->execute();
        $res = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $res['deployments'] ?? '{}';
    }

    public function getDeploymentsArr($service = false, $uuid = false): array {
        return json_decode($this->getDeploymentsJson($service, $uuid));
    }





}