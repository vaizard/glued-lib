<?php
declare(strict_types=1);

namespace Glued\Lib\Controllers;

use Exception;
use Glued\Lib\Exceptions\ExtendedException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Symfony\Component\Yaml\Yaml;

abstract class AbstractService extends AbstractBlank
{

    /**
     * Retrieves the OpenAPI specification file (JSON or YAML) from the specified cache directory.
     * If the ?json query parameter is provided, converts the YAML content to JSON.
     * If the ?yaml query parameter is provided, converts the JSON content to YAML.
     * If multiple OpenAPI specs are specified, only the first (in the following order: `openapi.json`, `openapi.yaml`,
     * `openapi.yml`) will be presented.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The request arguments.
     * @return Response The response object with the OpenAPI specification content.
     * @throws Exception If no OpenAPI specification file is found.
     */
    public function getOpenapi(Request $request, Response $response, array $args = []): Response
    {
        $path = "{$this->settings['glued']['datapath']}/{$this->settings['glued']['uservice']}/cache";
        $filesWhitelist = ["openapi.json", "openapi.yaml", "openapi.yml"];
        $convertToJson = $request->getQueryParams()['json'] ?? false;
        $convertToYaml = $request->getQueryParams()['yaml'] ?? false;

        foreach ($filesWhitelist as $file) {
            $fullPath = rtrim($path, '/') . '/' . $file;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                $extension = pathinfo($fullPath, PATHINFO_EXTENSION);

                if (($extension === 'yaml' || $extension === 'yml') && $convertToJson) {
                    $content = json_encode(Yaml::parse($content));
                    $contentType = 'application/json';
                } elseif ($extension === 'json' && $convertToYaml) {
                    $content = Yaml::dump(json_decode($content, true));
                    $contentType = 'application/x-yaml';
                } else {
                    $contentType = $extension === 'json' ? 'application/json' : 'application/x-yaml';
                }

                $response->getBody()->write($content);
                return $response->withHeader('Content-Type', $contentType);
            }
        }

        throw new Exception("OpenAPI specification not found", 404);
    }


    /**
     * Checks the health status of the service, including database connection and authentication status.
     *
     * This function performs health checks on the service, including checking the PostgreSQL database connection
     * and the authentication status, and returns the health status in a JSON response.
     *
     * @param Request $request The HTTP request object.
     * @param Response $response The HTTP response object.
     * @param array $args The request arguments.
     * @return Response The response object with the health status in JSON format.
     */
    public function getHealth(Request $request, Response $response, array $args = []): Response
    {
        try {
            $check['service'] = basename(__ROOT__);
            $check['timestamp'] = microtime();
            $check['healthy'] = true;
            $check['status']['postgres'] = $this->pg->query("select true as test")->fetch()['test'] ?? false;
            $check['status']['auth'] = $_SERVER['X-GLUED-AUTH-UUID'] ?? 'anonymous';
        } catch (\Exception $e) {
            $check['healthy'] = false;
            return $response->withJson($check);
        }
        return $response->withJson($check);
    }

    /**
     * Validates the JSON request body against a given JSON schema and returns the parsed body.
     *
     * This method performs the following steps:
     * 1. Checks if the `Content-Type` header is set to `application/json`. If not, throws an exception.
     * 2. Parses the request body into a JSON object.
     * 3. If a schema is provided, validates the parsed JSON object against the schema using a validator.
     * 4. If the validation passes, returns the parsed JSON object.
     * 5. If the validation fails, formats the validation errors and throws an exception with the error details.
     *
     * @param Request  $request  The PSR-7 Request object containing the request data.
     * @param Response $response The PSR-7 Response object, unused in this method.
     * @param mixed    $schema   The JSON schema to validate against. If false, skips validation.
     *
     * @return object|array The validated and parsed JSON object / array of objects from the request body.
     *
     * @throws Exception If the `Content-Type` header is not `application/json`.
     * @throws ExtendedException If the JSON body is invalid according to the provided schema, including validation error details.
     */

    /*
    public function getValidatedRequestBody(Request $request, Response $response, $schema = false): object|array
    {
        if (($request->getHeader('Content-Type')[0] ?? '') != 'application/json') { throw new \Exception('Content-Type header missing or not set to `application/json`.', 400); };


        try {
            $raw = (string) $request->getBody();
            $doc = json_decode($raw === '' ? 'null' : $raw,false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception('Invalid JSON: '.$e->getMessage(), 400);
        }

        // Only arrays or objects are accepted as top-level JSON
        if (!is_array($doc) && !is_object($doc)) {
            throw new \Exception('JSON root must be an object or array.', 400);
        }
        //$doc = json_decode(json_encode($request->getParsedBody()));
        if ($schema !== false) {
            $validation = $this->validator->validate((object) $doc, $schema);
            if ($validation->isValid()) { $res = $doc; }
            if ($validation->hasError()) {
                $formatter = new ErrorFormatter();
                $error = $validation->error();
                $res = $formatter->formatOutput($error, "basic");
                throw new ExtendedException('Schema validation failed.', 400, details: $res);
            }
        }
        return $doc;
    }
    */

    public function getValidatedRequestBody(
        Request $request,
        Response $response,
        object|false $schema = false
    ): mixed {
        // Accept application/json and */*+json (e.g., application/merge-patch+json)
        $ct = $request->getHeaderLine('Content-Type');
        if ($ct === '' || !preg_match('#^application/(?:[\w.+-]+\+)?json\b#i', $ct)) {
            throw new \Exception('Unsupported Media Type: Content-Type must be application/json.', 415);
        }

        $raw = (string)$request->getBody();
        if ($raw === '') {
            throw new \Exception('Empty request body; valid JSON required.', 400);
        }

        try {
            // $assoc = false => preserve JSON types:
            // [] -> array, {} -> stdClass, "x" -> string, 42 -> int/float, true -> bool, null -> null
            $doc = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new \Exception('Invalid JSON: '.$e->getMessage(), 400);
        }

        if ($schema !== false) {
            // Do NOT cast; pass the decoded JSON as-is
            $validation = $this->validator->validate($doc, $schema);
            if ($validation->hasError()) {
                $formatter = new ErrorFormatter();
                $details = $formatter->formatOutput($validation->error(), 'basic');
                throw new ExtendedException('Schema validation failed.', 400, details: $details);
            }
        }

        return $doc; // mixed: array|object|string|int|float|bool|null
    }

}
