<?php

namespace Spark\Relay\Console;

use Spark\Console\Prompt;
use Spark\Relay\RestartSignal;
use Throwable;

class RestartCommand
{
    public function handle(array $args): int
    {
        try {
            (new RestartSignal(RestartSignal::configuredPath()))->request();
        } catch (Throwable $error) {
            Prompt::error($error->getMessage());
            return 1;
        }
        Prompt::info('Relay restart requested. The process supervisor must start the replacement daemon.');
        return 0;
    }
}
