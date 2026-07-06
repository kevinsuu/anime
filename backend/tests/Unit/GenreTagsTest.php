<?php

namespace Tests\Unit;

use App\Services\Shared\GenreTags;
use PHPUnit\Framework\TestCase;

final class GenreTagsTest extends TestCase
{
    public function test_returns_true_for_genre_tags(): void
    {
        $this->assertTrue(GenreTags::isGenreTag('戀愛'));
        $this->assertTrue(GenreTags::isGenreTag('戰鬥'));
    }

    public function test_returns_false_for_source_type_tags(): void
    {
        $this->assertFalse(GenreTags::isGenreTag('新作'));
        $this->assertFalse(GenreTags::isGenreTag('續作'));
        $this->assertFalse(GenreTags::isGenreTag('漫畫改編'));
        $this->assertFalse(GenreTags::isGenreTag('小說改編'));
        $this->assertFalse(GenreTags::isGenreTag('原創作品'));
        $this->assertFalse(GenreTags::isGenreTag('遊戲改編'));
        $this->assertFalse(GenreTags::isGenreTag('跨季續播'));
    }

    public function test_returns_false_for_season_count_tags(): void
    {
        $this->assertFalse(GenreTags::isGenreTag('2季度'));
        $this->assertFalse(GenreTags::isGenreTag('12季度'));
    }
}
