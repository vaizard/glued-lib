<?php

declare(strict_types=1);

namespace Glued\Lib;

use Glued\Lib\Exceptions\AuthTokenException;
use Glued\Lib\Exceptions\AuthOidcException;
use Glued\Lib\Exceptions\AuthJwtException;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Easy\Load;
use Jose\Component\Core\JWKSet;
use Selective\Transformer\ArrayTransformer;
use \Ramsey\Uuid\Uuid;


/**
 * Provides authentication and authorization methods
 */

class Auth
{
    protected $settings;
    protected $db;
    protected $logger;
    protected $events;
    protected $e;
    protected $m;
    protected $fscache;
    protected $utils;
    protected $crypto;

    public function __construct($settings, $db, $logger, $events, $enforcer, $fscache, $utils, $crypto) {
        $this->db = $db;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->events = $events;
        $this->e = $enforcer;
        $this->m = $this->e->getModel();
        $this->fscache = $fscache;
        $this->utils = $utils;
        $this->crypto = $crypto;
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
            if ($conf == []) { throw new AuthOidcException('Identity server connection failure, please reload this page.'); }
            if ($conf['issuer'] != $oidc['uri']['realm']) { throw new AuthOidcException('Identity server configuration mismatch.'); }
            $this->fscache->set('glued_oidc_uri_discovery', $json, 300); // TODO make the 300s value configurable
        }

        $hit = $this->fscache->has('glued_oidc_uri_jwks');
        if ($hit) {
            $conf = (array) json_decode($this->fscache->get('glued_oidc_uri_jwks')) ?? [];
            if (!isset($jwks['keys'])) { $hit = false; }
        }

