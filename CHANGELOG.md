# Changelog

All notable changes to `laravel-ai-spark` will be documented in this file.

## 1.1.0 - 2026-10-05

- Document attachments are converted before the request, since vLLM rejects `file` content parts. Previously every document attachment failed with a 400.
- Text formats (`text/*`, JSON, XML, YAML, CSV) are inlined.
- PDF modes `text` (text layer via the optional `smalot/pdfparser`), `images` (page images via `pdftoppm` or Imagick) and `both`, per connection or per agent via the `attachments` provider option.
- Text, images and documents keep their order in the request; page images are labelled with file and page.
- `UnsupportedAttachmentException`, thrown before any request with reason and fix: missing parser or renderer, no text layer, encrypted or damaged PDF, too many pages, unsupported format, provider file IDs.
- `Spark` facade for server utilities: `health()`, `version()`, `models()`, `maxModelLength()`, `tokenize()`, `countTokens()`, `fits()`, `detokenize()`, `batch()`; `SparkServerException` on failures.
- `php artisan spark:status` to check a connection.

## 1.0.0 - 2026-10-05

- `spark` driver for the Laravel AI SDK: text, streaming, structured output and embeddings against vLLM's `/v1/chat/completions`.
- Custom headers per connection, e.g. Cloudflare Access service tokens, plus an optional Bearer key.
- Default request body options (`chat_template_kwargs`), overridable per agent.
- Omits `tools` / `tool_choice` when an agent has no tools and omits `structured_outputs`, both rejected by vLLM.
- Clear errors for Cloudflare Access redirects and 401/403 responses.
