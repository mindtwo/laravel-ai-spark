<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use mindtwo\LaravelAiSpark\Attachments\AttachmentOptions;
use mindtwo\LaravelAiSpark\Attachments\Renderers\PdftoppmRenderer;
use mindtwo\LaravelAiSpark\Attachments\Renderers\RendererResolver;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;

use function Laravel\Ai\agent;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('ai.providers.spark.url', 'https://spark.test/v1');
    config()->set('ai.providers.spark.models.text.default', 'nvidia/test-model');

    Http::fake([
        'spark.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'nvidia/test-model',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Done'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ]),
    ]);
});

function sentUserParts(): array
{
    $parts = [];

    Http::assertSent(function (Request $request) use (&$parts) {
        $parts = collect($request['messages'])->firstWhere('role', 'user')['content'];

        return true;
    });

    return $parts;
}

/**
 * Compact view of the sent parts: text parts as their text, images as "[image]".
 *
 * @return list<string>
 */
function partOutline(array $parts): array
{
    return array_map(fn (array $part): string => $part['type'] === 'image_url' ? '[image]' : $part['text'], $parts);
}

function pdfAttachment(?string $text = 'Quarterly numbers', string $name = 'report.pdf'): Document
{
    return Document::fromString(makePdf($text), 'application/pdf')->as($name);
}

it('sends pdf pages as images in images mode', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'images');
    useFakeRenderer(pages: 3);

    agent(instructions: 'Be brief.')->prompt('Summarise.', [pdfAttachment(null)], provider: 'spark');

    $parts = sentUserParts();
    $outline = partOutline($parts);

    expect($outline)->toHaveCount(7)
        ->and($outline[0])->toContain('<document name="report.pdf" pages="3">', 'No text layer')
        ->and($outline[0])->toEndWith("[report.pdf, page 1 of 3]\n")
        ->and(array_slice($outline, 1))->toBe([
            '[image]', "[report.pdf, page 2 of 3]\n",
            '[image]', "[report.pdf, page 3 of 3]\n",
            '[image]', "\nSummarise.",
        ])
        ->and($parts[1]['image_url']['url'])->toBe('data:image/jpeg;base64,'.base64_encode('jpeg-page-1'));
});

it('does not need the pdf parser in images mode', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'images');
    useFakeRenderer();

    agent(instructions: 'Be brief.')->prompt('Summarise.', [pdfAttachment(null)], provider: 'spark');

    expect(partOutline(sentUserParts()))->toHaveCount(3);
});

it('sends the text layer and the page images in both mode', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'both');
    useFakeRenderer();

    agent(instructions: 'Be brief.')->prompt('Summarise.', [pdfAttachment('Quarterly numbers')], provider: 'spark');

    $outline = partOutline(sentUserParts());

    expect($outline)->toHaveCount(3)
        ->and($outline[0])->toContain('Text layer below', 'Quarterly numbers', '[report.pdf, page 1 of 1]')
        ->and($outline[1])->toBe('[image]');
});

it('accepts scans in both mode because the page images carry the content', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'both');
    useFakeRenderer();

    agent(instructions: 'Be brief.')->prompt('Summarise.', [pdfAttachment(null)], provider: 'spark');

    expect(partOutline(sentUserParts())[0])->toContain('No text layer');
});

it('mixes images, text files and pdfs in one prompt and numbers the page images', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'images');
    useFakeRenderer(pages: 2);

    agent(instructions: 'Be brief.')->prompt('Compare.', [
        Image::fromBase64(base64_encode('photo'), 'image/png'),
        Document::fromString('Notes from the call', 'text/plain'),
        pdfAttachment(null),
    ], provider: 'spark');

    $parts = sentUserParts();
    $outline = partOutline($parts);

    expect($outline[0])->toBe('[image]')
        ->and($parts[0]['image_url']['url'])->toBe('data:image/png;base64,'.base64_encode('photo'))
        ->and($outline[1])->toContain('Notes from the call', '<document name="report.pdf" pages="2">', '[report.pdf, page 1 of 2]')
        ->and(array_slice($outline, 2))->toBe(['[image]', "[report.pdf, page 2 of 2]\n", '[image]', "\nCompare."]);
});

it('lets an agent override the pdf mode without leaking the option to vllm', function () {
    useFakeRenderer();

    $agent = new class implements Agent, HasProviderOptions
    {
        use Promptable;

        public function instructions(): string
        {
            return 'Be brief.';
        }

        public function providerOptions(Lab|string $provider): array
        {
            return ['attachments' => ['pdf' => 'images'], 'top_k' => 20];
        }
    };

    $agent->prompt('Summarise.', [pdfAttachment(null)], provider: 'spark');

    Http::assertSent(fn (Request $request) => ! array_key_exists('attachments', $request->data())
        && $request['top_k'] === 20
        && collect($request['messages'])->firstWhere('role', 'user')['content'][1]['type'] === 'image_url');
});

it('keeps text attachments and the prompt in their original order', function () {
    agent(instructions: 'Be brief.')->prompt('Which is newer?', [
        Document::fromString('Version A', 'text/plain')->as('a.txt'),
        Image::fromBase64(base64_encode('photo'), 'image/png'),
        Document::fromString('Version B', 'text/plain')->as('b.txt'),
    ], provider: 'spark');

    $outline = partOutline(sentUserParts());

    expect($outline)->toHaveCount(3)
        ->and($outline[0])->toContain('a.txt', 'Version A')
        ->and($outline[1])->toBe('[image]')
        ->and($outline[2])->toContain('b.txt', 'Version B', 'Which is newer?');
});

it('refuses pdfs above the page limit before sending', function () {
    config()->set('ai.providers.spark.attachments.pdf', 'images');
    config()->set('ai.providers.spark.attachments.max_pages', 2);
    useFakeRenderer(pages: 3);

    try {
        agent(instructions: 'Be brief.')->prompt('Summarise.', [pdfAttachment(null)], provider: 'spark');
    } finally {
        Http::assertNothingSent();
    }
})->throws(UnsupportedAttachmentException::class, 'has more than 2 pages');

it('explains how to install a renderer when none is available', function () {
    (new RendererResolver)->resolve(new AttachmentOptions(renderer: 'pdftoppm', pdftoppm: '/nonexistent/pdftoppm'), 'report.pdf');
})->throws(UnsupportedAttachmentException::class, 'Install poppler');

it('rejects unknown modes and renderers', function (array $options, string $message) {
    expect(fn () => (new RendererResolver)->resolve(AttachmentOptions::fromArray($options), 'report.pdf'))
        ->toThrow($message);
})->with([
    'mode' => [['pdf' => 'ocr'], 'Invalid Spark attachments.pdf mode [ocr]'],
    'renderer' => [['renderer' => 'gimp'], 'Unknown attachments.renderer [gimp]'],
]);

it('renders real pages with pdftoppm', function () {
    $renderer = new PdftoppmRenderer;

    if (! $renderer->isAvailable()) {
        $this->markTestSkipped('pdftoppm (poppler) is not installed.');
    }

    $pages = $renderer->render(makePdf('Hello', pages: 2), 'report.pdf', maxPages: 5, dpi: 72);

    expect($pages)->toHaveCount(2)
        ->and(substr($pages[0], 0, 3))->toBe("\xFF\xD8\xFF");

    expect(fn () => $renderer->render(makePdf('Hello', pages: 3), 'report.pdf', maxPages: 2, dpi: 72))
        ->toThrow(UnsupportedAttachmentException::class, 'has more than 2 pages');
});
