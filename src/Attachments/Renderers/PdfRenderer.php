<?php

namespace mindtwo\LaravelAiSpark\Attachments\Renderers;

use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;

interface PdfRenderer
{
    /**
     * Render up to $maxPages pages to JPEG images, in page order.
     *
     * @return list<string> Raw JPEG bytes per page.
     *
     * @throws UnsupportedAttachmentException When the PDF has more than $maxPages pages or cannot be rendered.
     */
    public function render(string $pdf, string $filename, int $maxPages, int $dpi): array;

    public function isAvailable(): bool;

    public function name(): string;
}
