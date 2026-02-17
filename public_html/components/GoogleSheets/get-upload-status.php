<?php

use Atte\Utils\Locker;

header('Content-Type: application/json');

$locker = new Locker('gs_upload.lock');
$isRunning = $locker->isLocked();

echo json_encode([
    'success' => true,
    'status' => $isRunning ? 'running' : 'idle'
]);
