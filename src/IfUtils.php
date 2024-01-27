<?php
declare(strict_types=1);
namespace Glued\Lib;
/**
 * IfUtils class provides unified query strings for If plugins and the If controller itself.
 */
class IfUtils
{
    protected $mysqli;
    public function __construct($mysqli) {
        $this->mysqli= $mysqli;
    }

    public function logRequest($act_uuid): string
    {
        $run_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $q = "
            INSERT INTO `t_if__runs` 
                (`c_act_uuid`, `c_uuid`, `c_data`, `c_ts_requested`, `c_ts_responded`, `c_status`, `c_response_hash`, `c_response_fid`)
                VALUES 
                (uuid_to_bin(?, 1), uuid_to_bin(?, 1), '{}', now(), NULL, 'started', NULL, NULL);
            ";
        $this->mysqli->execute_query($q, [$act_uuid, $run_uuid]);
        return $run_uuid;
    }

    public function logCheck($act_uuid, $response_hash = null, $response_fid = null): mixed
    {
        $qp = null;
        $qs = "
            SELECT 
                bin_to_uuid(`c_act_uuid`) AS `c_act_uuid`,
                bin_to_uuid(`c_uuid`) AS `c_uuid`,
                c_data,
                c_ts_requested,
                c_ts_responded,
                c_status,
                c_response_hash,
                c_response_fid
            FROM `t_if__runs`
            ";
        $qs = (new \Glued\Lib\QueryBuilder())->select($qs);
        if (!is_null($response_hash)) { $qs->where("c_response_hash = ?"); $qp[] = $response_hash; }
        if (!is_null($response_fid)) { $qs->where("c_response_fid = ?"); $qp[] = $response_fid; }
        $res = $this->mysqli->execute_query((string) $qs, $qp);
        foreach ($res as $i) { return $i ?? []; }
        return [];
    }


    public function logResponse($run_uuid, $status, $data = "{}", $response_hash = '', $response_fid = ''): void
    {
        if (array_key_exists($status, ['failed', 'ok', 'started', 'skipped'])) throw new \Exception('Bad run status.');
        $q = "
            UPDATE `t_if__runs` SET
                `c_data` = ?,
                `c_ts_responded` = now(),
                `c_status` = ?,
                `c_response_hash` = ?,
                `c_response_fid` = ?
            WHERE `c_uuid` = uuid_to_bin(?, 1);
            ";
        $this->mysqli->execute_query($q, [$data, $status, $response_hash, $response_fid, $run_uuid]);

    }


    public function getAction(mixed $action): array
    {
        $action = $args['uuid'] ?? false;
        if (!$action) {
            throw new \Exception('Action missing', 400);
        }
        $qs = "SELECT * FROM (" . ((new \Glued\Lib\IfSql())->q['rows:runs:joined']) . ") subquery";
        $qs .= " WHERE subquery.act_uuid = ? and (subquery.row_num = 1 or subquery.row_num is NULL)";
        $res = $this->mysqli->execute_query($qs, [$action]);
        foreach ($res as $i) { return $i ?? []; }
        return [];
    }


}