<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Services\Auth\JwtService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class ProductionJwtConfigurationTest extends TestCase
{
    #[DataProvider('invalidProductionSecrets')]
    public function test_production_boot_rejects_insecure_jwt_secrets(?string $secret, string $message): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('services.jwt.secret', $secret);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new AppServiceProvider($this->app))->boot();
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidProductionSecrets(): iterable
    {
        yield 'missing' => [null, 'JWT_SECRET must be configured in production.'];
        yield 'blank' => ['   ', 'JWT_SECRET must be configured in production.'];
        yield 'too short' => [str_repeat('a', 31), 'JWT_SECRET must be at least 32 characters in production.'];
        yield 'placeholder' => [
            'replace-with-a-long-random-production-secret-that-is-not-real',
            'JWT_SECRET must not use a placeholder value in production.',
        ];
        yield 'test-only placeholder' => [
            'test-only-jwt-secret-at-least-32-characters',
            'JWT_SECRET must not use a placeholder value in production.',
        ];
    }

    public function test_production_boot_accepts_a_strong_jwt_secret(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('services.jwt.secret', str_repeat('aB3!', 16));

        (new AppServiceProvider($this->app))->boot();

        $this->assertTrue(true);
    }

    public function test_jwt_service_refuses_to_sign_with_an_empty_secret(): void
    {
        config()->set('services.jwt.secret', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET must be configured.');

        app(JwtService::class)->issue(['sub' => 1]);
    }
}
