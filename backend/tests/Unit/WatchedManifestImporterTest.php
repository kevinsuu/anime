<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserAnimeListItem;
use App\Services\AnimeCatalog\AnimeImportService;
use App\Services\AnimeCatalog\WatchedManifestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WatchedManifestImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_skips_when_owner_email_not_configured(): void
    {
        config(['services.mylist.owner_email' => '']);

        $result = app(WatchedManifestImporter::class)->sync();

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, User::query()->count());
    }

    public function test_sync_creates_owner_and_marks_manifest_anime_watched(): void
    {
        config(['services.mylist.owner_email' => 'owner@example.com']);

        // watched.json 內固定存在的一筆(Fate/stay night,bangumi 290)
        app(AnimeImportService::class)->importRecord([
            'season' => '200601', 'season_year' => 2006, 'season_code' => 'winter',
            'title_zh' => 'Fate/stay night', 'title_ja' => 'Fate/stay night',
            'external_ids' => ['bangumi' => '290'],
        ], 'mylist');

        $result = app(WatchedManifestImporter::class)->sync();

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['marked']);

        $owner = User::query()->where('email', 'owner@example.com')->first();
        $this->assertNotNull($owner);
        $this->assertStringStartsWith('seed:', $owner->google_sub);

        $item = UserAnimeListItem::query()->where('user_id', $owner->id)->first();
        $this->assertNotNull($item);
        $this->assertTrue((bool) $item->watched);

        // 冪等:再跑一次不會重複建立,也不覆寫既有項目
        $item->update(['watched' => false, 'rating' => 8]);
        $again = app(WatchedManifestImporter::class)->sync();
        $this->assertSame(0, $again['marked']);
        $this->assertSame(1, $again['existing']);
        $item->refresh();
        $this->assertFalse((bool) $item->watched);
        $this->assertSame(8, $item->rating);
    }
}
