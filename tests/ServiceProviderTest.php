<?php

use Laravel\Ai\Ai;
use mindtwo\LaravelAiSpark\SparkServiceProvider;

it('registers the package config as the default spark connection', function () {
    expect(config('ai.providers.spark'))
        ->driver->toBe('spark')
        ->url->toBe('http://localhost:8000/v1');
});

it('keeps a spark connection defined by the application', function () {
    config()->set('ai.providers.spark', ['driver' => 'spark', 'url' => 'https://app.test/v1']);

    (new SparkServiceProvider(app()))->register();

    expect(config('ai.providers.spark.url'))->toBe('https://app.test/v1');
});

it('fails early when no model is configured', function () {
    config()->set('ai.providers.spark.models.text.default', null);

    Ai::textProvider('spark')->defaultTextModel();
})->throws(LogicException::class, 'No model configured for AI provider [spark]');
