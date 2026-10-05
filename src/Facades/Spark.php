<?php

namespace mindtwo\LaravelAiSpark\Facades;

use Illuminate\Support\Facades\Facade;
use mindtwo\LaravelAiSpark\Server\SparkManager;
use mindtwo\LaravelAiSpark\Server\SparkServer;
use mindtwo\LaravelAiSpark\Server\TokenCount;

/**
 * @method static SparkServer connection(?string $name = null)
 * @method static bool health()
 * @method static string version()
 * @method static list<array{id: string, max_model_len: int|null}> models()
 * @method static string model()
 * @method static int maxModelLength(?string $model = null)
 * @method static TokenCount tokenize(string|array $input, ?string $model = null)
 * @method static int countTokens(string|array $input, ?string $model = null)
 * @method static bool fits(string|array $input, int $reserve = 0, ?string $model = null)
 * @method static string detokenize(array $tokens, ?string $model = null)
 * @method static array batch(array $prompts, ?string $instructions = null, array $options = [], ?string $model = null)
 *
 * @see SparkManager
 * @see SparkServer
 */
class Spark extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SparkManager::class;
    }
}
