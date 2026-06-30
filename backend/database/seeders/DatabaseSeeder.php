<?php

namespace Database\Seeders;

use App\Models\Anime;
use App\Models\AnimeAlias;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'name' => '葬送的芙莉蓮',
                'description' => '魔王被討伐後，長壽精靈魔法使芙莉蓮重新理解人類與旅途意義的故事。',
                'image_url' => 'https://cdn.myanimelist.net/images/anime/1015/138006.jpg',
                'alias' => 'Frieren',
            ],
            [
                'name' => '孤獨搖滾！',
                'description' => '害羞的吉他少女加入樂團，在舞台與日常中慢慢找到自己的位置。',
                'image_url' => 'https://cdn.myanimelist.net/images/anime/1448/127956.jpg',
                'alias' => 'Bocchi the Rock',
            ],
            [
                'name' => '排球少年!!',
                'description' => '少年們以排球為中心互相競爭、成長，挑戰全國舞台。',
                'image_url' => 'https://cdn.myanimelist.net/images/anime/7/76014.jpg',
                'alias' => 'Haikyu',
            ],
        ];

        foreach ($items as $item) {
            $anime = Anime::query()->firstOrCreate([
                'name' => $item['name'],
            ], [
                'description' => $item['description'],
                'image_url' => $item['image_url'],
                'source' => 'seed',
            ]);

            AnimeAlias::query()->firstOrCreate([
                'anime_id' => $anime->id,
                'alias' => $item['alias'],
            ]);
        }
    }
}
