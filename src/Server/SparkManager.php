<?php

namespace mindtwo\LaravelAiSpark\Server;

use Illuminate\Contracts\Config\Repository;
use mindtwo\LaravelAiSpark\Exceptions\SparkServerException;
use mindtwo\LaravelAiSpark\SparkServiceProvider;

/**
 * Resolves SparkServer instances per `ai.providers.*` connection and proxies to the default one.
 *
 * @mixin SparkServer
 */
class SparkManager
{
    /**
     * @var array<string, SparkServer>
     */
    protected array $servers = [];

    public function __construct(protected Repository $config) {}

    public function connection(?string $name = null): SparkServer
    {
        $name ??= SparkServiceProvider::DRIVER;

        $config = $this->config->get('ai.providers.'.$name);

        if (! is_array($config) || ($config['driver'] ?? null) !== SparkServiceProvider::DRIVER) {
            throw SparkServerException::unknownConnection($name);
        }

        return $this->servers[$name] ??= new SparkServer($name, $config);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->{$method}(...$arguments);
    }
}
