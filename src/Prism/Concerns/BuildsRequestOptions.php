<?php

namespace mindtwo\LaravelAiSpark\Prism\Concerns;

use Illuminate\Support\Arr;
use Prism\Prism\Providers\OpenRouter\Maps\ToolChoiceMap;
use Prism\Prism\Providers\OpenRouter\Maps\ToolMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;

/**
 * Builds the optional Chat Completions parameters for vLLM.
 *
 * Replaces the OpenRouter variant, which sends `tools: []` when no tools are set.
 * OpenRouter accepts that, vLLM rejects it with a 400.
 */
trait BuildsRequestOptions
{
    /**
     * @param  array<string, mixed>  $additional
     * @return array<string, mixed>
     */
    protected function buildRequestOptions(TextRequest|StructuredRequest $request, array $additional = []): array
    {
        $tools = ToolMap::map($request->tools());

        return Arr::whereNotNull(array_merge(
            $request->providerOptions() ?? [],
            [
                'temperature' => $request->temperature(),
                'top_p' => $request->topP(),
                'max_tokens' => $request->maxTokens(),
                'tools' => $tools === [] ? null : $tools,
                'tool_choice' => $tools === [] ? null : ToolChoiceMap::map($request->toolChoice()),
            ],
            $additional,
        ));
    }
}
