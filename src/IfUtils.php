<?php
declare(strict_types=1);
namespace Glued\Lib;
/**
 * IfUtils class provides unified query strings for If plugins and the If controller itself.
 */
class IfUtils
{
    protected $db;
    public function __construct($db) {
        $this->db = $db;
    }

    public function logRequest($act): void
    {
        $q = "
            INSERT INTO `t_if__logs` 
                (`c_act_uuid`, `c_uuid`, `c_data`, `c_ts_requested`, `c_ts_responded`, `c_ok`, `c_response_hash`, `c_response_fid`)
                VALUES 
                (uuid_to_bin(?, 1), uuid_to_bin(?, 1), '{}', now(), NULL, NULL, NULL, NULL);
            ";
        $this->db->rawQuery($q, [$act_uuid, $run_uuid]);
    }

    public function logResponse($act, $json = "{}", $ok = 0, $response_hash = '', $response_fid = ''): void
    {

        $q = "
            UPDATE `t_if__logs` SET
                `c_data` = ?,
                `c_ts_responded` = now(),
                `c_ok` = ?,
                `c_response_hash` = ?,
                `c_response_fid` = ?
            WHERE `c_uuid` = uuid_to_bin(?, 1);
            ";
        $this->db->rawQuery($q, [$json, $ok, $response_hash, $response_fid, $run_uuid]);
    }


}