<?php

namespace mindtwo\LaravelAiSpark;

use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Providers\Provider;
use Prism\Prism\Embeddings\PendingRequest as EmbeddingsPendingRequest;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;

/**
 * Routes Laravel AI requests to the custom Prism provider registered under the driver name.
 *
 * The stock gateway maps drivers to a hard-coded list of Prism providers, so it cannot
 * resolve custom drivers. Text, streaming, structured output and embeddings all pass
 * through configure(), which makes it the single place to override.
 */
class SparkGateway extends PrismGateway
{
    /**
     * @param  TextPendingRequest|StructuredPendingRequest|EmbeddingsPendingRequest  $prism
     */
    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using(
            $provider->driver(),
            $model,
            array_filter([
                ...$provider->additionalConfiguration(),
                'api_key' => $provider->providerCredentials()['key'] ?? null,
            ]),
        );
    }
}