        if (!$hit) {
            $json = (string) $this->utils->fetch_uri($oidc['uri']['jwks']) ?? '';
            $jwks = (array) json_decode($json) ?? [];
            if ($conf == []) { throw new AuthOidcException('Identity server connection failure, please reload this page.'); }
            if (!isset($jwks['keys'])) { throw new AuthOidcException('Identity server certificate mismatch.'); }
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
                return $matches[1];
            }
        }

        // Token not found in header, try the cookie.
        $cookieParams = $request->getCookieParams();
        if (isset($cookieParams[$this->settings['oidc']['cookie']])) {
            if (preg_match($this->settings['oidc']["regexp"], $cookieParams[$this->settings['oidc']['cookie']], $matches)) {
                return $matches[1];
            }
            return $cookieParams[$this->settings['oidc']["cookie"]];
        };

        throw new AuthTokenException("Token not found.");
    }

    public function validate_jwt_token($accesstoken, $certs) {
        try {
            $oidc = $this->settings['oidc'];
            $decoded = [];

            // Instantiate the algorithm manager with required algorithms
            $jwsVerifier = new JWSVerifier(new AlgorithmManager([
                new RS256(),
                new RS512(),
            ]));

            // Instantiate ClaimCheckerManager with the required constraints
            $claimCheckerManager = new ClaimCheckerManager([
                new IssuedAtChecker(1000),
                new NotBeforeChecker(1000),
                new ExpirationTimeChecker(),
                new IssuerChecker([ $oidc['uri']['realm'] ])
            ]);

            // Set up the HeaderCheckerManager with required algorithms
            $headerCheckerManager = new HeaderCheckerManager(
                [ new AlgorithmChecker(['RS256', 'RS512']) ],
                [ new JWSTokenSupport() ]
            );

            // Load the JWS (signature) and JWK (keys). NOTE that multiple signatures are supported for reasons explained here
            // https://stackoverflow.com/questions/50031985/what-is-a-use-case-for-having-multiple-signatures-in-a-jws-that-uses-jws-json-se
            // For simplicity, we intentionally pick the first signature (signatureIndex 0). This probably has security implications.
            $jwsSerializerManager = new JWSSerializerManager([ new CompactSerializer() ]);
            $jws = $jwsSerializerManager->unserialize($accesstoken);
            $jwk = new JWKSet($certs);
            $r['claims'] = json_decode($jws->getPayload(), true) ?? [];
            $r['headers'] = $jws->getSignature(0)->getProtectedHeader();
            $r['signatures'] = $jws->countSignatures();

            // Check stuff
            if (!$jwsVerifier->verifyWithKeySet($jws, $jwk, 0)) {
                throw new \Exception('Token signature verification failed');
            }
            $headerCheckerManager->check($jws, 0, ['alg', 'typ', 'kid']);
            $claimCheckerManager->check($r['claims'], ['iss', 'sub', 'aud', 'exp']);
        } catch (\Exception $e) { throw new AuthJwtException($e->getMessage(), $e->getCode(), $e); }
        return $r;
    }

    function validate_api_token($apiKey): mixed
    {
        // Disregard tokens not starting with the `apitoken` prefix
        $apiKey = (string) $apiKey;
        if (!$apiKey || (!str_starts_with($apiKey, $this->settings['glued']['apitoken']))) {
            throw new AuthJwtException('Provided token is not an API token.', 400);
        }

        // Execute a query to check if the API key exists and is valid
        $query = "
            SELECT 
              bin_to_uuid(tok.c_uuid, true) as token_uuid,
              bin_to_uuid(tok.c_user_uuid, true) as user_uuid,
              u.c_handle as user_handle
            FROM t_core_tokens AS tok
            LEFT JOIN t_core_users AS u ON tok.c_inherit = u.c_uuid
            WHERE tok.c_token = ?
            AND IFNULL(tok.c_expired_at,NOW()+42) >= (NOW()+0)
            AND u.c_active = 1
        ";
        $params = [$apiKey];

        // Get the result of the query (number of matching rows)
        $result = $this->db->rawQuery($query, $params); // Get the result of the query
        if (empty($result)) {
            throw new AuthJwtException('Invalid / revoked API token provided', 401);
        }
        return $result;
    }

    function generate_api_token($userUuid, mixed $expiry = null): string
    {
        // Generate a random string for the API key, prefix it
        $apiKey = $this->settings['glued']['apitoken'] . $this->crypto->genkey_base64();

        // $expiry can be either `null` (token valid forever) or
        // a `string` (datetime or relative time distance such as '+30 days')
        // strings will be converted to a datetime format.
        if (!is_null($expiry)) {
            $expiry = date('Y-m-d H:i:s', strtotime($expiry));
        }

        // Store the API key in the database
        $this->logger->debug( 'lib.auth.addtoken', [ $apiKey, $expiry, $userUuid ]);
        $query = "INSERT INTO t_core_tokens (c_inherit, c_token, c_expired_at) VALUES (uuid_to_bin(?,true), ?, ?)";
        $params = [$userUuid, $apiKey, $expiry];
        $res = $this->db->rawQuery($query, $params);
        if ($res) $this->events->emit('core.auth.token.created', [$res]);

        return $apiKey;
    }

    //////////////////////////////////////////////////////////////////////////
    // OTHER AUTH RELATED METHODS ////////////////////////////////////////////
    //////////////////////////////////////////////////////////////////////////


    // call with users() to get them all
    // users(["c_column1", $something], ["c_column2", $somethingelse]) to filter (AND logic applies)
    // see getuser() for an example
    public function users(...$params) :? array {
        foreach ($params as $p) {
              $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_users", null, [
            "BIN_TO_UUID(`c_uuid`) AS `c_uuid`", "c_profile", "c_attr", "c_locale", "c_handle", "c_email", "c_ts_created", "c_ts_updated", "c_stor_name"
        ]);
    }


    // call with domains() to get them all
    // domains(["c_column1", $something], ["c_column2", $somethingelse]) to filter (AND logic applies)
    public function domains(...$params) :? array {
        foreach ($params as $p) {
            $this->db->where($p[0], $p[1]);
        }
        return $this->db->get("t_core_domains", null, [
            "BIN_TO_UUID(`c_uuid`) AS `c_uuid`", "BIN_TO_UUID(`c_primary_owner`) AS `c_primary_owner`", "`c_attr`"
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


    public function addrole(string $name, string $description): mixed
    {
        $q = "INSERT INTO `t_core_roles` (`c_uuid`, `c_name`, `c_dscr`) VALUES (uuid_to_bin(?, true), ?, ?)";
        $qp = [Uuid::uuid4()->toString(), $name, $description];
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

    /**
     * @param array $jwt_claims
     * @return bool
     */
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




}

