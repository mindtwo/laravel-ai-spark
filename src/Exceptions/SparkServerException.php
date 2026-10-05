<?php

namespace mindtwo\LaravelAiSpark\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Throwable;

/**
 * A server utility call (health, tokenize, batch, ...) failed.
 */
class SparkServerException extends AiException
{
    public static function requestFailed(string $connection, string $endpoint, int $status, string $message, ?Throwable $previous = null): self
    {
        $hint = match (true) {
            in_array($status, [401, 403], true) => ' Check the API key or Cloudflare Access service token and that the request comes from an allowed network.',
            $status === 404 && ! str_starts_with($endpoint, '/v1/') => sprintf(
                ' If the proxy in front of vLLM only forwards /v1/*, allow %s as well.',
                $endpoint,
            ),
            default => '',
        };

        return new self(sprintf(
            'Spark connection [%s]: %s failed (%d): %s.%s',
            $connection,
            $endpoint,
            $status,
            $message,
            $hint,
        ), $status, $previous);
    }

    public static function unreachable(string $connection, string $endpoint, Throwable $previous): self
    {
        return new self(sprintf(
            'Spark connection [%s]: %s is unreachable: %s',
            $connection,
            $endpoint,
            $previous->getMessage(),
        ), previous: $previous);
    }

    /**
     * @param  list<string>  $served
     */
    public static function modelNotServed(string $connection, string $model, array $served): self
    {
        return new self(sprintf(
            'Spark connection [%s] does not serve model [%s]. Served: %s. Check SPARK_MODEL against vLLM\'s --served-model-name.',
            $connection,
            $model ?: '(none configured)',
            $served === [] ? 'none' : implode(', ', $served),
        ));
    }

    public static function unknownConnection(string $connection): self
    {
        return new self(sprintf(
            'AI provider [%s] is not configured with the "spark" driver. Define ai.providers.%s with \'driver\' => \'spark\'.',
            $connection,
            $connection,
        ));
    }
}
