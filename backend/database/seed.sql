INSERT INTO anime (name, description, image_url, source, created_by_user_id, created_at, updated_at)
SELECT '葬送的芙莉蓮', '魔王被討伐後，長壽精靈魔法使芙莉蓮重新理解人類與旅途意義的故事。', 'https://cdn.myanimelist.net/images/anime/1015/138006.jpg', 'seed', NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM anime WHERE name = '葬送的芙莉蓮');

INSERT INTO anime (name, description, image_url, source, created_by_user_id, created_at, updated_at)
SELECT '孤獨搖滾！', '害羞的吉他少女加入樂團，在舞台與日常中慢慢找到自己的位置。', 'https://cdn.myanimelist.net/images/anime/1448/127956.jpg', 'seed', NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM anime WHERE name = '孤獨搖滾！');

INSERT INTO anime (name, description, image_url, source, created_by_user_id, created_at, updated_at)
SELECT '排球少年!!', '少年們以排球為中心互相競爭、成長，挑戰全國舞台。', 'https://cdn.myanimelist.net/images/anime/7/76014.jpg', 'seed', NULL, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM anime WHERE name = '排球少年!!');

INSERT INTO anime_aliases (anime_id, alias)
SELECT id, 'Frieren' FROM anime WHERE name = '葬送的芙莉蓮'
UNION ALL
SELECT id, 'Bocchi the Rock' FROM anime WHERE name = '孤獨搖滾！'
UNION ALL
SELECT id, 'Haikyu' FROM anime WHERE name = '排球少年!!';
