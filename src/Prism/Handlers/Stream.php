<?php

namespace mindtwo\LaravelAiSpark\Prism\Handlers;

use mindtwo\LaravelAiSpark\Prism\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenRouter\Handlers\Stream as OpenRouterStream;

class Stream extends OpenRouterStream
{
    use BuildsRequestOptions;
}
