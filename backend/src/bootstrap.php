<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $path = __DIR__ . '/' . $class . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function request_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new HttpException(400, 'bad_json', '請求 JSON 格式錯誤');
    }

    return $data;
}

function random_slug(int $bytes = 8): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
