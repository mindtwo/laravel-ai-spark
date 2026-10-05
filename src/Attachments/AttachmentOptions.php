<?php

namespace mindtwo\LaravelAiSpark\Attachments;

use InvalidArgumentException;

/**
 * Attachment settings of a connection, optionally overridden per agent via provider options.
 */
final readonly class AttachmentOptions
{
    public function __construct(
        public PdfMode $pdf = PdfMode::Text,
        public string $renderer = 'auto',
        public string $pdftoppm = 'pdftoppm',
        public int $maxPages = 20,
        public int $dpi = 144,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        $pdf = $options['pdf'] ?? PdfMode::Text;

        if (! $pdf instanceof PdfMode) {
            $pdf = PdfMode::tryFrom((string) $pdf) ?? throw new InvalidArgumentException(sprintf(
                'Invalid Spark attachments.pdf mode [%s]. Use one of: text, images, both.',
                $pdf,
            ));
        }

        return new self(
            pdf: $pdf,
            renderer: (string) ($options['renderer'] ?? 'auto'),
            pdftoppm: (string) ($options['pdftoppm'] ?? 'pdftoppm'),
            maxPages: max(1, (int) ($options['max_pages'] ?? 20)),
            dpi: max(36, (int) ($options['dpi'] ?? 144)),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function merge(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        return self::fromArray([
            'pdf' => $this->pdf,
            'renderer' => $this->renderer,
            'pdftoppm' => $this->pdftoppm,
            'max_pages' => $this->maxPages,
            'dpi' => $this->dpi,
            ...$overrides,
        ]);
    }
}
