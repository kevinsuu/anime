CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    google_sub VARCHAR(191) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL,
    display_name VARCHAR(160) NULL,
    avatar_url TEXT NULL,
    public_slug VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anime (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    image_url TEXT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_anime_name (name),
    CONSTRAINT fk_anime_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS anime_aliases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anime_id BIGINT UNSIGNED NOT NULL,
    alias VARCHAR(160) NOT NULL,
    INDEX idx_anime_alias (alias),
    CONSTRAINT fk_anime_aliases_anime FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_anime_list_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    anime_id BIGINT UNSIGNED NOT NULL,
    watched BOOLEAN NOT NULL DEFAULT FALSE,
    rating TINYINT UNSIGNED NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_anime (user_id, anime_id),
    INDEX idx_user_list_user (user_id),
    INDEX idx_user_list_anime (anime_id),
    CONSTRAINT chk_rating_range CHECK (rating IS NULL OR (rating >= 1 AND rating <= 10)),
    CONSTRAINT fk_user_list_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_list_anime FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
