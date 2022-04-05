<?php
declare(strict_types=1);
namespace Glued\Lib\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Writes the time taken to generate the response into the Time-Taken header.
 */

class TimerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $taken = microtime(true) - $start;
        return $response->withHeader('Time-Taken', $taken);
    }
}