<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Document;
use mindtwo\LaravelAiSpark\Attachments\DocumentConverter;
use mindtwo\LaravelAiSpark\Exceptions\UnsupportedAttachmentException;
use Prism\Prism\ValueObjects\Media\Document as PrismDocument;

use function Laravel\Ai\agent;

beforeEach(function () {
    Http::preventStrayRequests();

    config()->set('ai.providers.spark.url', 'https://spark.test/v1');
    config()->set('ai.providers.spark.models.text.default', 'nvidia/test-model');

    Http::fake([
        'spark.test/v1/chat/completions' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'nvidia/test-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Done'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
        ]),
    ]);
});

function sparkUserContent(Request $request): array
{
    return collect($request['messages'])->firstWhere('role', 'user')['content'];
}

it('sends text documents as text parts instead of file parts', function (string $mimeType, string $content) {
    agent(instructions: 'Be brief.')->prompt('Summarise.', [Document::fromString($content, $mimeType)], provider: 'spark');

    Http::assertSent(function (Request $request) use ($content) {
        $parts = sparkUserContent($request);

        return collect($parts)->pluck('type')->all() === ['text']
            && str_contains($parts[0]['text'], $content)
            && str_ends_with($parts[0]['text'], 'Summarise.');
    });
})->with([
    'plain text' => ['text/plain', 'The secret word is banana.'],
    'markdown' => ['text/markdown', '# Agenda'],
    'json' => ['application/json', '{"secret":"banana"}'],
    'csv' => ['text/csv', 'name,value'],
]);

it('extracts the text layer of a pdf', function () {
    agent(instructions: 'Be brief.')->prompt('Summarise.', [Document::fromString(makePdf('Quarterly numbers'), 'application/pdf')], provider: 'spark');

    Http::assertSent(fn (Request $request) => str_contains(sparkUserContent($request)[0]['text'], 'Quarterly numbers')
        && ! str_contains(json_encode($request->data()), '"type":"file"'));
});

it('fails before sending when a pdf has no text layer', function () {
    try {
        agent(instructions: 'Be brief.')->prompt('Summarise.', [Document::fromString(makePdf(), 'application/pdf')], provider: 'spark');
    } finally {
        Http::assertNothingSent();
    }
})->throws(UnsupportedAttachmentException::class, 'has no extractable text');

it('fails before sending for document types vllm cannot read', function () {
    try {
        agent(instructions: 'Be brief.')->prompt('Summarise.', [
            Document::fromString('PK fake docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], provider: 'spark');
    } finally {
        Http::assertNothingSent();
    }
})->throws(UnsupportedAttachmentException::class, 'cannot be sent to vLLM');

it('fails for damaged pdfs', function () {
    (new DocumentConverter)->toText(PrismDocument::fromRawContent('%PDF-1.4 broken', 'application/pdf', 'broken.pdf'));
})->throws(UnsupportedAttachmentException::class, 'PDF [broken.pdf] could not be parsed');

it('fails for documents stored at another provider', function () {
    (new DocumentConverter)->toText(PrismDocument::fromFileId('file-123'));
})->throws(UnsupportedAttachmentException::class, 'file stored at another AI provider');

it('names the missing pdf parser', function () {
    $converter = new class extends DocumentConverter
    {
        protected function pdfParserAvailable(): bool
        {
            return false;
        }
    };

    $converter->toText(PrismDocument::fromRawContent(makePdf('Hello'), 'application/pdf', 'report.pdf'));
})->throws(UnsupportedAttachmentException::class, 'composer require smalot/pdfparser');

it('uses the document title as the name', function () {
    $text = (new DocumentConverter)->toText(PrismDocument::fromText('Hello', 'notes.txt'));

    expect($text->text)->toStartWith('<document name="notes.txt">');
});
