<?php
$config = require __DIR__ . '/config.php';

$redis = new Redis();
$redis->connect($config['redis']['host'], $config['redis']['port']);
