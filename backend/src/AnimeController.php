<?php

declare(strict_types=1);

final class AnimeController
{
    public function __construct(private readonly AnimeRepository $anime, private readonly AuthMiddleware $auth)
    {
    }

    public function index(): array
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        return ['status' => 200, 'body' => ['items' => $this->anime->search($query)]];
    }

    public function create(): array
    {
        $userId = $this->auth->userId();
        $created = $this->anime->create(request_json(), $userId);
        return ['status' => 201, 'body' => ['item' => $created]];
    }
}
