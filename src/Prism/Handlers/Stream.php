<?php

namespace mindtwo\LaravelAiSpark\Prism\Handlers;

use Illuminate\Http\Client\Response;
use mindtwo\LaravelAiSpark\Prism\Concerns\BuildsRequestOptions;
use mindtwo\LaravelAiSpark\Prism\Maps\MessageMap;
use Prism\Prism\Providers\OpenRouter\Handlers\Stream as OpenRouterStream;
use Prism\Prism\Text\Request;

class Stream extends OpenRouterStream
{
    use BuildsRequestOptions;

    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this
            ->client
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                    'max_tokens' => $request->maxTokens(),
                ], $this->buildRequestOptions($request, [
                    'stream_options' => ['include_usage' => true],
                ]))
            );

        return $response;
    }
}
