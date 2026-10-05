<?php

namespace mindtwo\LaravelAiSpark\Tests;

use Laravel\Ai\AiServiceProvider;
use mindtwo\LaravelAiSpark\SparkServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            AiServiceProvider::class,
            SparkServiceProvider::class,
        ];
    }
}
