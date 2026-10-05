<?php

namespace mindtwo\LaravelAiSpark\Attachments;

/**
 * How PDF attachments are turned into something vLLM accepts.
 */
enum PdfMode: string
{
    /** Text layer only, via smalot/pdfparser. Cheap, blind to scans, charts and layout. */
    case Text = 'text';

    /** Every page rendered to an image for a vision-language model. Reads scans and layout. */
    case Images = 'images';

    /** Text layer plus page images: exact text for quoting, images for everything visual. */
    case Both = 'both';

    public function needsText(): bool
    {
        return $this !== self::Images;
    }

    public function needsImages(): bool
    {
        return $this !== self::Text;
    }
}
