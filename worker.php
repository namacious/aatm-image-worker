<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';          // defines createPdo() + $pdo
require __DIR__ . '/render_card.php'; // defines renderCard()

use Aws\S3\S3Client;

$pdo = createPdo($config);                  // ← now $config exists, safe to call
// --------------------------------------------------
// REDIS CONNECT
// --------------------------------------------------
function connectRedis(array $config): Redis
{
    $redis = new Redis();
    $redis->connect($config['redis']['host'], $config['redis']['port']);
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
    return $redis;
}

// --------------------------------------------------
// PDO PING — reconnects if MySQL dropped the connection.
// Long-running workers will eventually hit MySQL's wait_timeout
// (default 8h, but often lower on RDS/Aurora). This keeps it alive.
// --------------------------------------------------
function ensurePdo(PDO $pdo, array $config): PDO
{
    try {
        $pdo->query('SELECT 1');
        return $pdo; // connection healthy, reuse it
    } catch (PDOException $e) {
        echo "⚠️  DB connection lost, reconnecting... ({$e->getMessage()})\n";
        return createPdo($config); // fresh connection
    }
}

// --------------------------------------------------
// S3 CLIENT — created ONCE at startup, reused for every job.
// Saves ~50-100ms per job vs constructing inside renderCard().
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

$redis = connectRedis($config);

echo "🟢 Image worker started\n";

// --------------------------------------------------
// WORKER LOOP
// --------------------------------------------------
while (true) {

    echo "⏳ Waiting for job...\n";

    // --------------------------------------------------
    // 1. BLOCKING POP — with reconnect on Redis drop
    // --------------------------------------------------
    try {
        $data = $redis->brPop([$config['redis']['queue']], 0);
    } catch (RedisException $e) {
        echo "⚠️  Redis connection lost, reconnecting... ({$e->getMessage()})\n";
        sleep(2);
        $redis = connectRedis($config);
        continue;
    }

    if (!$data || !isset($data[1])) {
        echo "⚠️  Empty response from Redis, retrying...\n";
        sleep(1);
        continue;
    }

    // --------------------------------------------------
    // 2. DECODE PAYLOAD
    // --------------------------------------------------
    $job = json_decode($data[1], true);

    if (!$job || !isset($job['card_id'])) {
        echo "⚠️  Invalid job payload, skipping\n";
        continue;
    }

    // --------------------------------------------------
    // 3. PING DB before doing any work — reconnect if dropped
    // --------------------------------------------------
    $pdo = ensurePdo($pdo, $config);

    echo "🎨 Processing card_id={$job['card_id']}\n";

    // --------------------------------------------------
    // 4. RENDER — $s3 passed in so renderCard() doesn't
    //    construct a new client on every single job
    // --------------------------------------------------
    try {
    renderCard($job, $pdo, $s3, $config);
        echo "✅ Card {$job['card_id']} rendered successfully\n";
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'lock not acquired')) {
            echo "⚠️  Card {$job['card_id']} skipped: {$e->getMessage()}\n";
        } else {
            echo "❌ Card {$job['card_id']} failed (retry scheduled if count < 3): {$e->getMessage()}\n";
        }
        sleep(1);
    } catch (Throwable $e) {
        echo "❌ Card {$job['card_id']} failed (retry scheduled if count < 3): {$e->getMessage()}\n";
        sleep(1);
    }
}