<?php

declare(strict_types=1);
namespace Glued\Lib;

use PDO;
use Phpfastcache\Helper\Psr16Adapter;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Provides authentication methods using Private Access Tokens
 */

class PAT extends Bearer
{
    public PDO $pdo;
    public Psr16Adapter $cache;
    public Logger $logger;
    protected string $tokenCookie;
    protected string $tokenHeader;
    protected string $tokenRegexp;
    protected string $tokenPrefix;

    public function __construct(array $settings, Psr16Adapter $cache, PDO $pdo, Logger $logger)
    {
        $this->tokenPrefix = $settings['glued']['patprefix'];
        $this->tokenHeader = $settings['oidc']['header'];
        $this->tokenRegexp = $settings['oidc']['regexp'];
        $this->tokenCookie = $settings['oidc']['cookie'];
        $this->cache = $cache;
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Validates and retrieves details for a Personal Access Token (PAT).
     *
     * This function ensures that the provided token starts with the predefined
     * prefix (`patPrefix`) and then validates it against the database. If the token
     * is invalid, revoked, or does not meet the expected format, an exception is thrown.
     *
     * @param string $pat The Personal Access Token to validate.
     * @return mixed The token details retrieved from the database if the token is valid.
     * @throws \Exception If the token does not start with the required prefix.
     * @throws \Exception If the token is invalid, revoked, or not found in the database.
     */
    function matchToken(string $pat): mixed
    {
        // Disregard tokens not starting with `patPrefix`, drop the prefix
        if (!$pat || (!str_starts_with($pat, $this->tokenPrefix))) {
            throw new \Exception('Provided token is not an API token.', 401);
        }
        $pat = substr($pat, strlen($this->tokenPrefix));
        $db = new Sql($this->pdo, "core_pat_ext");
        $db->where("token", "=", $pat);
        $res = $db->getAll();
        if (empty($res)) {
            throw new \Exception('Invalid / revoked API token provided', 401);
        }
        return $res;
    }

    /**
     * Creates a new Personal Access Token (PAT) for a user.
     *
     * @param string $inheritUuid The UUID of the user for whom the PAT is created.
     * @param mixed $patExpiry The expiration date/time of the PAT. Can be:
     *                              - `null` for a token valid indefinitely.
     *                              - A `string` representing a datetime or a relative time (e.g., '+30 days').
     * @param string $patName A name or identifier for the PAT.
     * @return string           The generated PAT string.
     * @throws \Exception       If the PAT creation fails.
     */
    function createToken(string $inheritUuid, mixed $patExpiry = null, string $patName = null): string
    {
        // Generate a random string for the API key, prefixed with the PAT prefix
        $pat = $this->tokenPrefix . (new \Glued\Lib\Crypto)->genkey_base64();
        // Convert $patExpiry to a postgres compatible datetime string if it's not null
        if (!is_null($patExpiry)) {
            $patExpiry = date('Y-m-d H:i:sP', strtotime($patExpiry));
        }
        $doc = [
            'token' => $pat,
            'inheritUuid' => $inheritUuid,
            'exp' => $patExpiry,
            'name' => $patName
        ];

        try {
            $db = new Sql($this->pdo, "core_pats");
            $createdUuid = $db->create($doc, $upsert = false);
            if ($createdUuid) {
                $this->logger->info('auth.pat.create', ['status' => 'ok', 'patUuid' => $createdUuid]);
            }
        } catch (\Exception $e) {
            $this->logger->error('auth.pat.create', ['message' => $e->getMessage()]);
            // TODO expand logger to support sending notifications or basically handling events.
            throw $e;
        }

        return $pat; // Return the generated PAT string
    }
}