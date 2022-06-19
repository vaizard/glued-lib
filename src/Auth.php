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
    // users(["c_uid", $uuid]) to get a specific users
    // users(["c_column1", $something], ["c_column2", $somethingelse]) to filter
    public function users(...$params) :? array {
        foreach ($params as $p) {
            $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_users", null, [ 
            "BIN_TO_UUID(`c_uuid`) AS `c_uuid`", "c_profile", "c_account", "c_attr", "c_locale", "c_nick", "c_ts_created", "c_ts_modified", "c_stor_name", "c_email"
        ]);
    }

    /**
     * @param array $jwt_claims
     * @return bool
     */
    public function adduser(array $jwt_claims) {

        // check if user exists
        try {
            $this->db->where('c_uuid = uuid_to_bin(?, true)', [ $jwt_claims['sub'] ?? '' ]);
            $user = $this->db->getOne('t_core_users', null);
            if ($user) die('user already exists');
        } catch (\Exception $e) { throw new DbException($e->getMessage(), $e->getCode(), $e); }

        
        $account['locale'] = $this->utils->get_default_locale($jwt_claims['locale'] ?? 'en') ?? 'en_US';

        try {
            $tranform = new ArrayTransformer();
            $profile = $transform
                ->map('name.0.fn',          'name')
                ->map('name.0.given',       'given_name')
                ->map('name.0.family',      'family_name')
                ->map('name.0.@.src',       'iss')
                ->map('email.0.uri',        'email')
                ->map('email.0.@.src',      'iss')
                ->set('email.0.@.pref',     1)
                ->set('service.0.kind',     'oidc')
                ->map('service.0.uri',      'iss')
                ->map('service.0.handle',   'preferred_username')
                ->map('website.0.uri',      'website')
                ->toArray($jwt_claims) ?? [];
        } catch (\Exception $e) { throw new InternalException($e->getMessage(), $e->getCode(), $e); }

            // log do shadow profile log table
            // TODO shadow profile
            if ($jwt_claims['sub'])  {
                $data["c_uuid"]     = $this->db->func('uuid_to_bin(?, true)', [$jwt_claims['sub']]);
                $data["c_profile"]  = json_encode($profile);
                $data["c_account"]  = json_encode($account);
                $data["c_email"]  = $jwt_claims['emaild'] ?? 'NULL';
                $data["c_nick"]  = $jwt_claims['preferred_username'] ?? 'NULL';
                return $this->db->insert('t_core_users', $data);
            } 
        }




}

