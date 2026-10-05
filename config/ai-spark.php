<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spark Connection
    |--------------------------------------------------------------------------
    |
    | This connection is registered as the "spark" provider of the Laravel AI
    | SDK, unless config/ai.php already defines "ai.providers.spark". Add
    | further entries with 'driver' => 'spark' there for more servers.
    |
    */

    'driver' => 'spark',

    /*
    | OpenAI-compatible base URL of the vLLM server, including the /v1 suffix.
    */

    'url' => env('SPARK_URL', 'http://localhost:8000/v1'),

    /*
    | Bearer token. Only needed when vLLM (or a proxy in front of it) checks
    | an API key, e.g. vLLM started with --api-key.
    */

    'key' => env('SPARK_API_KEY', ''),

    /*
    | Extra headers sent with every request. Empty values are dropped, so the
    | Cloudflare Access headers below are harmless when not configured.
    */

    'headers' => [
        'CF-Access-Client-Id' => env('SPARK_CF_ACCESS_CLIENT_ID'),
        'CF-Access-Client-Secret' => env('SPARK_CF_ACCESS_CLIENT_SECRET'),
    ],

    /*
    | Default request body options, merged beneath an agent's own provider
    | options. Qwen3 and similar models think before answering unless the
    | chat template is told otherwise.
    */

    'options' => [
        'chat_template_kwargs' => [
            'enable_thinking' => (bool) env('SPARK_ENABLE_THINKING', false),
        ],
    ],

    /*
    | vLLM accepts no file attachments, so documents are converted before
    | the request. PDFs: "text" sends the text layer (needs the optional
    | smalot/pdfparser), "images" renders every page for a vision-capable
    | model, "both" sends text and page images. Rendering uses poppler's
    | pdftoppm or Imagick with Ghostscript; "auto" picks the first found.
    | Agents can override this with an `attachments` provider option.
    */

    'attachments' => [
        'pdf' => env('SPARK_PDF_MODE', 'text'),
        'renderer' => env('SPARK_PDF_RENDERER', 'auto'),
        'pdftoppm' => env('SPARK_PDFTOPPM_PATH', 'pdftoppm'),
        'max_pages' => (int) env('SPARK_PDF_MAX_PAGES', 20),
        'dpi' => (int) env('SPARK_PDF_DPI', 144),
    ],

    /*
    | Timeout in seconds for the server utilities (health, tokenize, batch).
    */

    'timeout' => (int) env('SPARK_TIMEOUT', 120),

    /*
    | vLLM usually serves a single model, so cheapest and smartest fall back
    | to the default. The name must match vLLM's --served-model-name.
    */

    'models' => [
        'text' => [
            'default' => env('SPARK_MODEL'),
        ],
        'embeddings' => [
            'default' => env('SPARK_EMBEDDINGS_MODEL'),
            'dimensions' => (int) env('SPARK_EMBEDDINGS_DIMENSIONS', 1024),
        ],
    ],

];
