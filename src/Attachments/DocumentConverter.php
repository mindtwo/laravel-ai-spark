<?php

namespace mindtwo\LaravelAiSpark\Attachments;

use Illuminate\Support\Facades\Log;
use mindtwo\LaravelAiSpark\Attachments\Renderers\RendererResolver;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Replaces document attachments with parts vLLM accepts, because it has no `file` content part.
 *
 * Text formats are inlined. PDFs become their text layer, page images, or both, depending on
 * the PdfMode. Everything else fails with an UnsupportedAttachmentException before the request
 * is sent. Images, audio and video attachments are left untouched and can be mixed freely.
 */
class DocumentConverter
{
    /**
     * Mime types that are text even though they are not `text/*`.
     */
    protected const TEXT_MIME_TYPES = [
        'application/json',
        'application/ld+json',
        'application/x-ndjson',
        'application/xml',
        'application/yaml',
        'application/x-yaml',
        'application/csv',
        'application/javascript',
        'application/x-httpd-php',
        'application/sql',
    ];

    public function __construct(
        protected RendererResolver $renderers = new RendererResolver,
    ) {}

    /**
     * @param  array<int, Message>  $messages
     */
    public function convertMessages(array $messages, AttachmentOptions $options = new AttachmentOptions): void
    {
        foreach ($messages as $message) {
            if (! $message instanceof UserMessage || $message->documents() === []) {
                continue;
            }

            $parts = [];

            foreach ($message->additionalContent as $part) {
                array_push($parts, ...($part instanceof Document ? $this->convert($part, $options) : [$part]));
            }

            $message->additionalContent = $parts;
        }
    }

    /**
     * Text-only conversion, for callers that need a single text part.
     */
    public function toText(Document $document): Text
    {
        $text = $this->convert($document, new AttachmentOptions(pdf: PdfMode::Text))[0];

        return $text instanceof Text ? $text : new Text('');
    }

    /**
     * @return list<Text|Image>
     */
    protected function convert(Document $document, AttachmentOptions $options): array
    {
        $filename = $this->filename($document);

        if ($document->isFileId()) {
            throw UnsupportedAttachmentException::providerFile((string) $document->fileId());
        }

        if ($document->isChunks()) {
            return [$this->wrap($filename, implode("\n\n", $document->chunks() ?? []))];
        }

        $content = $document->rawContent();

        if (blank($content)) {
            throw UnsupportedAttachmentException::emptyDocument($filename);
        }

        $mimeType = strtolower((string) $document->mimeType());

        return match (true) {
            $this->isText($mimeType) => [$this->wrap($filename, $this->toUtf8($content))],
            $mimeType === 'application/pdf' => $this->convertPdf($content, $filename, $options),
            default => throw UnsupportedAttachmentException::unsupportedType($filename, $mimeType ?: 'unknown'),
        };
    }

    /**
     * Page images follow the document's text part, each preceded by its own label. The Spark
     * MessageMap keeps that order, so the model knows which image is which page of which file.
     *
     * @return list<Text|Image>
     */
    protected function convertPdf(string $content, string $filename, AttachmentOptions $options): array
    {
        $text = $options->pdf->needsText()
            ? $this->extractPdfText($content, $filename, required: $options->pdf === PdfMode::Text)
            : '';

        if (! $options->pdf->needsImages()) {
            return [$this->wrap($filename, $text)];
        }

        $pages = $this->renderers->resolve($options, $filename)->render($content, $filename, $options->maxPages, $options->dpi);

        $count = count($pages);
        $note = $text === ''
            ? sprintf('No text layer. The %d page image(s) follow.', $count)
            : sprintf("Text layer below. The %d page image(s) follow after it.\n\n%s", $count, $text);

        $parts = [$this->wrap($filename, $note, ['pages' => (string) $count])];

        foreach ($pages as $index => $jpeg) {
            $parts[] = new Text(sprintf("[%s, page %d of %d]\n", $filename, $index + 1, $count));
            $parts[] = Image::fromRawContent($jpeg, 'image/jpeg');
        }

        $parts[] = new Text("\n");

        return $parts;
    }

    /**
     * @param  bool  $required  Without page images the text layer is all the model gets, so it must exist.
     */
    protected function extractPdfText(string $content, string $filename, bool $required): string
    {
        if (! $this->pdfParserAvailable()) {
            throw UnsupportedAttachmentException::pdfParserMissing($filename);
        }

        try {
            $pdf = (new Parser)->parseContent($content);
            $text = trim($pdf->getText());
        } catch (Throwable $e) {
            throw UnsupportedAttachmentException::unreadablePdf($filename, $e);
        }

        if (trim((string) preg_replace('/\s+/u', '', $text)) === '') {
            if ($required) {
                throw UnsupportedAttachmentException::noTextLayer($filename);
            }

            return '';
        }

        $images = count($pdf->getObjectsByType('XObject', 'Image'));

        if ($required && $images > 0) {
            Log::warning(sprintf(
                'Spark: PDF [%s] contains %d embedded image(s) that are dropped; only its text layer is sent. '
                .'Use attachments.pdf "both" to send page images as well.',
                $filename,
                $images,
            ));
        }

        return $text;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    protected function wrap(string $filename, string $body, array $attributes = []): Text
    {
        $attributes = collect(['name' => $filename, ...$attributes])
            ->map(fn (string $value, string $key): string => sprintf('%s="%s"', $key, htmlspecialchars($value, ENT_QUOTES)))
            ->implode(' ');

        return new Text("<document {$attributes}>\n{$body}\n</document>\n\n");
    }

    protected function pdfParserAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    protected function isText(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || in_array($mimeType, self::TEXT_MIME_TYPES, true)
            || str_ends_with($mimeType, '+json')
            || str_ends_with($mimeType, '+xml');
    }

    protected function toUtf8(string $content): string
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        return mb_check_encoding($content, 'UTF-8')
            ? $content
            : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    protected function filename(Document $document): string
    {
        $source = $document->localPath() ?? $document->storagePath() ?? $document->url();

        return $document->documentTitle()
            ?? $document->filename()
            ?? ($source !== null ? basename(parse_url($source, PHP_URL_PATH) ?: $source) : 'document');
    }
}
