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

/**
 * Provides authentication and authorization methods
 */

class Oidc
{
    protected $config;
    protected $cache;
    protected $utils;

    /**
     * Constructor for OIDC-related functionality.
     *
     * @param array $oidcSettings Configuration settings for OIDC.
     * @param \Phpfastcache\Helper\Psr16Adapter $cacheHandler Cache handler for managing discovery data.
     * @param \Glued\Lib\Utils
     */
    public function __construct(array $oidcSettings, $cacheHandler, $utils) {
        $this->config = $oidcSettings;
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
    public function fetchOidcConfiguration(array $oidc): array
    {
        $cacheKey = "gluedOidcConfiguration:" . md5($this->config['discovery']);

        // Attempt to retrieve cached discovery data
        $res = $this->cache->has($cacheKey)
            ? json_decode($this->cache->get($cacheKey), true) ?? []
            : [];

        // If cache is empty or issuer doesn't match, fetch fresh data
        if (empty($res) || ($res['issuer'] ?? null) !== $oidc['issuer']) {
            $json = $this->utils->fetch_uri($oidc['discovery']) ?? '';
            $res = json_decode($json, true);
            if (empty($res)) {
                throw new \Exception("Fetching OIDC discovery configuration {$oidc['discovery']} failed.", 502);
            }
            if (($res['issuer'] ?? null) !== $oidc['issuer']) {
                throw new \Exception("Discovered OIDC issuer {$res['issuer']} doesn't match the expected issuer {$oidc['issuer']}.", 500);
            }
            // Cache the new discovery data
            $this->cache->set($cacheKey, $json, $oidc['ttl']);
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
        $cacheKey = "gluedOidcJwks:" . md5($this->config['discovery']);

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
            $this->cache->set($cacheKey, $json, $this->config['ttl']);
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

    public function fetchToken($request)
    {
        // Check for token in header and in the cookie
        $header = $request->getHeaderLine($this->config['header']);
        if (!empty($header) && preg_match($this->config['regexp'], $header, $matches)) {
            return $matches[1];
        }

        $cookie = $request->getCookieParams()[$this->config['cookie']] ?? null;
        if ($cookie && preg_match($this->config['regexp'], $cookie, $matches)) {
            return $matches[1];
        }

        if ($cookie) {
            return $cookie;
        }

        throw new \Exception("Token not found.", 401);
    }


    public function validateJwtToken($accessToken, $certs) {
        try {
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
                new IssuerChecker([ $this->config['uri']['realm'] ])
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
            $jws = $jwsSerializerManager->unserialize($accessToken);
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
        } catch (\Exception $e) { throw new \Exception($e->getMessage() . ' ' . $e->getCode(), 401, $e); }
        return $r;
    }
}

