<?php

declare(strict_types=1);

/**
 * requeue-cron.php
 *
 * Finds failed jobs (pending + retry_count > 0) and re-pushes them to Redis.
 * Processes in batches so it can drain large backlogs within a single cron run.
 *
 * Schedule: every 1 minute
 *   * * * * * /usr/local/bin/php /app/requeue-cron.php >> /var/log/requeue-cron.log 2>&1
 */

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php'; // defines createPdo()

$pdo = createPdo($config);

$redis = new Redis();
$redis->connect($config['redis']['host'], (int)($config['redis']['port'] ?? 6379));

if (!empty($config['redis']['password'])) {
    $redis->auth($config['redis']['password']);
}

// --------------------------------------------------
// BATCH CONFIG
//
// Processes up to MAX_TOTAL jobs per cron run, in
// chunks of BATCH_SIZE to avoid locking the DB for
// too long on a single query.
//
// At 1000 jobs/run, every minute = 60,000 requeues/hour.
// Raise MAX_TOTAL if you need faster backlog draining.
// --------------------------------------------------
$BATCH_SIZE = 200;   // rows per SELECT/UPDATE
$MAX_TOTAL  = 1000;  // max jobs to requeue per cron run

$totalQueued  = 0;
$totalSkipped = 0;

echo sprintf('[requeue-cron] %s — starting' . PHP_EOL, date('Y-m-d H:i:s'));

while ($totalQueued + $totalSkipped < $MAX_TOTAL) {

    // --------------------------------------------------
    // FIND RETRYABLE CARDS
    // --------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT id, retry_count, job_payload
        FROM   cards
        WHERE  card_status = 'pending'
          AND  retry_count  BETWEEN 1 AND 2
          AND  job_payload  IS NOT NULL
          AND  updated_at   < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ORDER  BY updated_at ASC
        LIMIT  :limit
    ");
    $stmt->bindValue(':limit', $BATCH_SIZE, PDO::PARAM_INT);
    $stmt->execute();
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // No more eligible rows — we're done
    if (empty($cards)) {
        break;
    }

    foreach ($cards as $card) {

        // Atomic touch — prevents double-queue if two cron processes overlap
        $lock = $pdo->prepare("
            UPDATE cards
            SET    updated_at = NOW()
            WHERE  id          = :id
              AND  card_status = 'pending'
              AND  retry_count BETWEEN 1 AND 2
              AND  updated_at  < DATE_SUB(NOW(), INTERVAL 60 SECOND)
        ");
        $lock->execute([':id' => $card['id']]);

        if ($lock->rowCount() === 0) {
            $totalSkipped++;
            continue;
        }

        $redis->rPush($config['redis']['queue'], $card['job_payload']);
        $totalQueued++;

        echo sprintf(
            '[requeue-cron] %s — queued card_id=%d (retry_count=%d)' . PHP_EOL,
            date('Y-m-d H:i:s'),
            $card['id'],
            $card['retry_count']
        );
    }

    // If we got fewer rows than batch size, there are no more rows to process
    if (count($cards) < $BATCH_SIZE) {
        break;
    }
}

echo sprintf(
    '[requeue-cron] %s — done. queued=%d skipped=%d' . PHP_EOL,
    date('Y-m-d H:i:s'),
    $totalQueued,
    $totalSkipped
);