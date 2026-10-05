<?php

namespace mindtwo\LaravelAiSpark;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use mindtwo\LaravelAiSpark\Attachments\AttachmentOptions;
use mindtwo\LaravelAiSpark\Attachments\DocumentConverter;
use mindtwo\LaravelAiSpark\Console\SparkStatusCommand;
use mindtwo\LaravelAiSpark\Prism\Spark;
use mindtwo\LaravelAiSpark\Server\SparkManager;
use Prism\Prism\PrismManager;

/**
 * Registers the `spark` driver with Laravel AI and the matching provider with Prism.
 *
 * Any `ai.providers.*` entry with `'driver' => 'spark'` resolves through this driver,
 * so several self-hosted vLLM servers can be configured side by side.
 */
class SparkServiceProvider extends ServiceProvider
{
    public const DRIVER = 'spark';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-spark.php', 'ai-spark');

        $this->registerDefaultConnection();

        $this->app->singleton(SparkManager::class, fn (Application $app): SparkManager => new SparkManager($app->make('config')));

        $this->callAfterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend(self::DRIVER, fn (Application $app, array $config): SparkProvider => new SparkProvider(
                new SparkGateway($app->make(Dispatcher::class)),
                $config,
                $app->make(Dispatcher::class),
            ));
        });

        $this->callAfterResolving(PrismManager::class, function (PrismManager $manager): void {
            $manager->extend(self::DRIVER, fn (Application $app, array $config): Spark => new Spark(
                apiKey: (string) ($config['api_key'] ?? ''),
                url: rtrim((string) ($config['url'] ?? ''), '/'),
                headers: array_filter($config['headers'] ?? [], filled(...)),
                defaultOptions: $config['options'] ?? [],
                attachments: AttachmentOptions::fromArray($config['attachments'] ?? []),
                documents: $app->make(DocumentConverter::class),
            ));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-spark.php' => config_path('ai-spark.php'),
            ], 'ai-spark-config');

            $this->commands([SparkStatusCommand::class]);
        }
    }

    /**
     * Expose the package connection as `ai.providers.spark` unless the app defines its own.
     */
    protected function registerDefaultConnection(): void
    {
        $config = $this->app->make('config');

        if (! $config->has('ai.providers.'.self::DRIVER)) {
            $config->set('ai.providers.'.self::DRIVER, $config->get('ai-spark'));
        }
    }
}
