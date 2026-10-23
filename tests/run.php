<?php

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install before running tests.\n");
    exit(2);
}

require $autoload;

$_ENV['APP_ENV'] = 'testing';

$runner = new \Spark\Testing\Runner();
$result = $runner->run(__DIR__, array_slice($argv, 1));

exit($result);
