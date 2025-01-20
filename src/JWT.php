<?php

declare(strict_types=1);
namespace Glued\Lib;

use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\ClaimCheckerManager;
use Jose\Component\Checker\ExpirationTimeChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\IssuedAtChecker;
use Jose\Component\Checker\IssuerChecker;
use Jose\Component\Checker\NotBeforeChecker;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jose\Component\Signature\JWS;
use Phpfastcache\Helper\Psr16Adapter;

/**
 * Provides authentication methods using Json Web Tokens
 */

class JWT extends Bearer
{
    protected int $oidcTtl;
    protected string $oidcDiscovery;
    protected string $oidcIssuer;
    protected string $tokenCookie;
    protected string $tokenHeader;
    protected string $tokenRegexp;
    protected $cache;
    protected $utils;
    protected $pdo;

    private ?\Jose\Component\Signature\JWS $jws = null;
    private ?\Jose\Component\Core\JWKSet $jwkSet = null;
    private array $jwtClaims = [];
    private array $jwtHeaders = [];
    private int $signaturesCount = 0;

    /**
     * Constructor for OIDC-related functionality.
     *
     * @param array $oidcSettings Configuration settings for OIDC, including:
     *   - `discovery` (string): The OIDC discovery endpoint URL.
     *   - `issuer` (string): The expected OIDC issuer identifier.
     *   - `ttl` (int): Time-to-live for caching OIDC discovery data, in seconds.
     *   - `cookie` (string): The name of the cookie containing the token.
     *   - `header` (string): The name of the header containing the token.
     *   - `regexp` (string): A regular expression for validating and extracting the token.
     * @param \Phpfastcache\Helper\Psr16Adapter $cacheHandler Cache handler for managing discovery data.
     * @param \PDO
     * @param \Glued\Lib\Utils
     */
    public function __construct(array $oidcSettings, Psr16Adapter $cacheHandler, \PDO $pdo, $utils) {
        $this->oidcDiscovery = $oidcSettings['discovery'];
        $this->oidcIssuer = $oidcSettings['issuer'];
        $this->oidcTtl = $oidcSettings['ttl'];
        $this->tokenCookie = $oidcSettings['cookie'];
        $this->tokenHeader = $oidcSettings['header'];
        $this->tokenRegexp = $oidcSettings['regexp'];
        $this->cache = $cacheHandler;
        $this->utils = $utils;
    }


    /**
     * Retrieves and caches OpenID Connect (OIDC) discovery data.
     *
     * Fetches OIDC configuration data from the discovery endpoint
     * and validates the discovered issuer against the configured issuer.
     * If cached data is available and valid, it is returned; otherwise,
     * fresh data is fetched, validated, and cached.
     *
     * @param array $oidc The OIDC configuration array.
     * @return array The OIDC discovery data as an associative array.
     * @throws \Exception If the discovery process fails or if the discovered
     *                     issuer does not match the configured issuer.
     */
    public function fetchOidcConfiguration(): array
    {
        $cacheKey = "gluedOidcConfiguration_" . md5($this->oidcDiscovery);

        // Attempt to retrieve cached discovery data
        $res = $this->cache->has($cacheKey)
            ? json_decode($this->cache->get($cacheKey), true) ?? []
            : [];

        // If cache is empty or issuer doesn't match, fetch fresh data
        if (empty($res) || ($res['issuer'] ?? null) !== $this->oidcIssuer) {
            $json = $this->utils->fetch_uri($this->oidcDiscovery) ?? '';
            $res = json_decode($json, true);
            if (empty($res)) {
                throw new \Exception("Fetching OIDC discovery configuration {$this->oidcDiscovery} failed.", 502);
            }
            if (($res['issuer'] ?? null) !== $this->oidcIssuer) {
                throw new \Exception("Discovered OIDC issuer {$res['issuer']} doesn't match the expected issuer {$this->oidcIssuer}.", 500);
            }
            // Cache the new discovery data
            $this->cache->set($cacheKey, $json, $this->oidcTtl);
        }
        return $res;
    }

    /**
     * Retrieves and caches JSON Web Key Set (JWKS) data.
     *
     * Fetches JWKS data from the provided URI and caches it for subsequent use.
     * If cached data is available and valid, it is returned; otherwise,
     * fresh data is fetched, validated, and cached.
     *
     * @param string $jwksUri The URI for fetching the JWKS data.
     * @return array The JWKS data as an associative array.
     * @throws \Exception If the JWKS retrieval process fails or returns invalid data.
     */
    public function fetchOidcJwks(string $jwksUri): array
    {
        $cacheKey = "gluedOidcJwks_" . md5($this->oidcDiscovery);

        // Attempt to retrieve cached JWKS data
        $jwks = $this->cache->has($cacheKey)
            ? json_decode($this->cache->get($cacheKey), true) ?? []
            : [];

        // If cache is empty or 'keys' not found, fetch fresh data
        if (empty($jwks) || !isset($jwks['keys'])) {
            $json = $this->utils->fetch_uri($jwksUri) ?? '';
            $jwks = json_decode($json, true) ?? [];
            if (empty($jwks)) {
                throw new \Exception("Identity server returned empty JWKS response `{$jwksUri}`.", 502);
            }
            if (!isset($jwks['keys'])) {
                throw new \Exception("Identity server failed to return JWKS certificates.", 502);
            }
            // Cache the new JWKS data
            $this->cache->set($cacheKey, $json, $this->oidcTtl);
        }
        return $jwks;
    }

