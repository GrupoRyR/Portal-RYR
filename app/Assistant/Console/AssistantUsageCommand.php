<?php

namespace App\Assistant\Console;

use App\Assistant\AssistantLog;
use App\Assistant\Budget\AssistantBudget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

/**
 * `portal:assistant-usage {--days=7}`: the assistant's consumption per day
 * (Bogotá) and feature (`chat` or `draft`), from the question lines of
 * Auditoría (App\Assistant\AssistantLog): questions, model calls, tokens
 * (uncached input, cache writes, cache reads, output), the cache hit rate
 * (cache reads over all input tokens) and the cost, plus the month's spend
 * against the budget. Reads only; never prints a question or an answer,
 * which are not stored.
 */
class AssistantUsageCommand extends Command
{
    protected $signature = 'portal:assistant-usage {--days=7 : Days back, today included (1 to 366)}';

    protected $description = 'Muestra el consumo del asistente por día y uso: tokens, aciertos de caché y costo';

    public function handle(AssistantBudget $budget): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);

        if ($days === false) {
            $this->error('--days debe ser un número entero entre 1 y 366.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::now(config('app.timezone'))->startOfDay()->subDays($days - 1);
        $rows = [];
        $total = self::empty('Total', '');

        foreach (AssistantLog::questions($from)->orderBy('created_at')->get(['created_at', 'properties']) as $line) {
            $day = CarbonImmutable::instance($line->created_at)->setTimezone(config('app.timezone'))->toDateString();
            $feature = (string) ($line->properties->get('feature') ?: 'chat');
            $key = $day.'|'.$feature;

            $rows[$key] ??= self::empty($day, $feature);
            self::add($rows[$key], $line);
            self::add($total, $line);
        }

        if ($rows === []) {
            $this->info("Sin preguntas al asistente en los últimos {$days} días.");
        } else {
            $this->table(
                ['Día', 'Uso', 'Preguntas', 'Llamadas', 'Entrada', 'Caché escrita', 'Caché leída', 'Salida', 'Aciertos de caché', 'Costo (US$)'],
                array_map(self::format(...), [...array_values($rows), $total]),
            );
        }

        $spent = $budget->spentThisMonth();
        $this->line(sprintf(
            'Mes en curso (%s): US$ %s de US$ %s (%s %%).',
            AssistantBudget::monthStart()->format('Y-m'),
            number_format($spent, 2, ',', '.'),
            number_format($budget->limit(), 2, ',', '.'),
            number_format($budget->percent($spent), 1, ',', '.'),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(string $day, string $feature): array
    {
        return ['day' => $day, 'feature' => $feature, 'questions' => 0, 'calls' => 0, 'input' => 0, 'write' => 0, 'read' => 0, 'output' => 0, 'cost' => 0.0];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function add(array &$row, Activity $line): void
    {
        $get = fn (string $key) => $line->properties->get($key, 0);

        $row['questions']++;
        $row['calls'] += (int) $get('model_calls');
        $row['input'] += (int) $get('input_tokens');
        $row['write'] += (int) $get('cache_creation_input_tokens');
        $row['read'] += (int) $get('cache_read_input_tokens');
        $row['output'] += (int) $get('output_tokens');
        $row['cost'] += (float) $get('cost_usd');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function format(array $row): array
    {
        $number = fn (int $value) => number_format($value, 0, ',', '.');
        $allInput = $row['input'] + $row['write'] + $row['read'];

        return [
            $row['day'],
            match ($row['feature']) {
                'draft' => 'borrador',
                'chat' => 'chat',
                default => (string) $row['feature'],
            },
            $number($row['questions']),
            $number($row['calls']),
            $number($row['input']),
            $number($row['write']),
            $number($row['read']),
            $number($row['output']),
            $allInput === 0 ? '-' : number_format($row['read'] / $allInput * 100, 1, ',', '.').' %',
            number_format($row['cost'], 4, ',', '.'),
        ];
    }
}
