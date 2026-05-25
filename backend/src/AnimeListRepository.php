<?php

declare(strict_types=1);

final class AnimeListRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uli.id, uli.watched, uli.rating, uli.note, uli.created_at, uli.updated_at,
                    a.id AS anime_id, a.name, a.description, a.image_url
             FROM user_anime_list_items uli
             JOIN anime a ON a.id = uli.anime_id
             WHERE uli.user_id = ?
             ORDER BY uli.updated_at DESC, uli.id DESC'
        );
        $stmt->execute([$userId]);

        return array_map([$this, 'formatItem'], $stmt->fetchAll());
    }

    public function add(int $userId, int $animeId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO user_anime_list_items (user_id, anime_id, watched, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())'
            );
            $stmt->execute([$userId, $animeId]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException(409, 'already_in_list', '此動漫已在你的清單中');
            }
            throw $exception;
        }

        return $this->findOwned((int) $this->pdo->lastInsertId(), $userId);
    }

    public function update(int $itemId, int $userId, array $data): array
    {
        $fields = [];
        $values = [];

        if (array_key_exists('watched', $data)) {
            $fields[] = 'watched = ?';
            $values[] = filter_var($data['watched'], FILTER_VALIDATE_BOOL) ? 1 : 0;
        }

        if (array_key_exists('rating', $data)) {
            $rating = $data['rating'];
            if ($rating !== null && (!is_int($rating) || $rating < 1 || $rating > 10)) {
                throw new HttpException(422, 'validation_failed', '評價必須是 1 到 10 或空值', ['rating' => 'range']);
            }
            $fields[] = 'rating = ?';
            $values[] = $rating;
        }

        if (array_key_exists('note', $data)) {
            $fields[] = 'note = ?';
            $values[] = trim((string) $data['note']);
        }

        if ($fields === []) {
            throw new HttpException(422, 'validation_failed', '沒有可更新的欄位');
        }

        $fields[] = 'updated_at = NOW()';
        $values[] = $itemId;
        $values[] = $userId;

        $stmt = $this->pdo->prepare('UPDATE user_anime_list_items SET ' . implode(', ', $fields) . ' WHERE id = ? AND user_id = ?');
        $stmt->execute($values);
        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'list_item_not_found', '找不到清單項目');
        }

        return $this->findOwned($itemId, $userId);
    }

    public function delete(int $itemId, int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_anime_list_items WHERE id = ? AND user_id = ?');
        $stmt->execute([$itemId, $userId]);
        if ($stmt->rowCount() === 0) {
            throw new HttpException(404, 'list_item_not_found', '找不到清單項目');
        }
    }

    private function findOwned(int $itemId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT uli.id, uli.watched, uli.rating, uli.note, uli.created_at, uli.updated_at,
                    a.id AS anime_id, a.name, a.description, a.image_url
             FROM user_anime_list_items uli
             JOIN anime a ON a.id = uli.anime_id
             WHERE uli.id = ? AND uli.user_id = ?'
        );
        $stmt->execute([$itemId, $userId]);
        $item = $stmt->fetch();
        if (!$item) {
            throw new HttpException(404, 'list_item_not_found', '找不到清單項目');
        }

        return $this->formatItem($item);
    }

    private function formatItem(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'watched' => (bool) $row['watched'],
            'rating' => $row['rating'] === null ? null : (int) $row['rating'],
            'note' => $row['note'],
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
            'anime' => [
                'id' => (int) $row['anime_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'imageUrl' => $row['image_url'],
            ],
        ];
    }
}
