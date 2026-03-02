<?php

$config = require __DIR__ . '/config.php';

function redisQueue(array $config): Redis
{
    $redis = new Redis();
    $redis->connect($config['redis']['host'], $config['redis']['port']);
    $redis->select((int)($config['redis']['queue_db'] ?? 2)); // 🔴 QUEUE DB
    $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
    return $redis;
}

function redisCache(array $config): Redis
{
    $redis = new Redis();
    $redis->connect($config['redis']['host'], $config['redis']['port']);
    $redis->select((int)($config['redis']['cache_db'] ?? 1)); // 🟢 CACHE DB
    return $redis;
}