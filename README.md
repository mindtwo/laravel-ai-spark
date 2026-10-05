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
- **Document attachments** – vLLM accepts no file attachments, so text files are inlined and PDFs are sent as their text layer, as page images, or both
- **Mixed prompts** – images, text files and PDFs in one prompt keep their order, each page image labelled with file and page number
- **Server utilities** – health, version, served models, token counting, context-window checks and batched prompts via the `Spark` facade, plus `php artisan spark:status`
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
- Optional, for PDF text: [`smalot/pdfparser`](https://github.com/smalot/pdfparser) 2.12+
- Optional, for PDF page images: poppler's `pdftoppm` (`brew install poppler`, `apt install poppler-utils`), or the Imagick extension with Ghostscript, and a vision-capable model
- For the [server utilities](#server-utilities) behind a proxy: `/health`, `/version`, `/tokenize` and `/detokenize` must be forwarded next to `/v1/*`

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
| `SPARK_PDF_MODE` | `text` (default), `images` or `both`, see [Attachments](#attachments) |
| `SPARK_PDF_RENDERER` | `auto` (default), `pdftoppm` or `imagick` |
| `SPARK_PDFTOPPM_PATH` | Path to `pdftoppm` if it is not on the `PATH` |
| `SPARK_PDF_MAX_PAGES`, `SPARK_PDF_DPI` | Page limit (20) and resolution (144) for page images |
| `SPARK_TIMEOUT` | Timeout in seconds for the server utilities (120) |
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

### Attachments

vLLM has no `file` content part on any of its APIs, so a document sent as a file is rejected
with a 400. The driver converts documents on the application server before the request leaves:

| Attachment | Handling |
|:-----------|:---------|
| `text/*`, JSON, XML, YAML, CSV | Inlined as text, wrapped in `<document name="…">` |
| PDF | Depends on the PDF mode below |
| DOCX, XLSX and other binary formats | `UnsupportedAttachmentException`: convert to text or Markdown first |
| Files stored at another provider (`Document::fromId()`) | `UnsupportedAttachmentException`: vLLM has no file storage |
| Images, audio, video | Sent as `image_url` / `input_audio` parts; the served model must support them |

Attachments of all kinds can be combined in one prompt. They reach the model in the order they
were given, and every page image is preceded by a label such as `[report.pdf, page 2 of 5]`.

### PDF modes

| Mode | Sends | Needs | Good for |
|:-----|:------|:------|:---------|
| `text` (default) | The text layer | `smalot/pdfparser` | Minutes, contracts, exported articles. Cheap |
| `images` | One JPEG per page | `pdftoppm` or Imagick + Ghostscript, a vision-capable model | Scans, charts, forms, layout |
| `both` | Text layer and page images | Both of the above | Exact quoting plus everything visual |

`text` is blind to scans, charts and table layout, and a PDF without a text layer fails. Page
images cost image tokens per page and one of the server's parallel slots for longer, which is
why `images` and `both` stop at `max_pages` instead of truncating silently.

```bash
composer require smalot/pdfparser
brew install poppler
```

The mode applies per connection (`SPARK_PDF_MODE`) and can be overridden per agent with an
`attachments` provider option, which the driver removes before the request:

```php
public function providerOptions(Lab|string $provider): array
{
    return $provider === 'spark'
        ? ['attachments' => ['pdf' => 'both', 'max_pages' => 40]]
        : [];
}
```

### When an attachment cannot be sent

Every case that cannot work throws `mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException`
**before** any HTTP request, with the reason and the fix in the message: parser or renderer
missing, no text layer in `text` mode, encrypted or damaged PDF, too many pages, unsupported
format. A PDF with embedded images in `text` mode is sent, but a warning is logged that the
images were dropped.

```php
try {
    $response = (new InternalSummaryAgent)->prompt('Summarise the report.', [Document::fromStorage('reports/q3.pdf')]);
} catch (UnsupportedAttachmentException $e) {
    report($e); // nothing was sent to the server
}
```

The exception extends the SDK's `AiException` and is deliberately not failoverable, so
`provider: ['spark', 'anthropic']` never sends a document to a hosted provider behind your back.

### Beyond page images

Page images cover most documents. For very long documents or heavy table extraction, convert
the PDF to Markdown first with [Docling](https://github.com/docling-project/docling)
([docling-serve](https://github.com/docling-project/docling-serve) runs next to vLLM, also on
ARM64) and attach the result as `Document::fromString($markdown, 'text/markdown')`.
[OCRmyPDF](https://github.com/ocrmypdf/OCRmyPDF) adds a text layer to scans so that `text` mode
works. A hosted provider with native PDF support, e.g.
[Anthropic](https://docs.anthropic.com/en/docs/build-with-claude/pdf-support), needs no
infrastructure, but the document leaves your network.

### Server utilities

The `Spark` facade talks to the same server with the same URL, key and headers. Use
`Spark::connection('spark-lab')` for another connection.

```php
use mindtwo\LaravelAiSpark\Facades\Spark;

Spark::health();                    // bool, never throws
Spark::version();                   // "0.23.1"
Spark::models();                    // [['id' => 'nvidia/Qwen3.8-27B-NVFP4', 'max_model_len' => 262144]]
Spark::maxModelLength();            // 262144

Spark::countTokens($text);          // tokens of a plain string
Spark::countTokens($messages);      // tokens of a chat, including the chat template
Spark::fits($text, reserve: 4000);  // room left for a 4,000 token answer?
Spark::tokenize($text)->remaining();
Spark::detokenize([9419, 1814]);    // "Hello world"

// Many short, independent prompts in one request, answers keyed like the input
Spark::batch(
    ['a' => 'I love this product.', 'b' => 'Worst purchase ever.'],
    instructions: 'Answer with one word: positive or negative.',
    options: ['max_tokens' => 5],
); // ['a' => 'positive', 'b' => 'negative']
```

`batch()` uses vLLM's `/v1/chat/completions/batch` and has no tools, structured output or
streaming; use agents for those. Failures throw `SparkServerException`, with a hint when a
proxy blocks the non-`/v1` endpoints.

```bash
php artisan spark:status
php artisan spark:status spark-lab
```

`spark:status` checks reachability, version, served models and whether the configured model is
among them, and which PDF tools are installed. It exits non-zero when the server is down or
the model is not served, so it also works as a deploy or CI check.

### Timeouts

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
| `src/Prism/Maps/MessageMap.php` | Keeps text and media parts in their original order |
| `src/Attachments/DocumentConverter.php` | Turns documents into text and page images, or throws `UnsupportedAttachmentException` |
| `src/Attachments/Renderers/` | PDF page rendering via `pdftoppm` or Imagick, auto-detected |
| `src/Server/SparkServer.php` | Health, version, models, tokenize and batch endpoints; `Facades/Spark.php` and `SparkManager` resolve it per connection |
| `src/Console/SparkStatusCommand.php` | `spark:status` |
| `src/Exceptions/` | `UnsupportedAttachmentException`, `SparkServerException`, one named constructor per case |
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
