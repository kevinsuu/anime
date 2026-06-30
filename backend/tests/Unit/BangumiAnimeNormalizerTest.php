<?php

namespace Tests\Unit;

use App\Services\AnimeCatalog\BangumiAnimeNormalizer;
use App\Services\AnimeCatalog\ChineseTextConverter;
use PHPUnit\Framework\TestCase;

final class BangumiAnimeNormalizerTest extends TestCase
{
    public function test_it_normalizes_bangumi_subjects_for_traditional_chinese_catalog(): void
    {
        $normalizer = new BangumiAnimeNormalizer(new ChineseTextConverter());

        $record = $normalizer->normalize([
            'id' => 377130,
            'url' => 'https://bgm.tv/subject/377130',
            'type' => 2,
            'name' => 'とんがり帽子のアトリエ',
            'name_cn' => '尖帽子的魔法工房',
            'summary' => '魔法工房簡介',
            'air_date' => '2026-04-06',
            'eps' => 13,
            'images' => ['large' => 'https://lain.bgm.tv/pic/cover/l/27/ff/377130_wDU1x.jpg'],
        ]);

        $this->assertSame('bangumi', $record['provider']);
        $this->assertSame('377130', $record['externalId']);
        $this->assertSame(2026, $record['seasonYear']);
        $this->assertSame('spring', $record['seasonCode']);
        $this->assertSame('尖帽子的魔法工房', $record['primaryTitle']);
        $this->assertSame('とんがり帽子のアトリエ', $record['titles']['ja-JP']);
    }
}
