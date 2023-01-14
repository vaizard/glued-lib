<?php

declare(strict_types=1);

namespace Glued\Lib;

use Glued\Lib\Exceptions\AuthTokenException;
use Glued\Lib\Exceptions\AuthOidcException;
use Glued\Lib\Exceptions\AuthJwtException;
use Glued\Lib\Exceptions\DbException;
use Glued\Lib\Exceptions\InternalException;
use Jose\Component\Core\JWK;
use Jose\Easy\Load;
use Jose\Component\Core\JWKSet;
use Selective\Transformer\ArrayTransformer;



/**
 * Provides authentication and authorization methods
 */

class Auth
{

    protected $settings;
    protected $db;
    protected $logger;
    protected $events;

    public function __construct($settings, $db, $logger, $events, $enforcer, $fscache, $utils) {
        $this->db = $db;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->events = $events;
        $this->e = $enforcer;
        $this->m = $this->e->getModel();
        $this->fscache = $fscache;
        $this->utils = $utils;
    }

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

    //////////////////////////////////////////////////////////////////////////
    // AUTHENTICATION METHODS ////////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////

    public function get_jwks(): array {
        $oidc = $this->settings['oidc'];
        $hit = $this->fscache->has('glued_oidc_uri_discovery');
        if ($hit) {
            $conf = (array) json_decode($this->fscache->get('glued_oidc_uri_discovery')) ?? [];
            if ($conf['issuer'] != $oidc['uri']['realm']) $hit = false;
        }

        if (!$hit) {
            $json = (string) $this->utils->fetch_uri($oidc['uri']['discovery']) ?? ''; 
            $conf = (array) json_decode($json);
            if ($conf == []) throw new AuthOidcException('Identity server connection failure, please reload this page.');
            if ($conf['issuer'] != $oidc['uri']['realm']) throw new AuthOidcException('Identity server configuration mismatch.');
            $this->fscache->set('glued_oidc_uri_discovery', $json, 300); // TODO make the 300s value configurable
        }

        $hit = $this->fscache->has('glued_oidc_uri_jwks');
        if ($hit) {
            $conf = (array) json_decode($this->fscache->get('glued_oidc_uri_jwks')) ?? [];
            if (!isset($jwks['keys'])) $hit = false;
        }

        if (!$hit) {
            $json = (string) $this->utils->fetch_uri($oidc['uri']['jwks']) ?? '';
            $jwks = (array) json_decode($json) ?? [];
            if ($conf == []) throw new AuthOidcException('Identity server connection failure, please reload this page.');
            if (!isset($jwks['keys'])) throw new AuthOidcException('Identity server certificate mismatch.');
            $this->fscache->set('glued_oidc_uri_jwks', $json, 300); // TODO make the 300s value configurable
        }

        $certs = [];
        foreach ($jwks['keys'] as $item) {
            $item = (array) $item;
            if ($item['use'] === 'sig') $certs[] = new JWK($item);
        }
        return $certs;
    }


    public function fetch_token($request) {
        // Check for token in header.
        $header = $request->getHeaderLine($this->settings['oidc']["header"]);
        if (false === empty($header)) {
            if (preg_match($this->settings['oidc']["regexp"], $header, $matches)) {
                //$this->log(LogLevel::DEBUG, "Using token from request header");
                return $matches[1];
            }
        }

        // Token not found in header, try the cookie.
        $cookieParams = $request->getCookieParams();
        if (isset($cookieParams[$this->settings['oidc']['cookie']])) {
            //$this->log(LogLevel::DEBUG, "Using token from cookie");
            if (preg_match($this->settings['oidc']["regexp"], $cookieParams[$this->settings['oidc']['cookie']], $matches)) {
                return $matches[1];
            }
            return $cookieParams[$this->settings['oidc']["cookie"]];
        };

        // If everything fails log and throw.
        //$this->log(LogLevel::WARNING, "Token not found");
        throw new AuthTokenException("Token not found.");
    }

    public function decode_token($accesstoken, $certs) {
        try {
            $oidc = $this->settings['oidc'];
            $jwt = Load::jws($accesstoken)   // Load and verify the token in $accesstoken
                ->algs(['RS256', 'RS512'])   // Check if allowed The algorithms are used
                ->exp()                      // Check if "exp" claim is present
                ->iat(1000)                  // Check if "iat" claim is present and within 1000ms leeway
                ->nbf(1000)                  // Check if "nbf" claim is present and within 1000ms leeway
                ->iss($oidc['uri']['realm']) // Check if "nbf" claim is present and matches the realm
                ->keyset(new JWKSet($certs)) // Key used to verify the signature
                ->run();                     // Do it.
            $decoded['claims'] = $jwt->claims->all() ?? [];
            $decoded['header'] = $jwt->header->all() ?? [];
        } catch (\Exception $e) { throw new AuthJwtException($e->getMessage(), $e->getCode(), $e); }
        return $decoded;
    }
   
