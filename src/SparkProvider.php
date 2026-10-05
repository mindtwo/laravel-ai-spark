<?php

namespace mindtwo\LaravelAiSpark;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns;
use Laravel\Ai\Providers\Provider;
use LogicException;

/**
 * Laravel AI provider for a self-hosted, OpenAI-compatible vLLM server such as an NVIDIA DGX Spark.
 */
class SparkProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesText;
    use Concerns\HasEmbeddingGateway;
    use Concerns\HasTextGateway;
    use Concerns\StreamsText;

    public function defaultTextModel(): string
    {
        return $this->configuredModel('text.default');
    }

    /**
     * vLLM usually serves a single model, so every tier falls back to the default.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? $this->defaultTextModel();
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->configuredModel('embeddings.default');
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }

    /**
     * vLLM answers an unknown model name with a 404, so fail early with a clearer message.
     */
    protected function configuredModel(string $key): string
    {
        $model = data_get($this->config, 'models.'.$key);

        if (blank($model)) {
            throw new LogicException(sprintf(
                'No model configured for AI provider [%s] at [models.%s]. Set SPARK_MODEL or pass a model explicitly.',
                $this->config['name'] ?? self::class,
                $key,
            ));
        }

        return (string) $model;
    }
}
