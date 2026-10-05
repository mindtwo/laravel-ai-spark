# Changelog

All notable changes to `laravel-ai-spark` will be documented in this file.

## 1.0.0 - unreleased

- `spark` driver for the Laravel AI SDK: text, streaming, structured output and embeddings against vLLM's `/v1/chat/completions`.
- Custom headers per connection, e.g. Cloudflare Access service tokens, plus an optional Bearer key.
- Default request body options (`chat_template_kwargs`), overridable per agent.
- Omits `tools` / `tool_choice` when an agent has no tools and omits `structured_outputs`, both rejected by vLLM.
- Clear errors for Cloudflare Access redirects and 401/403 responses.
