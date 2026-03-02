<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/redis.php';
require __DIR__ . '/render_card.php';

use Aws\S3\S3Client;

$pdo = createPdo($config);

// --------------------------------------------------
// S3 CLIENT — create once
// --------------------------------------------------
$s3 = new S3Client([
    'version'     => 'latest',
    'region'      => 'auto',
    'endpoint'    => $config['r2']['endpoint'],
    'credentials' => [
        'key'    => $config['r2']['accessKey'],
        'secret' => $config['r2']['secretKey'],
    ],
]);

// 🔴 QUEUE redis (DB 2)
$queueRedis = redisQueue($config);

// 🟢 CACHE redis (DB 0)
$cacheRedis = redisCache($config);

echo "🟢 Image worker started\n";

while (true) {

    echo "⏳ Waiting for job...\n";

    try {
        $data = $queueRedis->brPop([$config['redis']['queue']], 0);
    } catch (RedisException $e) {
        echo "⚠️ Redis lost, reconnecting...\n";
        sleep(2);
        $queueRedis = redisQueue($config);
        $cacheRedis = redisCache($config);
        continue;
    }

    if (!isset($data[1])) {
        continue;
    }

    $job = json_decode($data[1], true);

    if (!$job || !isset($job['card_id'])) {
        echo "⚠️ Invalid job\n";
        continue;
    }

    echo "🎨 Processing card_id={$job['card_id']}\n";

    try {
        renderCard($job, $pdo, $s3, $cacheRedis, $config);
        echo "✅ Card {$job['card_id']} rendered\n";
    } catch (Throwable $e) {
        echo "❌ Card {$job['card_id']} failed: {$e->getMessage()}\n";
        sleep(1);
    }
}