<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\SessionManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Guards all non-auth routes: rejects unauthenticated requests with the login
 * page and validates that the session has a usable CSRF token.
 *
 * On success, the CSRF token is attached as request attribute 'csrfToken' so
 * controllers can access it without touching the session again.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionManager $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->session->isAuthenticated()) {
            return $this->loginPage();
        }

        $csrfToken = $this->session->getCsrfToken();
        if (!is_string($csrfToken) || $csrfToken === '') {
            $this->session->logout();
            return $this->loginPage();
        }

        return $handler->handle(
            $request->withAttribute('csrfToken', $csrfToken)
        );
    }

    private function loginPage(): ResponseInterface
    {
        $response = new Response(200);
        $error    = null;
        ob_start();
        include dirname(__DIR__) . '/Views/login.php';
        $response->getBody()->write((string)ob_get_clean());
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
