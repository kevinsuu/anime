<?php

namespace Tests\Unit;

use App\Services\Seo\AnimeIndexability;
use PHPUnit\Framework\TestCase;

/**
 * Same cases as frontend/test/indexability.test.ts: both sides must agree, or
 * IndexNow pushes pages the frontend serves as noindex.
 */
final class AnimeIndexabilityTest extends TestCase
{
    private function synopsis(int $length): string
    {
        return str_repeat('介', $length);
    }

    public function test_indexes_a_standalone_synopsis_without_other_facts(): void
    {
        $this->assertTrue(AnimeIndexability::isIndexable($this->synopsis(160), null, 0, 0));
    }

    public function test_ignores_whitespace_when_measuring_the_synopsis(): void
    {
        $this->assertFalse(AnimeIndexability::isIndexable($this->synopsis(159)."   \n ", null, 0, 0));
    }

    public function test_indexes_a_shorter_synopsis_backed_by_two_facts(): void
    {
        $this->assertTrue(AnimeIndexability::isIndexable($this->synopsis(60), 12, 1, 0));
        $this->assertTrue(AnimeIndexability::isIndexable($this->synopsis(60), null, 2, 3));
    }

    public function test_keeps_a_shorter_synopsis_with_one_fact_noindex(): void
    {
        $this->assertFalse(AnimeIndexability::isIndexable($this->synopsis(100), 12, 0, 2));
    }

    public function test_keeps_a_too_short_synopsis_noindex_whatever_the_facts(): void
    {
        $this->assertFalse(AnimeIndexability::isIndexable($this->synopsis(59), 12, 3, 10));
        $this->assertFalse(AnimeIndexability::isIndexable(null, 12, 3, 10));
    }
}
