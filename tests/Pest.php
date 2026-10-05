<?php

use mindtwo\LaravelAiSpark\Attachments\AttachmentOptions;
use mindtwo\LaravelAiSpark\Attachments\Renderers\PdfRenderer;
use mindtwo\LaravelAiSpark\Attachments\Renderers\RendererResolver;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;
use mindtwo\LaravelAiSpark\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Build a minimal, valid PDF. Without text the pages mimic a scanned, image-only document.
 */
function makePdf(?string $text = null, int $pages = 1): string
{
    $stream = $text === null ? '' : sprintf('BT /F1 12 Tf 20 100 Td (%s) Tj ET', $text);
    $pageIds = range(5, 4 + $pages);

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', array_map(fn (int $id): string => "{$id} 0 R", $pageIds)), $pages),
        3 => sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream),
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    foreach ($pageIds as $id) {
        $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Contents 3 0 R /Resources << /Font << /F1 4 0 R >> >> >>';
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [];

    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
    }

    $xref = strlen($pdf);
    $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
}

/**
 * A renderer that returns one fake JPEG per page without touching poppler or Imagick.
 */
function fakeRenderer(int $pages = 1): PdfRenderer
{
    return new class($pages) implements PdfRenderer
    {
        public function __construct(private int $pages) {}

        public function render(string $pdf, string $filename, int $maxPages, int $dpi): array
        {
            if ($this->pages > $maxPages) {
                throw UnsupportedAttachmentException::tooManyPages($filename, $maxPages);
            }

            return array_map(fn (int $page): string => "jpeg-page-{$page}", range(1, $this->pages));
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function name(): string
        {
            return 'fake';
        }
    };
}

function useFakeRenderer(int $pages = 1): void
{
    $renderer = fakeRenderer($pages);

    app()->instance(RendererResolver::class, new class($renderer) extends RendererResolver
    {
        public function __construct(private PdfRenderer $renderer) {}

        public function resolve(AttachmentOptions $options, string $filename): PdfRenderer
        {
            return $this->renderer;
        }
    });
}
