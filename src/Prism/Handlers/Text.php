<?php

namespace mindtwo\LaravelAiSpark\Prism\Handlers;

use Illuminate\Http\Client\Response;
use mindtwo\LaravelAiSpark\Prism\Concerns\BuildsRequestOptions;
use mindtwo\LaravelAiSpark\Prism\Maps\MessageMap;
use Prism\Prism\Providers\OpenRouter\Handlers\Text as OpenRouterText;
use Prism\Prism\Text\Request;

class Text extends OpenRouterText
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
            ], $this->buildRequestOptions($request))
        );

        return $response->json() ?? [];
    }
}
