<?php

namespace mindtwo\LaravelAiSpark\Attachments\Renderers;

use Imagick;
use ImagickException;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Renders pages with the Imagick extension. Imagick delegates PDFs to Ghostscript, which must be installed.
 */
class ImagickRenderer implements PdfRenderer
{
    protected const PROBE_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\n"
        ."xref\n0 4\n0000000000 65535 f \n0000000009 00000 n \n0000000052 00000 n \n0000000101 00000 n \n"
        ."trailer<</Size 4/Root 1 0 R>>\nstartxref\n149\n%%EOF\n";

    public function name(): string
    {
        return 'imagick';
    }

    /**
     * Imagick lists PDF as a format even without Ghostscript, so probe with a real one-page PDF.
     */
    public function isAvailable(): bool
    {
        if (! extension_loaded('imagick') || ! $this->ghostscriptInstalled()) {
            return false;
        }

        try {
            $probe = new Imagick;
            $probe->pingImageBlob(self::PROBE_PDF);

            return $probe->getNumberImages() === 1;
        } catch (ImagickException) {
            return false;
        }
    }

    /**
     * Checked up front, otherwise ImageMagick's delegate prints "gs: command not found" to stderr.
     */
    protected function ghostscriptInstalled(): bool
    {
        $finder = new ExecutableFinder;

        foreach (['gs', 'gswin64c', 'gswin32c'] as $binary) {
            if ($finder->find($binary) !== null) {
                return true;
            }
        }

        return false;
    }

    public function render(string $pdf, string $filename, int $maxPages, int $dpi): array
    {
        try {
            $document = new Imagick;
            $document->pingImageBlob($pdf);
            $pageCount = $document->getNumberImages();
            $document->clear();

            if ($pageCount > $maxPages) {
                throw UnsupportedAttachmentException::tooManyPages($filename, $maxPages);
            }

            $document = new Imagick;
            $document->setResolution($dpi, $dpi);
            $document->readImageBlob($pdf);

            $pages = [];

            foreach ($document as $page) {
                $page->setImageBackgroundColor('white');
                $page = $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                $page->setImageFormat('jpeg');
                $page->setImageCompressionQuality(85);
                $pages[] = $page->getImageBlob();
            }

            $document->clear();

            return $pages;
        } catch (ImagickException $e) {
            throw UnsupportedAttachmentException::renderFailed(
                $filename,
                $this->name(),
                $e->getMessage().' (Imagick needs Ghostscript to read PDFs.)',
            );
        }
    }
}
