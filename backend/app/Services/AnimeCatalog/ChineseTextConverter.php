<?php

namespace App\Services\AnimeCatalog;

final class ChineseTextConverter
{
    private const SIMPLIFIED_TO_TRADITIONAL = [
        '为' => '為',
        '乐' => '樂',
        '体' => '體',
        '关' => '關',
        '剧' => '劇',
        '动' => '動',
        '华' => '華',
        '发' => '發',
        '后' => '後',
        '国' => '國',
        '学' => '學',
        '实' => '實',
        '师' => '師',
        '广' => '廣',
        '异' => '異',
        '战' => '戰',
        '时' => '時',
        '机' => '機',
        '来' => '來',
        '梦' => '夢',
        '气' => '氣',
        '汉' => '漢',
        '湾' => '灣',
        '爱' => '愛',
        '独' => '獨',
        '画' => '畫',
        '礼' => '禮',
        '种' => '種',
        '经' => '經',
        '网' => '網',
        '联' => '聯',
        '艺' => '藝',
        '见' => '見',
        '观' => '觀',
        '话' => '話',
        '语' => '語',
        '说' => '說',
        '长' => '長',
        '门' => '門',
        '间' => '間',
        '风' => '風',
        '馆' => '館',
        '魔法工房' => '魔法工房',
    ];

    public function toTraditional(string $text): string
    {
        return strtr($text, self::SIMPLIFIED_TO_TRADITIONAL);
    }
}
