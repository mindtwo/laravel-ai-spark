<?php

namespace mindtwo\LaravelAiSpark\Prism;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use mindtwo\LaravelAiSpark\Prism\Handlers\Stream;
use mindtwo\LaravelAiSpark\Prism\Handlers\Structured;
use mindtwo\LaravelAiSpark\Prism\Handlers\Text;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Embeddings\Response as EmbeddingsResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Providers\OpenRouter\Handlers\Embeddings;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Prism provider for an OpenAI-compatible vLLM server (`/v1/chat/completions`).
 *
 * Reuses Prism's OpenRouter handlers because they speak plain Chat Completions and
 * forward provider options into the request body, which vLLM needs for extras such
 * as `chat_template_kwargs`. Prism's OpenAI provider is not used because it targets
 * the Responses API.
 */
class Spark extends Provider
{
    use InitializesClient;

    /**
     * @param  array<string, string>  $headers  Extra headers on every request, e.g. Cloudflare Access service token headers.
     * @param  array<string, mixed>  $defaultOptions  Request body defaults, overridden by an agent's provider options.
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly string $url,
        #[\SensitiveParameter] public readonly array $headers = [],
        public readonly array $defaultOptions = [],
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $this->applyDefaultOptions($request);

        return (new Text($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $this->applyDefaultOptions($request);

        return (new Structured($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $this->applyDefaultOptions($request);

        return (new Stream($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return (new Embeddings($this->client($request->clientOptions(), $request->clientRetry())))->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $status = $e->response->getStatusCode();
        $message = data_get($e->response->json(), 'error.message')
            ?? data_get($e->response->json(), 'message')
            ?? 'Unknown error';

        match ($status) {
            401 => throw PrismException::providerResponseError(
                sprintf('Spark rejected the API key (401): %s', $message)
            ),
            403 => throw PrismException::providerResponseError(
                'Spark access denied (403). Check the Cloudflare Access service token and that the request comes from an allowed network.'
            ),
            400, 404, 422 => throw PrismException::providerResponseError(
                sprintf('Spark rejected the request for model [%s] (%d): %s', $model, $status, $message)
            ),
            413 => throw PrismRequestTooLargeException::make('Spark'),
            429 => throw PrismRateLimitedException::make([]),
            502, 503, 504 => throw PrismProviderOverloadedException::make('Spark'),
            default => throw PrismException::providerRequestError($model, $e),
        };
    }

    /**
     * Merge the configured body defaults beneath the request's own provider options.
     */
    protected function applyDefaultOptions(TextRequest|StructuredRequest $request): void
    {
        if ($this->defaultOptions === []) {
            return;
        }

        $request->withProviderOptions(array_replace_recursive(
            $this->defaultOptions,
            $request->providerOptions() ?? [],
        ));
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        return $this->baseClient()
            ->withHeaders($this->headers)
            ->when($this->apiKey, fn (PendingRequest $client) => $client->withToken($this->apiKey))
            ->withOptions(['allow_redirects' => false, ...$options])
            ->withResponseMiddleware($this->failOnRedirect(...))
            ->when($retry !== [], fn (PendingRequest $client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }

    /**
     * Cloudflare Access answers an unauthenticated request with a redirect to its login
     * page instead of an error status. Fail loudly rather than parsing HTML as JSON.
     */
    protected function failOnRedirect(ResponseInterface $response): ResponseInterface
    {
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw PrismException::providerResponseError(sprintf(
                'Spark redirected the request (%d to %s). Cloudflare Access most likely rejected the service token.',
                $status,
                $response->getHeaderLine('Location') ?: 'unknown location',
            ));
        }

        return $response;
    }
}
