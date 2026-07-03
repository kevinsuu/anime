<?php

namespace Tests\Unit;

use App\Services\AnimeCatalog\BangumiTitleMatcher;
use PHPUnit\Framework\TestCase;

final class BangumiTitleMatcherTest extends TestCase
{
    public function test_matches_exact_title(): void
    {
        $matcher = new BangumiTitleMatcher();

        $match = $matcher->matchExact('ハルチカ ～ハルタとチカは青春する～', [
            ['id' => 136213, 'name' => 'ハルチカ ～ハルタとチカは青春する～', 'name_cn' => '春&夏推理事件簿'],
        ]);

        $this->assertSame(136213, $match['id'] ?? null);
    }

    public function test_matches_after_normalizing_tilde_and_whitespace_variants(): void
    {
        $matcher = new BangumiTitleMatcher();

        // Query uses a full-width tilde + no space; candidate uses the wave dash + a space.
        $match = $matcher->matchExact('ハルチカ〜ハルタとチカは青春する〜', [
            ['id' => 136213, 'name' => 'ハルチカ ～ハルタとチカは青春する～', 'name_cn' => ''],
        ]);

        $this->assertSame(136213, $match['id'] ?? null);
    }

    public function test_returns_null_when_no_candidate_matches(): void
    {
        $matcher = new BangumiTitleMatcher();

        $match = $matcher->matchExact('無彩限のファントム・ワールド', [
            ['id' => 466296, 'name' => '胶囊计划·英雄', 'name_cn' => '胶囊计划 英雄'],
        ]);

        $this->assertNull($match);
    }

    public function test_returns_null_when_multiple_candidates_match_ambiguously(): void
    {
        $matcher = new BangumiTitleMatcher();

        $match = $matcher->matchExact('タイトル', [
            ['id' => 1, 'name' => 'タイトル', 'name_cn' => ''],
            ['id' => 2, 'name' => 'タイトル', 'name_cn' => ''],
        ]);

        $this->assertNull($match);
    }

    public function test_returns_null_for_empty_query(): void
    {
        $matcher = new BangumiTitleMatcher();

        $match = $matcher->matchExact('', [
            ['id' => 1, 'name' => 'Something', 'name_cn' => ''],
        ]);

        $this->assertNull($match);
    }

    public function test_matches_via_name_cn_when_japanese_name_differs(): void
    {
        $matcher = new BangumiTitleMatcher();

        $match = $matcher->matchExact('春&夏推理事件簿', [
            ['id' => 136213, 'name' => 'ハルチカ', 'name_cn' => '春&夏推理事件簿'],
        ]);

        $this->assertSame(136213, $match['id'] ?? null);
    }
}
