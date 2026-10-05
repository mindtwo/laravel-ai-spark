<?php

namespace mindtwo\LaravelAiSpark\Server;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use mindtwo\LaravelAiSpark\Concerns\FailsOnRedirect;
use mindtwo\LaravelAiSpark\Exceptions\SparkServerException;
use Prism\Prism\Exceptions\PrismException;

/**
 * vLLM server utilities beyond text generation: health, version, models, token counting
 * and batched chat completions. Uses the same URL, key and headers as the AI provider.
 */
class SparkServer
{
    use FailsOnRedirect;

    /**
     * @param  array<string, mixed>  $config  The `ai.providers.*` entry of the connection.
     */
    public function __construct(
        public readonly string $connection,
        protected array $config,
    ) {}

    /**
     * Whether the server is up. Never throws.
     */
    public function health(): bool
    {
        try {
            return $this->client(timeout: 10)->get($this->serverUrl('/health'))->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function version(): string
    {
        return (string) $this->get('/version')->json('version');
    }

    /**
     * @return list<array{id: string, max_model_len: int|null}>
     */
    public function models(): array
    {
        $models = $this->get('/v1/models')->json('data');

        return array_values(array_map(fn (array $model): array => [
            'id' => (string) $model['id'],
            'max_model_len' => isset($model['max_model_len']) ? (int) $model['max_model_len'] : null,
        ], is_array($models) ? $models : []));
    }

    /**
     * The configured default text model of this connection.
     */
    public function model(): string
    {
        return (string) data_get($this->config, 'models.text.default', '');
    }

    /**
     * Context window of the given or configured model, in tokens.
     */
    public function maxModelLength(?string $model = null): int
    {
        $model ??= $this->model();
        $served = $this->models();

        foreach ($served as $candidate) {
            if ($candidate['id'] === $model && $candidate['max_model_len'] !== null) {
                return $candidate['max_model_len'];
            }
        }

        throw SparkServerException::modelNotServed($this->connection, $model, array_column($served, 'id'));
    }

    /**
     * Tokenize a prompt string, or a chat (list of ['role' => ..., 'content' => ...]) including its chat template.
     *
     * @param  string|list<array{role: string, content: mixed}>  $input
     */
    public function tokenize(string|array $input, ?string $model = null): TokenCount
    {
        $payload = is_string($input)
            ? ['prompt' => $input]
            : [
                'messages' => $input,
                'add_generation_prompt' => true,
                ...array_filter(['chat_template_kwargs' => data_get($this->config, 'options.chat_template_kwargs')]),
            ];

        $data = $this->post('/tokenize', ['model' => $model ?? $this->model(), ...$payload])->json();

        return new TokenCount(
            count: (int) ($data['count'] ?? 0),
            maxModelLength: (int) ($data['max_model_len'] ?? 0),
            tokens: array_values(array_map('intval', (array) ($data['tokens'] ?? []))),
        );
    }

    /**
     * @param  string|list<array{role: string, content: mixed}>  $input
     */
    public function countTokens(string|array $input, ?string $model = null): int
    {
        return $this->tokenize($input, $model)->count;
    }

    /**
     * Whether the input plus $reserve tokens for the answer fit into the model's context window.
     *
     * @param  string|list<array{role: string, content: mixed}>  $input
     */
    public function fits(string|array $input, int $reserve = 0, ?string $model = null): bool
    {
        return $this->tokenize($input, $model)->fits($reserve);
    }

    /**
     * @param  list<int>  $tokens
     */
    public function detokenize(array $tokens, ?string $model = null): string
    {
        return (string) $this->post('/detokenize', ['model' => $model ?? $this->model(), 'tokens' => $tokens])->json('prompt');
    }

    /**
     * Run several independent prompts in one request (vLLM's /v1/chat/completions/batch).
     *
     * Useful for bulk jobs such as classifying many short texts. No tools, structured output
     * or streaming; for those use agents. Answers are returned in the order of the prompts.
     *
     * @param  array<array-key, string|list<array{role: string, content: mixed}>>  $prompts  Strings or full message lists.
     * @param  array<string, mixed>  $options  Extra body options, e.g. max_tokens or temperature.
     * @return array<array-key, string>
     */
    public function batch(array $prompts, ?string $instructions = null, array $options = [], ?string $model = null): array
    {
        if ($prompts === []) {
            return [];
        }

        $keys = array_keys($prompts);

        $conversations = array_map(fn (string|array $prompt): array => [
            ...($instructions !== null ? [['role' => 'system', 'content' => $instructions]] : []),
            ...(is_string($prompt) ? [['role' => 'user', 'content' => $prompt]] : $prompt),
        ], array_values($prompts));

        $response = $this->post('/v1/chat/completions/batch', array_replace_recursive(
            (array) ($this->config['options'] ?? []),
            $options,
            ['model' => $model ?? $this->model(), 'messages' => $conversations],
        ));

        $answers = [];

        foreach ((array) $response->json('choices') as $choice) {
            $answers[(int) data_get($choice, 'index')] = (string) data_get($choice, 'message.content', '');
        }

        return array_combine($keys, array_map(
            fn (int $index): string => $answers[$index] ?? '',
            array_keys($keys),
        ));
    }

    protected function get(string $endpoint): Response
    {
        return $this->send('get', $endpoint);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $endpoint, array $payload): Response
    {
        return $this->send('post', $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $endpoint, array $payload = []): Response
    {
        try {
            return $this->client()->{$method}($this->serverUrl($endpoint), $payload)->throw();
        } catch (RequestException $e) {
            throw SparkServerException::requestFailed(
                $this->connection,
                $endpoint,
                $e->response->status(),
                (string) ($e->response->json('error.message') ?? $e->response->json('message') ?? $e->response->reason()),
                $e,
            );
        } catch (ConnectionException|PrismException $e) {
            throw SparkServerException::unreachable($this->connection, $endpoint, $e);
        }
    }

    protected function client(?int $timeout = null): PendingRequest
    {
        $key = (string) ($this->config['key'] ?? '');

        return Http::acceptJson()
            ->timeout($timeout ?? (int) ($this->config['timeout'] ?? 120))
            ->withHeaders(array_filter($this->config['headers'] ?? [], filled(...)))
            ->when($key !== '', fn (PendingRequest $client) => $client->withToken($key))
            ->withOptions(['allow_redirects' => false])
            ->withResponseMiddleware($this->failOnRedirect(...));
    }

    /**
     * vLLM serves /health, /version and /tokenize next to /v1, so strip the /v1 suffix of the provider URL.
     */
    protected function serverUrl(string $endpoint): string
    {
        $base = rtrim((string) ($this->config['url'] ?? ''), '/');

        if (! str_starts_with($endpoint, '/v1/')) {
            $base = (string) preg_replace('#/v1$#', '', $base);
        } else {
            $endpoint = substr($endpoint, 3);
        }

        return $base.$endpoint;
    }
}
