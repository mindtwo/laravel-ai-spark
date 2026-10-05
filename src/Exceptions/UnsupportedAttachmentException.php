<?php

namespace mindtwo\LaravelAiSpark\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Throwable;

/**
 * Thrown before a request is sent when an attachment cannot be passed to vLLM.
 *
 * vLLM has no `file` content part, so documents are converted to text locally.
 * Whatever cannot be converted fails here with a reason instead of a 400 from the server.
 * It is deliberately not failoverable: silently re-sending a document to a hosted
 * provider would move data off-premises.
 */
class UnsupportedAttachmentException extends AiException
{
    public const FULL_PDF_SUPPORT = 'See "Full PDF support" in the mindtwo/laravel-ai-spark README.';

    public static function pdfParserMissing(string $filename): self
    {
        return new self(sprintf(
            'Cannot read PDF [%s]: text extraction needs smalot/pdfparser (composer require smalot/pdfparser). '
            .'It only reads the text layer; scanned pages, images and layout are not understood. %s',
            $filename,
            self::FULL_PDF_SUPPORT,
        ));
    }

    public static function noTextLayer(string $filename): self
    {
        return new self(sprintf(
            'PDF [%s] has no extractable text, most likely a scan or an image-only export. '
            .'Set attachments.pdf to "images" or "both" so the pages are sent as images to a vision-capable model. %s',
            $filename,
            self::FULL_PDF_SUPPORT,
        ));
    }

    public static function unreadablePdf(string $filename, Throwable $previous): self
    {
        return new self(sprintf(
            'PDF [%s] could not be parsed (%s). Encrypted, password protected or damaged files are not supported. %s',
            $filename,
            $previous->getMessage(),
            self::FULL_PDF_SUPPORT,
        ), previous: $previous);
    }

    public static function unsupportedType(string $filename, string $mimeType): self
    {
        return new self(sprintf(
            'Document [%s] of type [%s] cannot be sent to vLLM, which accepts no file attachments. '
            .'Supported are text formats (text/*, JSON, XML, YAML, CSV) and PDFs with a text layer. '
            .'Convert other formats to text or Markdown before attaching them.',
            $filename,
            $mimeType,
        ));
    }

    public static function providerFile(string $fileId): self
    {
        return new self(sprintf(
            'Document [%s] references a file stored at another AI provider. vLLM has no file storage; attach the content instead.',
            $fileId,
        ));
    }

    public static function tooManyPages(string $filename, int $maxPages): self
    {
        return new self(sprintf(
            'PDF [%s] has more than %d pages, the attachments.max_pages limit for page images. '
            .'Raise the limit, split the document, or use attachments.pdf "text" for long text documents.',
            $filename,
            $maxPages,
        ));
    }

    public static function noRenderer(string $filename, string $renderer): self
    {
        return new self(sprintf(
            'Cannot render PDF [%s] to images: no PDF renderer available (attachments.renderer = %s). '
            .'Install poppler (brew install poppler / apt install poppler-utils) for pdftoppm, '
            .'or the Imagick extension together with Ghostscript.',
            $filename,
            $renderer,
        ));
    }

    public static function unknownRenderer(string $renderer): self
    {
        return new self(sprintf(
            'Unknown attachments.renderer [%s]. Use one of: auto, pdftoppm, imagick.',
            $renderer,
        ));
    }

    public static function renderFailed(string $filename, string $renderer, string $reason): self
    {
        return new self(sprintf(
            'Rendering PDF [%s] with %s failed: %s',
            $filename,
            $renderer,
            $reason !== '' ? $reason : 'no error output',
        ));
    }

    public static function emptyDocument(string $filename): self
    {
        return new self(sprintf('Document [%s] is empty or could not be read.', $filename));
    }
}
