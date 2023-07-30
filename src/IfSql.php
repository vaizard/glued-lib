<?php
declare(strict_types=1);
namespace Glued\Lib;
/**
 * IfUtils class provides unified query strings for If plugins and the If controller itself.
 */
class IfSql
{
    public $q;
    public function __construct()
    {
        $q['logs'] = "
        SELECT * FROM (
            SELECT 
               BIN_TO_UUID(`c_uuid`,1) AS `uuid`,
               BIN_TO_UUID(`c_act_uuid`,1) AS `act_uuid`,
               c_ts_requested as `ts_requested`,
               c_ts_responded as `ts_responded`,
               c_data as `data`,
               TIMESTAMPDIFF(SECOND, c_ts_requested, c_ts_responded) AS duration,
               c_ok AS ok,
               c_response_hash as response_hash,
               c_response_fid as response_fid,
               TIMESTAMPDIFF(SECOND, c_ts_requested, NOW()) AS requested_ago
            FROM `t_if__logs`
        ) q
        ";

        $q['queue-private'] = "
        SELECT
          svc_uuid,
          svc_data,
          svc_type,
          svc_name,
          act_uuid,
          act_type,
          act_freq,
          log_req,
          log_res,
          log_duration,
          log_ok,
          log_hash,
          log_fid,
          log_ago,
          next_in,
          hash_count
        FROM (
          SELECT
            bin_to_uuid(svc.c_uuid, true) AS svc_uuid,
            svc.c_data AS svc_data,
            svc.c_type AS svc_type,
            svc.c_name AS svc_name,
            bin_to_uuid(act.c_uuid, true) AS act_uuid,
            act.c_type AS act_type,
            act.c_freq AS act_freq,
            bin_to_uuid(log.c_uuid, true) as log_uuid,
            IFNULL(log.c_ts_requested, 0) AS log_req,
            IFNULL(log.c_ts_responded, 0) AS log_res,
            TIMESTAMPDIFF(SECOND, log.c_ts_requested, c_ts_responded) AS log_duration,
            log.c_ok AS log_ok,
            log.c_response_hash AS log_hash,
            log.c_response_fid AS log_fid,
            TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW()) AS log_ago,
            ifnull(act.c_freq - TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW()),-1) AS next_in,
            (SELECT COUNT(*) FROM t_if__logs WHERE c_response_hash = log.c_response_hash) AS hash_count,
            ROW_NUMBER() OVER (PARTITION BY act.c_uuid ORDER BY TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW())) AS row_num
          FROM t_if__services svc
          LEFT JOIN t_if__actions act ON svc.c_uuid = act.c_svc_uuid
          LEFT JOIN t_if__logs log ON act.c_uuid = log.c_act_uuid
          ORDER BY log_ago ASC, log_duration ASC
        ) subquery";
        $q['queue'] = "
        SELECT
          svc_uuid,
          svc_type,
          svc_name,
          act_uuid,
          act_type,
          act_freq,
          log_req,
          log_res,
          log_duration,
          log_ok,
          log_hash,
          log_fid,
          log_ago,
          next_in,
          hash_count
        FROM (
          SELECT
            bin_to_uuid(svc.c_uuid, true) AS svc_uuid,
            svc.c_data AS svc_data,
            svc.c_type AS svc_type,
            svc.c_name AS svc_name,
            bin_to_uuid(act.c_uuid, true) AS act_uuid,
            act.c_type AS act_type,
            act.c_freq AS act_freq,
            bin_to_uuid(log.c_uuid, true) as log_uuid,
            IFNULL(log.c_ts_requested, 0) AS log_req,
            IFNULL(log.c_ts_responded, 0) AS log_res,
            TIMESTAMPDIFF(SECOND, log.c_ts_requested, c_ts_responded) AS log_duration,
            log.c_ok AS log_ok,
            log.c_response_hash AS log_hash,
            log.c_response_fid AS log_fid,
            TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW()) AS log_ago,
            ifnull(act.c_freq - TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW()),-1) AS next_in,
            (SELECT COUNT(*) FROM t_if__logs WHERE c_response_hash = log.c_response_hash) AS hash_count,
            ROW_NUMBER() OVER (PARTITION BY act.c_uuid ORDER BY TIMESTAMPDIFF(SECOND, log.c_ts_requested, NOW())) AS row_num
          FROM t_if__services svc
          LEFT JOIN t_if__actions act ON svc.c_uuid = act.c_svc_uuid
          LEFT JOIN t_if__logs log ON act.c_uuid = log.c_act_uuid
          ORDER BY log_ago ASC, log_duration ASC
        ) subquery";
        $this->q = $q;
    }

}