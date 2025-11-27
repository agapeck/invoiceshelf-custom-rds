<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Bootstrap the Laravel application for testing
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
