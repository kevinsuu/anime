<?php

declare(strict_types=1);

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertGoogleUser(array $googleUser): array
    {
        $existing = $this->findByGoogleSub($googleUser['sub']);
        if ($existing !== null) {
            $stmt = $this->pdo->prepare('UPDATE users SET email = ?, display_name = ?, avatar_url = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$googleUser['email'], $googleUser['name'], $googleUser['picture'], $existing['id']]);
            return $this->findById((int) $existing['id']);
        }

        do {
            $slug = random_slug();
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE public_slug = ?');
            $stmt->execute([$slug]);
        } while ($stmt->fetch() !== false);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, display_name, avatar_url, public_slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$googleUser['sub'], $googleUser['email'], $googleUser['name'], $googleUser['picture'], $slug]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, display_name, avatar_url, public_slug, created_at, updated_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new HttpException(404, 'user_not_found', '找不到使用者');
        }

        return $user;
    }

    public function findByPublicSlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, display_name, avatar_url, public_slug FROM users WHERE public_slug = ?');
        $stmt->execute([$slug]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function regenerateSlug(int $userId): array
    {
        do {
            $slug = random_slug();
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE public_slug = ?');
            $stmt->execute([$slug]);
        } while ($stmt->fetch() !== false);

        $stmt = $this->pdo->prepare('UPDATE users SET public_slug = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$slug, $userId]);

        return $this->findById($userId);
    }

    private function findByGoogleSub(string $googleSub): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE google_sub = ?');
        $stmt->execute([$googleSub]);
        $user = $stmt->fetch();

        return $user ?: null;
    }
}
