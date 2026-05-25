<?php

declare(strict_types=1);

final class AnimeListController
{
    public function __construct(
        private readonly AnimeListRepository $lists,
        private readonly UserRepository $users,
        private readonly AuthMiddleware $auth,
    ) {
    }

    public function me(): array
    {
        return ['status' => 200, 'body' => ['user' => $this->users->findById($this->auth->userId())]];
    }

    public function index(): array
    {
        return ['status' => 200, 'body' => ['items' => $this->lists->listForUser($this->auth->userId())]];
    }

    public function add(): array
    {
        $data = request_json();
        $animeId = (int) ($data['animeId'] ?? 0);
        if ($animeId <= 0) {
            throw new HttpException(422, 'validation_failed', '缺少動漫 ID', ['animeId' => 'required']);
        }

        return ['status' => 201, 'body' => ['item' => $this->lists->add($this->auth->userId(), $animeId)]];
    }

    public function update(int $itemId): array
    {
        return ['status' => 200, 'body' => ['item' => $this->lists->update($itemId, $this->auth->userId(), request_json())]];
    }

    public function delete(int $itemId): array
    {
        $this->lists->delete($itemId, $this->auth->userId());
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public function publicList(string $slug): array
    {
        $user = $this->users->findByPublicSlug($slug);
        if ($user === null) {
            throw new HttpException(404, 'public_list_not_found', '找不到公開清單');
        }

        return ['status' => 200, 'body' => ['user' => $user, 'items' => $this->lists->listForUser((int) $user['id'])]];
    }

    public function regenerateSlug(): array
    {
        return ['status' => 200, 'body' => ['user' => $this->users->regenerateSlug($this->auth->userId())]];
    }
}
