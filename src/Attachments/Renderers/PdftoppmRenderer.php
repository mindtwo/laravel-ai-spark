<?php

namespace mindtwo\LaravelAiSpark\Attachments\Renderers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;
use Throwable;

/**
 * Renders pages with poppler's `pdftoppm` binary (brew/apt package `poppler` / `poppler-utils`).
 */
class PdftoppmRenderer implements PdfRenderer
{
    public function __construct(protected string $binary = 'pdftoppm') {}

    public function name(): string
    {
        return 'pdftoppm';
    }

    public function isAvailable(): bool
    {
        try {
            return Process::timeout(10)->run([$this->binary, '-v'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function render(string $pdf, string $filename, int $maxPages, int $dpi): array
    {
        $directory = sys_get_temp_dir().'/spark-pdf-'.Str::random(16);
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/input.pdf', $pdf);

            // One page more than allowed tells us the document is too long without a second tool.
            $result = Process::timeout(120)->path($directory)->run([
                $this->binary, '-jpeg', '-r', (string) $dpi, '-l', (string) ($maxPages + 1), 'input.pdf', 'page',
            ]);

            if (! $result->successful()) {
                throw UnsupportedAttachmentException::renderFailed($filename, $this->name(), trim($result->errorOutput()));
            }

            $pages = File::glob($directory.'/page-*.jpg');
            natsort($pages);

            if (count($pages) > $maxPages) {
                throw UnsupportedAttachmentException::tooManyPages($filename, $maxPages);
            }

            return array_values(array_map(fn (string $path): string => File::get($path), $pages));
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
