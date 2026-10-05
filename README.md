[![mindtwo GmbH](https://www.mindtwo.de/downloads/doodles/github/repository-header.png)](https://www.mindtwo.de/)

# Laravel AI Spark

A `spark` driver for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk) that talks to a
self-hosted, OpenAI-compatible [vLLM](https://docs.vllm.ai/) server, such as an NVIDIA DGX Spark.
Agents use it like any other provider. Requests go to vLLM's `/v1/chat/completions` endpoint and
can carry extra headers, for example a [Cloudflare Access service token](https://developers.cloudflare.com/cloudflare-one/identity/service-tokens/)
when the server sits behind Cloudflare Zero Trust.

## Features

- **Laravel AI provider** – text, streaming, structured output and embeddings through the regular agent API
- **Custom headers per connection** – e.g. `CF-Access-Client-Id` / `CF-Access-Client-Secret`; empty values are dropped
- **Optional Bearer key** – for vLLM started with `--api-key` or a key-checking proxy in front of it
- **Default request options** – `chat_template_kwargs` and other vLLM extras, overridable per agent
- **vLLM-safe payloads** – omits `tools` / `tool_choice` when an agent has no tools and never sends OpenRouter's `structured_outputs` flag; vLLM rejects both
- **Clear errors** – Cloudflare Access login redirects and 401/403 responses fail with a readable message instead of a JSON parse error
- **Multiple servers** – every `ai.providers.*` entry with `'driver' => 'spark'` uses this driver

## Tech Stack

| Layer | Technology | Docs |
|:------|:-----------|:-----|
| Runtime | PHP 8.3+ | [PHP](https://www.php.net/docs.php) |
| Framework | Laravel 12 and 13 | [Laravel](https://laravel.com/docs) |
| AI | Laravel AI SDK 0.3, Prism 0.99 | [Laravel AI SDK](https://laravel.com/docs/ai-sdk), [Prism](https://prismphp.com) |
| Server | vLLM (OpenAI-compatible API) | [vLLM](https://docs.vllm.ai/) |
| Testing | Pest 4, Orchestra Testbench | [Pest](https://pestphp.com/docs), [Testbench](https://packages.tools/testbench) |
| Code quality | Pint, Larastan (level 8) | [Pint](https://laravel.com/docs/pint), [Larastan](https://github.com/larastan/larastan) |

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13 with `laravel/ai` installed
- A reachable vLLM server exposing `/v1/chat/completions`
- For tool calls: vLLM started with `--enable-auto-tool-choice` and a `--tool-call-parser` matching the model

## Installation

```bash
composer require mindtwo/laravel-ai-spark
```

The service provider is auto-discovered and registers a `spark` connection from the package
config, so setting the environment variables is enough:

```env
SPARK_URL="https://llm.example.com/v1"
SPARK_MODEL="nvidia/Qwen3.8-27B-NVFP4"
SPARK_CF_ACCESS_CLIENT_ID=
SPARK_CF_ACCESS_CLIENT_SECRET=
SPARK_API_KEY=
SPARK_ENABLE_THINKING=false
```

| Variable | Purpose |
|:---------|:--------|
| `SPARK_URL` | Base URL including `/v1`. Defaults to `http://localhost:8000/v1` |
| `SPARK_MODEL` | Must match vLLM's `--served-model-name`. Required; the provider fails early without it |
| `SPARK_CF_ACCESS_CLIENT_ID`, `SPARK_CF_ACCESS_CLIENT_SECRET` | Cloudflare Access service token. Leave empty when the server is not behind Access |
| `SPARK_API_KEY` | Bearer token, only if vLLM or a proxy checks one |
| `SPARK_ENABLE_THINKING` | Passed as `chat_template_kwargs.enable_thinking`. Off by default for faster, shorter answers |
| `SPARK_EMBEDDINGS_MODEL`, `SPARK_EMBEDDINGS_DIMENSIONS` | Only when the server also serves an embedding model |

To change headers or default options beyond the variables, publish the config:

```bash
php artisan vendor:publish --tag="ai-spark-config"
```

If `config/ai.php` already defines `ai.providers.spark`, the package keeps that definition and
does not register its own.

## Usage

Select the provider per agent with the `#[Provider]` attribute, or per call:

```php
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

use function Laravel\Ai\agent;

#[Provider('spark')]
#[Timeout(180)]
class InternalSummaryAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Summarise the given text in three sentences.';
    }
}

$summary = (new InternalSummaryAgent)->prompt($text)->text;

// Ad hoc, with failover to a hosted provider when the server is busy
agent(instructions: 'Be brief.')->prompt('Hello', provider: ['spark', 'anthropic']);
```

The SDK fails over only on rate limiting (429) and overload, which this driver maps from 502,
503 and 504. A 401/403 (for example a request from outside the allowed network) and connection
errors are thrown as they are and do not fall back.

Agent provider options are merged over the configured defaults, so a single agent can turn on
reasoning or set sampling parameters vLLM understands:

```php
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;

class DeepAnalysisAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function providerOptions(Lab|string $provider): array
    {
        return $provider === 'spark'
            ? ['chat_template_kwargs' => ['enable_thinking' => true], 'top_k' => 20]
            : [];
    }
}
```

A second server is an additional entry in `config/ai.php`:

```php
'providers' => [
    'spark-lab' => [
        'driver' => 'spark',
        'url' => env('SPARK_LAB_URL'),
        'key' => env('SPARK_LAB_API_KEY', ''),
        'headers' => [],
        'models' => ['text' => ['default' => env('SPARK_LAB_MODEL')]],
    ],
],
```

Self-hosted servers usually have few parallel slots and are slower than hosted APIs. The SDK's
default request timeout is 60 seconds; set `#[Timeout]` on agents that run against Spark.

## Architecture

| Path | Purpose |
|:-----|:--------|
| `src/SparkServiceProvider.php` | Registers the `spark` driver with Laravel AI and Prism, merges and publishes the config |
| `src/SparkProvider.php` | Laravel AI provider: text, streaming and embeddings capabilities, model resolution |
| `src/SparkGateway.php` | Routes requests to the custom Prism provider; the stock gateway only knows built-in drivers |
| `src/Prism/Spark.php` | Prism provider: HTTP client, headers, Bearer key, default options, error mapping |
| `src/Prism/Handlers/` | Text, stream and structured handlers built on Prism's OpenRouter handlers |
| `src/Prism/Concerns/BuildsRequestOptions.php` | vLLM-compatible request options (no empty `tools`) |
| `config/ai-spark.php` | Default `spark` connection |

The handlers reuse Prism's OpenRouter implementation because it speaks plain Chat Completions
and forwards provider options into the request body. Prism's OpenAI provider is not used since
it targets the Responses API. Both `laravel/ai` and Prism are pre-1.0, so their internals can
change between minor versions; the constraints in `composer.json` are kept narrow on purpose.

## Development

### Testing

```bash
composer test
composer test -- --filter=structured
```

The tests fake all HTTP traffic with `Http::fake()`; no server is needed.

### Code quality

```bash
composer format     # Pint
composer analyse    # Larastan
```

## Releasing

1. Add the changes to `CHANGELOG.md` under a new version heading.
2. Tag the release: `git tag vX.Y.Z && git push --tags`.
3. Before widening the `laravel/ai` or `prism-php/prism` constraints, run the tests against the new versions.

## Conventions

- Semantic Versioning; breaking changes only in major releases
- Code style is enforced by Pint, static analysis by Larastan level 8
- New behaviour needs a Pest test that asserts the request payload sent to vLLM

## License

MIT, see [LICENSE](LICENSE.md).

## Support

Maintained by the development team at [mindtwo GmbH](https://www.mindtwo.de/).

[![Back to the top](https://www.mindtwo.de/downloads/doodles/github/repository-footer.png)](#)
