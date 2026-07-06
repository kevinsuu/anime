<?php

namespace App\Jobs;

use App\Services\AnimeCatalog\AnimeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class ImportAnimeRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param array<string, mixed> $record
     */
    public function __construct(
        public readonly array $record,
        public readonly string $source,
        public readonly string $batchId,
    ) {
    }

    public function handle(AnimeImportService $service): void
    {
        $service->importRecord($this->record, $this->source);

        Cache::increment("import:{$this->batchId}:success");
    }
}
