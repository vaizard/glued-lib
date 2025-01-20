<?php

declare(strict_types=1);
namespace Glued\Lib;

class Bearer
{
    protected string $tokenCookie;
    protected string $tokenHeader;
    protected string $tokenRegexp;
    protected string $tokenPrefix;

    public function fetchToken($request)
    {
        // Check for token in header and in the cookie
        $header = $request->getHeaderLine($this->tokenHeader);
        if (!empty($header) && preg_match($this->tokenRegexp, $header, $matches)) {
            return $matches[1];
        }

        $cookie = $request->getCookieParams()[$this->tokenCookie] ?? null;
        if ($cookie && preg_match($this->tokenRegexp, $cookie, $matches)) {
            return $matches[1];
        }

        throw new \Exception("Token not found.", 401);
    }

}