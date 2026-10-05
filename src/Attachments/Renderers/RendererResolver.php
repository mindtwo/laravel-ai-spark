<?php

namespace mindtwo\LaravelAiSpark\Attachments\Renderers;

use mindtwo\LaravelAiSpark\Attachments\AttachmentOptions;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;

/**
 * Picks the PDF renderer: the configured one, or with `auto` the first that is installed.
 */
class RendererResolver
{
    /**
     * Availability per renderer, since checking a binary spawns a process.
     *
     * @var array<string, bool>
     */
    protected array $available = [];

    public function resolve(AttachmentOptions $options, string $filename): PdfRenderer
    {
        $candidates = match ($options->renderer) {
            'auto' => [new PdftoppmRenderer($options->pdftoppm), new ImagickRenderer],
            'pdftoppm' => [new PdftoppmRenderer($options->pdftoppm)],
            'imagick' => [new ImagickRenderer],
            default => throw UnsupportedAttachmentException::unknownRenderer($options->renderer),
        };

        foreach ($candidates as $renderer) {
            $key = $renderer->name().':'.$options->pdftoppm;

            if ($this->available[$key] ??= $renderer->isAvailable()) {
                return $renderer;
            }
        }

        throw UnsupportedAttachmentException::noRenderer($filename, $options->renderer);
    }
}
