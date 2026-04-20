<?php

require_once __DIR__.'/../vendor/autoload.php';

// Load pest.php so uses() bindings are registered before test discovery
if (file_exists(__DIR__.'/../pest.php')) {
    require_once __DIR__.'/../pest.php';
}
