<?php

namespace mindtwo\LaravelAiSpark\Prism\Handlers;

use Illuminate\Http\Client\Response;
use mindtwo\LaravelAiSpark\Prism\Concerns\BuildsRequestOptions;
use mindtwo\LaravelAiSpark\Prism\Maps\MessageMap;
use Prism\Prism\Providers\OpenRouter\Handlers\Structured as OpenRouterStructured;
use Prism\Prism\Structured\Request;

/**
 * Structured output via the standard OpenAI `response_format` field.
 *
 * The OpenRouter handler also sends `structured_outputs: true`, which vLLM rejects
 * because it reserves that key for its own structured outputs parameter object.
 */
class Structured extends OpenRouterStructured
{
    use BuildsRequestOptions;

    /**
     * @return array<string, mixed>
     */
    protected function sendRequest(Request $request): array
    {
        /** @var Response $response */
        $response = $this->client->post(
            'chat/completions',
            array_merge([
                'model' => $request->model(),
                'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                'max_tokens' => $request->maxTokens(),
            ], $this->buildRequestOptions($request, [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $request->schema()->name(),
                        'strict' => true,
                        'schema' => $request->schema()->toArray(),
                    ],
                ],
            ]))
        );

        return $response->json() ?? [];
    }
}
