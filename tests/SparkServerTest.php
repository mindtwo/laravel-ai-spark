<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use mindtwo\LaravelAiSpark\Exceptions\SparkServerException;
use mindtwo\LaravelAiSpark\Facades\Spark;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('ai.providers.spark.url', 'https://spark.test/v1');
    config()->set('ai.providers.spark.headers', ['CF-Access-Client-Id' => 'client-id', 'CF-Access-Client-Secret' => null]);
    config()->set('ai.providers.spark.models.text.default', 'nvidia/test-model');
});

function fakeModels(): array
{
    return ['object' => 'list', 'data' => [['id' => 'nvidia/test-model', 'max_model_len' => 262144]]];
}

it('reports health without throwing', function () {
    Http::fake(['spark.test/health' => Http::response('', 200)]);
    expect(Spark::health())->toBeTrue();

    Http::fake(['spark.test/health' => Http::failedConnection()]);
    expect(Spark::connection()->health())->toBeFalse();
});

it('reads version and models with the connection headers', function () {
    Http::fake([
        'spark.test/version' => Http::response(['version' => '0.23.1']),
        'spark.test/v1/models' => Http::response(fakeModels()),
    ]);

    expect(Spark::version())->toBe('0.23.1')
        ->and(Spark::models())->toBe([['id' => 'nvidia/test-model', 'max_model_len' => 262144]])
        ->and(Spark::maxModelLength())->toBe(262144);

    Http::assertSent(fn (Request $request) => $request->header('CF-Access-Client-Id') === ['client-id']
        && ! $request->hasHeader('CF-Access-Client-Secret'));
});

it('names the served models when the configured one is missing', function () {
    Http::fake(['spark.test/v1/models' => Http::response(fakeModels())]);

    Spark::maxModelLength('other/model');
})->throws(SparkServerException::class, 'does not serve model [other/model]. Served: nvidia/test-model');

it('tokenizes prompts and chats', function () {
    Http::fake(['spark.test/tokenize' => Http::response(['count' => 54, 'max_model_len' => 100, 'tokens' => [1, 2]])]);

    $count = Spark::tokenize([['role' => 'user', 'content' => 'Hello']]);

    expect($count->count)->toBe(54)
        ->and($count->remaining())->toBe(46)
        ->and($count->fits(reserve: 46))->toBeTrue()
        ->and($count->fits(reserve: 47))->toBeFalse()
        ->and(Spark::countTokens('Hello'))->toBe(54)
        ->and(Spark::fits('Hello', reserve: 50))->toBeFalse();

    Http::assertSent(fn (Request $request) => $request['model'] === 'nvidia/test-model'
        && ($request['add_generation_prompt'] ?? false) === true
        && $request['chat_template_kwargs'] === ['enable_thinking' => false]);
    Http::assertSent(fn (Request $request) => ($request['prompt'] ?? null) === 'Hello');
});

it('detokenizes', function () {
    Http::fake(['spark.test/detokenize' => Http::response(['prompt' => 'Hello world'])]);

    expect(Spark::detokenize([9419, 1814]))->toBe('Hello world');
});

it('runs batched prompts and keeps keys and order', function () {
    Http::fake(['spark.test/v1/chat/completions/batch' => Http::response(['choices' => [
        ['index' => 1, 'message' => ['content' => 'negative']],
        ['index' => 0, 'message' => ['content' => 'positive']],
    ]])]);

    $answers = Spark::batch(
        ['first' => 'I love it', 'second' => [['role' => 'user', 'content' => 'I hate it']]],
        instructions: 'Answer positive or negative.',
        options: ['max_tokens' => 5],
    );

    expect($answers)->toBe(['first' => 'positive', 'second' => 'negative']);

    Http::assertSent(fn (Request $request) => $request['max_tokens'] === 5
        && $request['chat_template_kwargs'] === ['enable_thinking' => false]
        && $request['messages'][0] === [
            ['role' => 'system', 'content' => 'Answer positive or negative.'],
            ['role' => 'user', 'content' => 'I love it'],
        ]);
});

it('hints at the proxy when non v1 endpoints are blocked', function () {
    Http::fake(['spark.test/tokenize' => Http::response('Not Found', 404)]);

    Spark::countTokens('Hello');
})->throws(SparkServerException::class, 'only forwards /v1/*, allow /tokenize as well');

it('hints at credentials on 403', function () {
    Http::fake(['spark.test/version' => Http::response(['message' => 'Forbidden'], 403)]);

    Spark::version();
})->throws(SparkServerException::class, 'Cloudflare Access service token');

it('rejects connections that do not use the spark driver', function () {
    config()->set('ai.providers.other', ['driver' => 'openai']);

    Spark::connection('other');
})->throws(SparkServerException::class, 'is not configured with the "spark" driver');

it('reports the status of a connection', function () {
    Http::fake([
        'spark.test/health' => Http::response('', 200),
        'spark.test/version' => Http::response(['version' => '0.23.1']),
        'spark.test/v1/models' => Http::response(fakeModels()),
    ]);

    $this->artisan('spark:status')
        ->expectsOutputToContain('0.23.1')
        ->expectsOutputToContain('nvidia/test-model (262,144 tokens)')
        ->assertSuccessful();
});

it('fails the status check when the configured model is not served', function () {
    config()->set('ai.providers.spark.models.text.default', 'other/model');

    Http::fake([
        'spark.test/health' => Http::response('', 200),
        'spark.test/version' => Http::response(['version' => '0.23.1']),
        'spark.test/v1/models' => Http::response(fakeModels()),
    ]);

    $this->artisan('spark:status')->assertFailed();
});
