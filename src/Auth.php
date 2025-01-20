<?php

declare(strict_types=1);

namespace Glued\Lib;


use Monolog\Logger;
use PDO;
use Phpfastcache\Helper\Psr16Adapter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Selective\Transformer\ArrayTransformer;

/**
 * Provides authentication and authorization methods
 */

class Auth
{
    protected array $settings;
    protected PDO $pg;
    protected Logger $logger;
    protected $e;
    protected $m;
    protected Psr16Adapter $cache;

    public function __construct(array $settings, PDO $dbo, Logger $logger, Psr16Adapter $cache) {
        $this->db = $dbo;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    //////////////////////////////////////////////////////////////////////////
    // OTHER AUTH RELATED METHODS ////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////


    /*
    // call with domains() to get them all
    // domains(["c_column1", $something], ["c_column2", $somethingelse]) to filter (AND logic applies)
    public function domains(...$params) :? array {
        foreach ($params as $p) {
            $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_domains", null, [
            "BIN_TO_UUID(`c_uuid`, true) AS `c_uuid`", "BIN_TO_UUID(`c_primary_owner`, true) AS `c_primary_owner`", "`c_attr`"
        ]);
    }



    public function addrole(string $name, string $description): mixed
    {
        $q = "INSERT INTO `t_core_roles` (`c_uuid`, `c_name`, `c_dscr`) VALUES (uuid_to_bin(?, true), ?, ?)";
        $qp = [ "uuid" => Uuid::uuid4()->toString(), "name" => $name, "description" => $description ];
        $this->logger->debug( 'lib.auth.addrole', $qp);
        $res = $this->db->rawQuery($q, $qp);
        if ($res) { $this->events->emit('core.auth.role.created', $qp); }
        return $qp;
    }


    public function adddomain(string $name, string $primary_owner, $create_domain_if_none_exists = false) : mixed
    {
        $domain_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $part_insert = '
                INSERT INTO `t_core_domains` (`c_uuid`, `c_primary_owner`, `c_attr`, `c_ts_created`, `c_ts_updated`) 
                SELECT uuid_to_bin(?, true), uuid_to_bin(?, true), ?, now(), now() -- no parentheses!
                FROM DUAL -- DUAL is a built-in table with one row
        ';
        $part_if_none_exists = '
                WHERE NOT EXISTS ( select 1 from `t_core_domains` limit 1 );
        ';

        $json['uuid'] = $domain_uuid;
        $json['ownership'] = [
            '_sub' => $primary_owner,
            '_iat' => time(),
            '_primary' => 1
        ];

        if ($create_domain_if_none_exists) {
            $q = $part_insert . $part_if_none_exists;
            $json['name'] = "System";
            $json['_root'] = 1;
        }
        else {
            $q = $part_insert;
            if ($name == "") { $json['name'] = "Domain ".substr($domain_uuid, -4); }
            else { $json['name'] = $name; }
        }

        $this->logger->debug( 'lib.auth.addomain', [ $name, $primary_owner, $create_domain_if_none_exists ]);
        $res = $this->db->rawQuery($q, [$domain_uuid, $primary_owner, json_encode($json)]);
        if ($res) $this->events->emit('core.auth.domain.created', [$res]);
        return $res;
    }


    public function adduser(array $jwt_claims) : mixed {
        // if user exists, break (return false)
        if ($this->getuser($jwt_claims['sub'])) return false;

        // else add user
        $attr['locale'] = $this->utils->get_default_locale($jwt_claims['locale'] ?? 'en') ?? 'en_US';
        $attr['status']['active'] = 1;
        $attr['status']['flag']['suspended'] = 0;

        $transform = new ArrayTransformer();
        $transform
            ->map(destination: 'service.0.uri',     source: 'iss')
            ->map(destination: 'service.0.handle',  source: 'preferred_username')
            ->set(destination: 'service.0.kind',     value: 'oidc')
            ->map(destination: 'service.0._iss',    source: 'iss')
            ->set(destination: 'service.0._iat',     value: time())
            ->map(destination: 'service.0._sub',    source: 'sub')
            ->set(destination: 'service.0._s',       value: 'service')
            ->set(destination: 'service.0._v',       value: 1)
            ->set(destination: 'service.0.uuid',     value: \Ramsey\Uuid\Uuid::uuid4()->toString());

        if (array_key_exists('name', $jwt_claims) and $jwt_claims['email'] != "") {
            $transform
                ->map(destination: 'name.0.value',  source: 'name')
                ->map(destination: 'name.0.given',  source: 'given_name')
                ->map(destination: 'name.0.family', source: 'family_name')
                ->map(destination: 'name.0._iss',   source: 'iss')
                ->set(destination: 'name.0._iat',    value: time())
                ->map(destination: 'name.0._sub',   source: 'sub')
                ->set(destination: 'name.0._s',      value: 'name')
                ->set(destination: 'name.0._v',      value: 1)
                ->set(destination: 'name.0.uuid',    value: \Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        if (array_key_exists('email', $jwt_claims) and $jwt_claims['email'] != "") {
            $transform
                ->map(destination: 'email.0.value',   source: 'email')
                ->map(destination: 'email.0._iss',    source: 'iss')
                ->set(destination: 'email.0._iat',     value: time())
                ->map(destination: 'email.0._sub',    source: 'sub')
                ->set(destination: 'email.0._primary', value: 1)
                ->set(destination: 'email.0._s',       value: 'email')
                ->set(destination: 'email.0._v',       value: 1)
                ->set(destination: 'email.0.uuid',     value: \Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        if (array_key_exists('website', $jwt_claims) and $jwt_claims['website'] != "") {
            $transform
                ->map(destination: 'uri.0.value',    source: 'website')
                ->set(destination: 'uri.0.kind',      value: 'website')
                ->map(destination: 'uri.0._iss',     source: 'iss')
                ->set(destination: 'uri.0._iat',      value: time())
                ->map(destination: 'uri.0._sub',     source: 'sub')
                ->set(destination: 'uri.0._s',        value: 'uri')
                ->set(destination: 'uri.0._v',        value: 1)
                ->set(destination: 'uri.0.uuid',      value: \Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        $profile = $transform->toArray($jwt_claims) ?? [];
        // TODO log data to shadow profile
        if ($jwt_claims['sub'])  {
            $data["c_uuid"]     = $this->db->func('uuid_to_bin(?, true)', [$jwt_claims['sub']]);
            $data["c_profile"]  = json_encode($profile);
            $data["c_attr"]     = json_encode($attr);
            $data["c_email"]    = $jwt_claims['email'] ?? 'NULL';
            $data["c_handle"]   = $jwt_claims['preferred_username'] ?? 'NULL';
            $domain = $this->adddomain(name: 'Root', primary_owner: $jwt_claims['sub'], create_domain_if_none_exists: true);
            $res = $this->db->insert('t_core_users', $data);
            if ($res) $this->events->emit('core.auth.user.created', [$res]);
            return $res;
        }
        return false;
    }
*/


    //////////////////////////////////////////////////////////////////////////
    // AUTHORIZATION METHODS /////////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////

    public function get_domains() {
        return $this->m->getPolicy('g', 'g2');
    }

    public function get_roles() {
        return $this->m->getPolicy('g','g');
    }

    public function get_roles_with_domain(string $domain) {
        return $this->m->getFilteredPolicy('g', 'g', 2, (string) $domain);
    }

    public function get_roles_with_role(string $role) {
        return $this->m->getFilteredPolicy('g', 'g', 1, 'r:' . (string) $role);
    }
    public function get_roles_with_user(string $user) {
        return $this->m->getFilteredPolicy('g', 'g', 0, 'u:' . (string) $user);
    }

    public function get_permissions() {
        return $this->e->getPolicy('p', 'p');
    }

    public function get_permissions_for_subject(string $sub) {
        return $this->e->getFilteredPolicy(0, $sub);
    }

    public function get_permissions_for_subject_in_domain(string $sub, string $dom) {
        return $this->e->getFilteredPolicy(0, $sub, $dom);
    }

    public function get_permissions_for_user($string) {
        return $this->m->getFilteredPolicy('p','p', 0,'r:usage');
    }

    public function get_permissions_for_domain($string) {
        return $this->m->getFilteredPolicy('p','p', 0,'r:usage');
    }

    public function get_permissions_for_object($string) {
        return $this->m->getFilteredPolicy('p','p', 0,'r:usage');
    }



}

