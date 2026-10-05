<?php

namespace mindtwo\LaravelAiSpark\Prism\Handlers;

use mindtwo\LaravelAiSpark\Prism\Concerns\BuildsRequestOptions;
use Prism\Prism\Providers\OpenRouter\Handlers\Text as OpenRouterText;

class Text extends OpenRouterText
{
    use BuildsRequestOptions;
}
