<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    public function testAccountantCanMutateOwnSigningProfilesRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCanMutateOwnSigningProfileCredentialRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles/7/credentials/certificate', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCanUpdateOwnSigningProfileCredentialRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/signing/profiles/7/credentials/certificate', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testAccountantCannotMutateGlobalSigningSettingsRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/signing', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAccountantCannotMutatePdfOutputSettingsRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/output-settings/invoice', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAccountantCanMutateOwnPdfSigningUserDefaultRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/user-defaults/invoice', 'accountant'),
            $this->okHandler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testReadonlyCannotMutateSigningProfilesRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('POST', '/api/settings/signing/profiles', 'readonly'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testReadonlyCannotMutatePdfSigningUserDefaultRoute(): void
    {
        $response = $this->middleware()->process(
            $this->request('PUT', '/api/settings/pdf-signing/user-defaults/invoice', 'readonly'),
            $this->okHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    private function middleware(): RoleMiddleware
    {
        return new RoleMiddleware(new ResponseFactory());
    }

    private function request(string $method, string $path, string $role): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 10,
                'role' => $role,
            ]);
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
