<?php

declare(strict_types=1);

namespace PigAntigravity\Test;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Pig\CodingAgent\Config;
use PigAntigravity\DailyRateLimits;

/**
 * Every test starts with no 429 counted on any account: the counts are a file under the run's
 * HOME, and a test that is answered with 429s would otherwise use up the limit of the ones after.
 */
final class ResetsDailyLimits implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class () implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                DailyRateLimits::useFiles(null);
                DailyRateLimits::useAccounts(null);
                $file = Config::home() . '/antigravity-429.json';

                if (is_file($file)) {
                    unlink($file);
                }
            }
        });
    }
}
