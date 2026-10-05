<?php

namespace mindtwo\LaravelAiSpark\Prism\Maps;

use BackedEnum;
use Prism\Prism\Providers\OpenRouter\Maps\AudioMapper;
use Prism\Prism\Providers\OpenRouter\Maps\DocumentMapper;
use Prism\Prism\Providers\OpenRouter\Maps\ImageMapper;
use Prism\Prism\Providers\OpenRouter\Maps\MessageMap as OpenRouterMessageMap;
use Prism\Prism\Providers\OpenRouter\Maps\VideoMapper;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Media\Video;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * Maps user messages with their parts in the original order.
 *
 * The OpenRouter map puts all text first and every image after it, so the model cannot tell
 * which label belongs to which image. vLLM accepts interleaved parts, which keeps a page label
 * directly in front of its page image and attachments in the order they were given.
 */
class MessageMap extends OpenRouterMessageMap
{
    protected function mapUserMessage(UserMessage $message): void
    {
        $cacheType = $message->providerOptions('cacheType');
        $cacheControl = $cacheType ? ['type' => $cacheType instanceof BackedEnum ? $cacheType->value : $cacheType] : null;

        $parts = [];
        $text = null;

        foreach ($message->additionalContent as $part) {
            if ($part instanceof Text) {
                $text = ($text ?? '').$part->text;

                continue;
            }

            if ($text !== null) {
                $parts[] = $this->textPart($text, $cacheControl);
                [$text, $cacheControl] = [null, null];
            }

            $parts[] = match (true) {
                $part instanceof Image => (new ImageMapper($part))->toPayload(),
                $part instanceof Audio => (new AudioMapper($part))->toPayload(),
                $part instanceof Video => (new VideoMapper($part))->toPayload(),
                $part instanceof Document => (new DocumentMapper($part))->toPayload(),
                default => null,
            };
        }

        if ($text !== null) {
            $parts[] = $this->textPart($text, $cacheControl);
        }

        $this->mappedMessages[] = [
            'role' => 'user',
            'content' => array_values(array_filter($parts)),
        ];
    }

    /**
     * Cache control, if requested, goes on the first text part only.
     *
     * @param  array{type: mixed}|null  $cacheControl
     * @return array<string, mixed>
     */
    protected function textPart(string $text, ?array $cacheControl): array
    {
        return array_filter(['type' => 'text', 'text' => $text, 'cache_control' => $cacheControl]);
    }
}