    //////////////////////////////////////////////////////////////////////////
    // OTHER AUTH RELATED METHODS ////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////



    public function safeAddPolicy(object $e, object $m, string $section, string $type, array $rule) {
        if (!$m->hasPolicy($section, $type, $rule)) {
            $m->addPolicy($section, $type, $rule);  
            $e->savePolicy();
        }
    }

    // call with users() to get them all
    // users(["c_column1", $something], ["c_column2", $somethingelse]) to filter (AND logic applies)
    // see getuser() for an example
    public function users(...$params) :? array {
        foreach ($params as $p) {
              $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_users", null, [
            "BIN_TO_UUID(`c_uuid`) AS `c_uuid`", "c_profile", "c_attr", "c_locale", "c_handle", "c_email", "c_ts_created", "c_ts_modified", "c_stor_name"
        ]);
    }


    // call with domains() to get them all
    // domains(["c_column1", $something], ["c_column2", $somethingelse]) to filter (AND logic applies)
    public function domains(...$params) :? array {
        foreach ($params as $p) {
            $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_domains", null, [
            "BIN_TO_UUID(`c_uuid`) AS `c_uuid`", "BIN_TO_UUID(`c_primary_owner`) AS `c_primary_owner`", "`c_json`"
        ]);
    }


    public function getuser(string $uuid) : mixed {
        $user = $this->users([ 
            'c_uuid = uuid_to_bin(?, true)', [ $uuid ]
        ]);
        if (!is_array($user)) return false; // empty() below is meaningless if $user is not array
        if (!empty($user)) return $user[0];
        return false;
    }





    public function adddomain(string $name, string $primary_owner, $create_domain_if_none_exists = false) : mixed
    {
        $domain_uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $part_insert = '
                INSERT INTO `t_core_domains` (`c_uuid`, `c_primary_owner`, `c_json`, `c_ts_created`, `c_ts_modified`) 
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
            $json['name'] = "Root";
            $json['_root'] = 1;
        }
        else {
            $q = $part_insert;
            if ($name == "") { $json['name'] = "Domain ".substr($domain_uuid, -4); }
            else { $json['name'] = $name; }
        }

        $this->logger->debug( 'lib.auth.addomain', [ $name, $primary_owner, $create_domain_if_none_exists ]);
        return $this->db->rawQuery($q, [$domain_uuid, $primary_owner, json_encode($json)]);
    }

    /**
     * @param array $jwt_claims
     * @return bool
     */
    public function adduser(array $jwt_claims) : mixed {
        // if user exists, break (return false)
        if ($this->getuser($jwt_claims['sub'])) return false;

        // else add user
        $attr['locale'] = $this->utils->get_default_locale($jwt_claims['locale'] ?? 'en') ?? 'en_US';
        $transform = new ArrayTransformer();
        $transform
            ->map(destination: 'service.0.uri',     source: 'iss')
            ->map(destination: 'service.0.handle',  source: 'preferred_username')
            ->set(destination: 'service.0.kind',     value: 'oidc')
            ->map(destination: 'service.0._iss',    source: 'iss')
            ->set(destination: 'service.0._iat',     value: time())
            ->map(destination: 'service.0._sub',    source: 'sub')
            ->set(destination: 'service.0.uuid',     value: \Ramsey\Uuid\Uuid::uuid4()->toString());

        if (array_key_exists('name', $jwt_claims) and $jwt_claims['email'] != "") {
            $transform
                ->map(destination: 'name.0.value', source: 'name')
                ->map(destination: 'name.0.given', source: 'given_name')
                ->map(destination: 'name.0.family', source: 'family_name')
                ->map(destination: 'name.0._iss', source: 'iss')
                ->set(destination: 'name.0._iat', value: time())
                ->map(destination: 'name.0._sub', source: 'sub')
                ->set(destination: 'name.0.uuid', value: \Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        if (array_key_exists('email', $jwt_claims) and $jwt_claims['email'] != "") {
            $transform
                ->map(destination: 'email.0.value', source: 'email')
                ->map(destination: 'email.0._iss', source: 'iss')
                ->set(destination: 'email.0._iat', value: time())
                ->map(destination: 'email.0._sub', source: 'sub')
                ->set(destination: 'email.0._primary', value: 1)
                ->set(destination: 'email.0.uuid', value: \Ramsey\Uuid\Uuid::uuid4()->toString());
        }

        if (array_key_exists('website', $jwt_claims) and $jwt_claims['website'] != "") {
            $transform
                ->map(destination: 'uri.0.value',      source: 'website')
                ->set(destination: 'uri.0.kind',        value: 'website')
                ->map(destination: 'uri.0._iss',       source: 'iss')
                ->set(destination: 'uri.0._iat',        value: time())
                ->map(destination: 'uri.0._sub',       source: 'sub')
                ->set(destination: 'uri.0.uuid',        value: \Ramsey\Uuid\Uuid::uuid4()->toString());
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
            return $this->db->insert('t_core_users', $data);
        }
        return false;
    }




}