    /**
     * Processes OIDC JSON Web Key (JWK) data and converts it to JWK objects.
     *
     * Extracts and converts all JWKs with a key usage of 'sig' (signature)
     * from the provided JWKS data into an array of JWK objects.
     *
     * @param array $oidcJwks The OIDC JWKS data containing 'keys'.
     * @return array An array of JWK objects for keys with 'use' set to 'sig'.
     */
    public function processOidcJwks(array $oidcJwks): array
    {
        $jwk = [];
        foreach ($oidcJwks['keys'] as $item) {
            $item = (array) $item;
            if ($item['use'] === 'sig') { $jwk[] = new JWK($item); }
        }
        return $jwk;
    }


    /**
     * Parse the JWT token into an internal JWS and JWKSet representation,
     * extracting claims, headers, etc. Use get methods to retrieve them.
     */
    public function parseToken(string $accessToken, array $certs): void
    {
        if ($accessToken === '') {
            throw new \Exception('Raw JWT token is an empty string.', 401);
        }
        try {
            $jwsSerializerManager = new JWSSerializerManager([new CompactSerializer()]);
            $this->jws = $jwsSerializerManager->unserialize($accessToken);
            $this->jwkSet = new JWKSet($certs);
            $this->jwtClaims = json_decode($this->jws->getPayload(), true) ?? [];
            $this->jwtHeaders = $this->jws->getSignature(0)->getProtectedHeader();
            $this->signaturesCount = $this->jws->countSignatures();
        } catch (\Exception $e) {
            throw new \Exception('Failed to parse token: ' . $e->getMessage(), 401, $e);
        }
    }

    /**
     * Validate the token (signature, headers, claims) using the internally stored JWS/JWKSet.
     */
    public function validateToken(): void
    {
        if (!$this->jws || !$this->jwkSet) {
            throw new \Exception('No parsed token or key set available. Call parseToken() first.', 401);
        }

        try {
            // Verify signature
            $jwsVerifier = new JWSVerifier(
                new AlgorithmManager([
                    new RS256(),
                    new RS512(),
                ])
            );

            if (!$jwsVerifier->verifyWithKeySet($this->jws, $this->jwkSet, 0)) {
                throw new \Exception('Token signature verification failed.');
            }

            // Verify headers
            $headerCheckerManager = new HeaderCheckerManager(
                [new AlgorithmChecker(['RS256', 'RS512'])],
                [new JWSTokenSupport()]
            );
            $headerCheckerManager->check($this->jws, 0, ['alg', 'typ', 'kid']);

            // Verify claims
            $claimCheckerManager = new ClaimCheckerManager([
                new IssuedAtChecker(1000),
                new NotBeforeChecker(1000),
                new ExpirationTimeChecker(),
                new IssuerChecker([$this->oidcIssuer])
            ]);
            $claimCheckerManager->check($this->jwtClaims, ['iss', 'sub', 'aud', 'exp']);

        } catch (\Exception $e) {
            throw new \Exception('Token validation failed: ' . $e->getMessage(), 401, $e);
        }
    }

    public function matchToken(): array|object
    {
        $db = new Sql($this->pdo, 'core_users');
        $this->pdo->startTrans();

        $res = $db->get($this->jwtClaims['sub']);
        if (!$res) {
            $doc = [
                'uuid' => $this->jwtClaims['sub'],
                'profiles' => [
                    $this->jwtClaims['issuer'] => [
                        'name' => $this->jwtClaims['name'],
                        'email' => $this->jwtClaims['email'],
                        'username' => $this->jwtClaims['preferred_username'],
                    ]
                ]
            ];
            $res = $db->create($doc);
        }
        $this->pdo->commit();
        return ($doc ?? $res);
    }

    /**
     * Get the parsed claims.
     */
    public function getJwtClaims(): array
    {
        return $this->jwtClaims;
    }

    /**
     * Get the parsed headers.
     */
    public function getJwtHeaders(): array
    {
        return $this->jwtHeaders;
    }

    /**
     * Get the number of signatures on this token.
     */
    public function getSignaturesCount(): int
    {
        return $this->signaturesCount;
    }

    /**
     * Get the loaded JWKSet.
     */
    public function getJwkSet(): ?JWKSet
    {
        return $this->jwkSet;
    }

    /**
     * Get the underlying JWT (JWS) object.
     */
    public function getJws(): ?JWS
    {
        return $this->jws;
    }

}

