<?php

use Illuminate\Http\Client\Request;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\AiException;
use mindtwo\LaravelAiSpark\SparkProvider;

use function Laravel\Ai\agent;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('ai.providers.spark', [
        'driver' => 'spark',
        'key' => '',
        'url' => 'https://spark.test/v1/',
        'headers' => [
            'CF-Access-Client-Id' => 'client-id',
            'CF-Access-Client-Secret' => 'client-secret',
            'X-Empty' => null,
        ],
        'options' => [
            'chat_template_kwargs' => ['enable_thinking' => false],
        ],
        'models' => [
            'text' => ['default' => 'nvidia/test-model'],
        ],
    ]);
});

function sparkCompletion(string $content): array
{
    return [
        'id' => 'chatcmpl-1',
        'model' => 'nvidia/test-model',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
    ];
}

it('resolves the spark driver through the ai manager', function () {
    expect(Ai::textProvider('spark'))
        ->toBeInstanceOf(SparkProvider::class)
        ->defaultTextModel()->toBe('nvidia/test-model');
});

it('sends chat completions with cloudflare access headers and default options', function () {
    Http::fake([
        'spark.test/v1/chat/completions' => Http::response(sparkCompletion('Hallo')),
    ]);

    $response = agent(instructions: 'Be brief.')->prompt('Hello', provider: 'spark');

    expect($response->text)->toBe('Hallo');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://spark.test/v1/chat/completions'
            && $request->header('CF-Access-Client-Id') === ['client-id']
            && $request->header('CF-Access-Client-Secret') === ['client-secret']
            && ! $request->hasHeader('X-Empty')
            && ! $request->hasHeader('Authorization')
            && $request['model'] === 'nvidia/test-model'
            && $request['chat_template_kwargs'] === ['enable_thinking' => false]
            && ! array_key_exists('tools', $request->data())
            && ! array_key_exists('tool_choice', $request->data());
    });
});

it('sends a bearer token when a key is configured', function () {
    config()->set('ai.providers.spark.key', 'secret-key');

    Http::fake([
        'spark.test/v1/chat/completions' => Http::response(sparkCompletion('Hallo')),
    ]);

    agent(instructions: 'Be brief.')->prompt('Hello', provider: 'spark');

    Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer secret-key']);
});

it('requests structured output without the openrouter only flag', function () {
    Http::fake([
        'spark.test/v1/chat/completions' => Http::response(sparkCompletion('{"score":7}')),
    ]);

    $response = agent(
        instructions: 'Score the text.',
        schema: fn (JsonSchema $schema) => ['score' => $schema->integer()->required()],
    )->prompt('Laravel is great.', provider: 'spark');

    expect($response['score'])->toBe(7);

    Http::assertSent(function (Request $request) {
        return data_get($request->data(), 'response_format.type') === 'json_schema'
            && ! array_key_exists('structured_outputs', $request->data());
    });
});

it('fails with a clear message when cloudflare access redirects to its login page', function () {
    Http::fake([
        'spark.test/v1/chat/completions' => Http::response('', 302, ['Location' => 'https://mindtwo.cloudflareaccess.com/login']),
    ]);

    agent(instructions: 'Be brief.')->prompt('Hello', provider: 'spark');
})->throws(AiException::class, 'Cloudflare Access');
