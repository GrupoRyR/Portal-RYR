<?php

namespace App\Audit;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Exportar CSV» of Auditoría: the current filter, streamed page by page
 * through AuditFeed (constant memory), at most {@see MAX_ROWS} rows.
 *
 * UTF-8 with a byte order mark and `;` as separator, which is what Excel
 * expects with the Colombian regional settings. Cells that a spreadsheet
 * would read as a formula (`=`, `+`, `-`, `@`, and tab/CR per OWASP) get a
 * leading apostrophe.
 */
class AuditCsv
{
    public const MAX_ROWS = 50000;

    public const SEPARATOR = ';';

    public const HEADER = ['Fecha', 'Persona', 'Correo', 'Tipo', 'Qué pasó', 'Empresa', 'Dispositivo'];

    private const CHUNK = 500;

    public function __construct(public readonly int $maxRows = self::MAX_ROWS) {}

    public function download(AuditFeed $feed, AuditFilters $filters): StreamedResponse
    {
        $filename = 'auditoria-'.now()->timezone(config('app.timezone'))->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($feed, $filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $this->put($out, self::HEADER);

            $written = 0;
            $cursor = null;

            do {
                $page = $feed->page($filters, $cursor, min(self::CHUNK, $this->maxRows - $written));

                foreach ($page['entries'] as $entry) {
                    $this->put($out, self::row($entry));
                    $written++;
                }

                $cursor = $page['next'];
            } while ($cursor !== null && $written < $this->maxRows);

            if ($cursor !== null) {
                $this->put($out, ['Se exportaron solo los primeros '.number_format($this->maxRows, 0, ',', ' ').' eventos. Acota los filtros para ver el resto.']);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    public static function row(AuditEntry $entry): array
    {
        return [
            $entry->occurredAt->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            $entry->who,
            (string) $entry->email,
            $entry->categoryLabel(),
            $entry->what,
            (string) $entry->company,
            $entry->device === null ? '' : $entry->deviceLabel(),
        ];
    }

    public static function neutralize(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function put($out, array $cells): void
    {
        fputcsv($out, array_map(self::neutralize(...), $cells), self::SEPARATOR, '"', '', "\r\n");
    }
}
