<?php
declare(strict_types = 1);

namespace Glued\Lib\Middleware;

use Middlewares\Utils\Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TrailingSlash implements MiddlewareInterface
{
    /**
     * @var bool Add or remove the slash
     */
    private $trailingSlash;

    /**
     * @var bool Include the port in the redirect URI
     */
    private $includePort;

    /**
     * @var ResponseFactoryInterface
     */
    private $responseFactory;

    /**
     * Configure whether add or remove the slash and optionally include the port.
     */
    public function __construct(bool $trailingSlash = false, bool $includePort = true)
    {
        $this->trailingSlash = $trailingSlash;
        $this->includePort = $includePort;
    }

    /**
     * Whether returns a 301 response to the new path.
     */
    public function redirect(ResponseFactoryInterface $responseFactory = null): self
    {
        $this->responseFactory = $responseFactory ?: Factory::getResponseFactory();

        return $this;
    }

    /**
     * Process a request and return a response.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $this->normalize($uri->getPath());

        if ($this->responseFactory && ($uri->getPath() !== $path)) {
            $locationUri = $uri->withPath($path);
            // Optionally exclude the port in the Location header
            if (!$this->includePort) {
                $locationUri = $locationUri->withPort(null);
            }

            return $this->responseFactory->createResponse(301)
                ->withHeader('Location', (string) $locationUri);
        }

        return $handler->handle($request->withUri($uri->withPath($path)));
    }

    /**
     * Normalize the trailing slash.
     */
    private function normalize(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (strlen($path) > 1) {
            if ($this->trailingSlash) {
                if (substr($path, -1) !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
                    return $path.'/';
                }
            } else {
                return rtrim($path, '/');
            }
        }

        return $path;
    }
}