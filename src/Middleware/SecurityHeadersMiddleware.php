<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security-related response headers to every response.
 * Applied globally so no route can forget them.
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; "
                . "script-src 'self' https://unpkg.com; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "font-src https://fonts.gstatic.com; "
                . "img-src 'self' data: https://avatars.githubusercontent.com; "
                . "connect-src 'self' https://unpkg.com; "
                . "frame-ancestors 'none'; "
                . "base-uri 'self'; "
                . "form-action 'self'; "
                . "object-src 'none'"
            );
    }
}
