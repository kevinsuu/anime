<?php

declare(strict_types=1);

final class AnimeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(string $query): array
    {
        $term = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.id, a.name, a.description, a.image_url, a.source
             FROM anime a
             LEFT JOIN anime_aliases aa ON aa.anime_id = a.id
             WHERE ? = "" OR a.name LIKE ? OR aa.alias LIKE ?
             ORDER BY a.name
             LIMIT 50'
        );
        $stmt->execute([$query, $term, $term]);

        return $stmt->fetchAll();
    }

    public function create(array $data, int $userId): array
    {
        $this->validate($data);
        $stmt = $this->pdo->prepare(
            'INSERT INTO anime (name, description, image_url, source, created_by_user_id, created_at, updated_at) VALUES (?, ?, ?, "manual", ?, NOW(), NOW())'
        );
        $stmt->execute([
            trim($data['name']),
            trim($data['description'] ?? ''),
            trim($data['imageUrl'] ?? ''),
            $userId,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function find(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, description, image_url, source FROM anime WHERE id = ?');
        $stmt->execute([$id]);
        $anime = $stmt->fetch();
        if (!$anime) {
            throw new HttpException(404, 'anime_not_found', '找不到動漫資料');
        }

        return $anime;
    }

    private function validate(array $data): void
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        $imageUrl = trim((string) ($data['imageUrl'] ?? ''));

        if ($name === '' || strlen($name) > 480) {
            $errors['name'] = '名稱必填且不可超過 160 字';
        }

        if ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
            $errors['imageUrl'] = '圖片 URL 格式錯誤';
        }

        if ($imageUrl !== '' && !str_starts_with($imageUrl, 'http://') && !str_starts_with($imageUrl, 'https://')) {
            $errors['imageUrl'] = '圖片 URL 必須使用 HTTP 或 HTTPS';
        }

        if ($errors !== []) {
            throw new HttpException(422, 'validation_failed', '動漫資料驗證失敗', $errors);
        }
    }
}
