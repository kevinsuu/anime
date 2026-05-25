<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = Config::fromEnv();
$response = new Response();

$response->cors($config);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $response->json(['ok' => true]);
}

try {
    $pdo = Database::connect($config);
    $router = new Router($_SERVER['REQUEST_METHOD'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

    $auth = new AuthController($config, new UserRepository($pdo), new GoogleTokenVerifier($config), new JwtService($config));
    $anime = new AnimeController(new AnimeRepository($pdo), new AuthMiddleware(new JwtService($config)));
    $lists = new AnimeListController(
        new AnimeListRepository($pdo),
        new UserRepository($pdo),
        new AuthMiddleware(new JwtService($config))
    );

    $router->post('/auth/google', fn () => $auth->google());
    $router->get('/me', fn () => $lists->me());
    $router->get('/anime', fn () => $anime->index());
    $router->post('/anime', fn () => $anime->create());
    $router->get('/my/anime-list', fn () => $lists->index());
    $router->post('/my/anime-list', fn () => $lists->add());
    $router->patch('/my/anime-list/{id}', fn (array $params) => $lists->update((int) $params['id']));
    $router->delete('/my/anime-list/{id}', fn (array $params) => $lists->delete((int) $params['id']));
    $router->get('/public/lists/{slug}', fn (array $params) => $lists->publicList($params['slug']));
    $router->post('/me/share-slug/regenerate', fn () => $lists->regenerateSlug());

    $result = $router->dispatch();
    $response->json($result['body'], $result['status']);
} catch (HttpException $exception) {
    $response->json([
        'code' => $exception->errorCode,
        'message' => $exception->getMessage(),
        'details' => $exception->details,
    ], $exception->status);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $response->json([
        'code' => 'server_error',
        'message' => '伺服器發生未預期錯誤',
    ], 500);
}
