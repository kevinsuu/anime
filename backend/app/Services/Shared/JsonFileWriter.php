<?php

namespace App\Services\Shared;

use RuntimeException;

final class JsonFileWriter
{
    public function write(string $path, mixed $data, int $flags = 0): void
    {
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new RuntimeException("Failed to encode JSON for {$path}: ".json_last_error_msg());
        }
        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Failed to write {$path}");
        }
    }
}
