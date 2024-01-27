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

        $q['rows:runs:simple'] = "
            SELECT 
               BIN_TO_UUID(`c_uuid`,1) AS `uuid`,
               BIN_TO_UUID(`c_action_uuid`,1) AS `act_uuid`,
               c_ts_start as `ts_started`,
               c_ts_finish as `ts_finished`,
               c_data as `data`,
               c_status AS status,
               c_hash as response_hash,
               c_fid as response_fid,
               TIMESTAMPDIFF(SECOND, c_ts_start, c_ts_finish) AS run_time,
               TIMESTAMPDIFF(SECOND, c_ts_finish, NOW()) AS run_ago
            FROM `t_if__runs`
        ";

        $q['rows:runs:joined'] = "
            SELECT
                bin_to_uuid(svc.c_uuid, true) AS deployment_uuid,
                svc.c_data AS deployment_data,
                svc.c_service AS service,
                svc.c_name AS deployment_name,
                svc.c_remote AS deployment_host,
                bin_to_uuid(act.c_uuid, true) AS act_uuid,
                act.c_scheme AS act_scheme,
                act.c_freq AS act_freq,
                bin_to_uuid(run.c_uuid, true) as run_uuid,
                IFNULL(run.c_ts_start, 0) AS run_req,
                IFNULL(run.c_ts_finish, 0) AS run_res,
                run.c_status AS run_ok,
                run.c_hash AS run_hash,
                run.c_fid AS run_fid,
                TIMESTAMPDIFF(SECOND, run.c_ts_finish, NOW()) AS run_ago,
                IFNULL(act.c_freq - TIMESTAMPDIFF(SECOND, run.c_ts_finish, NOW()), -1) AS run_in,
                TIMESTAMPDIFF(SECOND, c_ts_start, c_ts_finish) AS run_time,
                (SELECT COUNT(*) FROM t_if__runs WHERE c_hash = run.c_hash) AS run_hash_count,
                ROW_NUMBER() OVER (PARTITION BY COALESCE(act.c_uuid, UUID()) ORDER BY TIMESTAMPDIFF(SECOND, run.c_ts_finish, NOW())) AS row_num
                -- Row_num is = 1 is used to select the latest run of an action.
                -- Row_num is = null ensures we don't ge a null on actions that never had a run
                -- COALESCE(act.c_uuid, UUID()) ensures listing in case service deployment doesn't have an action stored
            FROM t_if__deployments svc
            LEFT JOIN t_if__actions act ON svc.c_uuid = act.c_deployment_uuid
            LEFT JOIN t_if__runs run ON act.c_uuid = run.c_action_uuid
            ORDER BY run_ago ASC, run_duration ASC

        ";

        $q['json:runs:all'] = "
        SELECT COALESCE(
            JSON_ARRAYAGG(
                JSON_OBJECT(
                    'service', service,
                    -- 'deployment', deployment_data,
                    'deployment', JSON_MERGE_PRESERVE(deployment_data, JSON_OBJECT('uuid', deployment_uuid)),
                    'action', JSON_OBJECT(
                        'uuid', act_uuid,
                        'scheme', act_scheme,
                        'freq', act_freq
                    ),
                    'run', JSON_OBJECT(
                        'uuid', run_uuid,
                        'req', run_req,
                        'res', run_res,
                        'duration', run_duration,
                        'ok', run_ok,
                        'hash', run_hash,
                        'fid', run_fid,
                        'ago', run_ago,
                        'in', run_in,
                        'hash_count', run_hash_count
                    )
                )
            ),
            JSON_ARRAY()
        ) AS json_result
        FROM ( 
            {$q['rows:runs:joined']}
        ) subquery
        ";

        $q['json:runs:latest'] = "{$q['json:runs:all']} where (subquery.row_num = 1 or subquery.row_num is NULL)";


        $this->q = $q;
    }

}