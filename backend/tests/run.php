<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = new Config(
    'sqlite::memory:',
    '',
    '',
    'test-secret',
    3600,
    'client-id',
    ['http://localhost:5173'],
    true,
);

$jwt = new JwtService($config);
$token = $jwt->issue(['sub' => '42', 'email' => 'user@example.com']);
$claims = $jwt->verify($token);
assert_true($claims['sub'] === '42', 'JWT should preserve subject');
assert_true($claims['email'] === 'user@example.com', 'JWT should preserve email');

$verifier = new GoogleTokenVerifier($config);
$user = $verifier->verify('dev:dev@example.com');
assert_true($user['email'] === 'dev@example.com', 'dev auth bypass should use provided email');
assert_true(str_starts_with($user['sub'], 'dev-'), 'dev auth bypass should create stable dev subject');

try {
    $jwt->verify($token . 'tampered');
    assert_true(false, 'tampered JWT should fail');
} catch (HttpException $exception) {
    assert_true($exception->status === 401, 'tampered JWT should return 401');
}

echo "Backend tests passed\n";
