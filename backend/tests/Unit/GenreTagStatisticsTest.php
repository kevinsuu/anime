<?php

namespace Tests\Unit;

use App\Services\Shared\GenreTagStatistics;
use PHPUnit\Framework\TestCase;

final class GenreTagStatisticsTest extends TestCase
{
    public function test_counts_genres_and_sorts_by_count_then_name(): void
    {
        $statistics = new GenreTagStatistics;

        $this->assertSame([
            ['tag' => '戀愛', 'count' => 2],
            ['tag' => '戰鬥', 'count' => 2],
            ['tag' => '搞笑', 'count' => 1],
        ], $statistics->summarize([
            ['戀愛', '戰鬥', '漫畫改編'],
            ['戀愛', '搞笑'],
            ['戰鬥', '2季度'],
            null,
        ]));
    }

    public function test_ignores_non_string_values(): void
    {
        $statistics = new GenreTagStatistics;

        $this->assertSame(
            [['tag' => '奇幻', 'count' => 1]],
            $statistics->summarize([['奇幻', null, 123, ['巢狀值']]])
        );
    }
}
