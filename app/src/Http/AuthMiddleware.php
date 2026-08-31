<?php

declare(strict_types=1);

namespace Flownatic\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Wpuszcza dalej tylko zalogowanych; reszte odsyla na logowanie.
 *
 * POC ma jedno konto, wiec nie ma tu rol ani uprawnien - jedynym pytaniem
 * jest "czy sesja zawiera user_id".
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly string $loginUrl,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (isset($_SESSION['user_id'])) {
            return $handler->handle($request);
        }

        // Zapamietujemy, dokad user chcial trafic, zeby po zalogowaniu
        // wrocil tam, a nie zawsze na dashboard.
        $_SESSION['po_zalogowaniu'] = (string) $request->getUri()->getPath();

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $this->loginUrl);
    }
}
