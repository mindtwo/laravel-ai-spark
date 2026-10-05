<?php

namespace mindtwo\LaravelAiSpark\Console;

use Illuminate\Console\Command;
use mindtwo\LaravelAiSpark\Attachments\AttachmentOptions;
use mindtwo\LaravelAiSpark\Attachments\Renderers\ImagickRenderer;
use mindtwo\LaravelAiSpark\Attachments\Renderers\PdftoppmRenderer;
use mindtwo\LaravelAiSpark\Server\SparkManager;
use Smalot\PdfParser\Parser;
use Throwable;

class SparkStatusCommand extends Command
{
    protected $signature = 'spark:status {connection=spark : The ai.providers entry to check}';

    protected $description = 'Check a Spark (vLLM) connection: reachability, served models and attachment support';

    public function handle(SparkManager $manager): int
    {
        $server = $manager->connection($this->argument('connection'));
        $config = (array) config('ai.providers.'.$server->connection);
        $attachments = AttachmentOptions::fromArray($config['attachments'] ?? []);
        $healthy = $server->health();

        $rows = [
            ['URL', (string) ($config['url'] ?? '')],
            ['Health', $healthy ? '<info>ok</info>' : '<error>unreachable</error>'],
        ];

        $modelServed = false;

        if ($healthy) {
            try {
                $models = $server->models();
                $modelServed = collect($models)->contains('id', $server->model());

                $rows[] = ['vLLM version', $server->version()];
                $rows[] = ['Served models', collect($models)
                    ->map(fn (array $model): string => sprintf('%s (%s tokens)', $model['id'], number_format((int) $model['max_model_len'])))
                    ->implode(', ')];
            } catch (Throwable $e) {
                $rows[] = ['Models', '<error>'.$e->getMessage().'</error>'];
            }
        }

        $rows[] = ['Configured model', ($server->model() ?: '(none)').($modelServed ? ' <info>served</info>' : ' <error>not served</error>')];
        $rows[] = ['PDF mode', $attachments->pdf->value];
        $rows[] = ['PDF text (smalot/pdfparser)', class_exists(Parser::class) ? 'installed' : 'not installed'];
        $rows[] = ['PDF images: pdftoppm', (new PdftoppmRenderer($attachments->pdftoppm))->isAvailable() ? 'available' : 'not found'];
        $rows[] = ['PDF images: imagick', (new ImagickRenderer)->isAvailable() ? 'available' : (extension_loaded('imagick') ? 'not usable (Ghostscript missing)' : 'not installed')];

        $this->table(['Check', 'Result'], $rows);

        return $healthy && $modelServed ? self::SUCCESS : self::FAILURE;
    }
}
