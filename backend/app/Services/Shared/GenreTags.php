<?php

namespace App\Services\Shared;

final class GenreTags
{
    // 與 frontend/app/composables/useSeasonalCatalog.ts 的 SOURCE_TAGS / isGenreTag() 保持一致
    // 若其中一份修改（新增/移除排除規則），需同步更新另一份
    private const SOURCE_TAGS = ['新作', '續作', '漫畫改編', '小說改編', '原創作品', '遊戲改編', '跨季續播'];

    public static function isGenreTag(string $tag): bool
    {
        return ! in_array($tag, self::SOURCE_TAGS, true) && ! preg_match('/^\d+季度/u', $tag);
    }
}
