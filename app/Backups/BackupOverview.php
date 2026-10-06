<?php

namespace App\Backups;

use App\Models\BackupRun;
use Carbon\CarbonInterface;
use Spatie\Backup\BackupDestination\BackupDestination;

/**
 * What the «Respaldo diario» card of Configuración shows, all of it real:
 * the copies found on the backups disk, the outcome of the last finished
 * run and any «Respaldar ahora» request still waiting or running.
 */
final readonly class BackupOverview
{
    public function __construct(
        public ?CarbonInterface $newestAt,
        public ?float $newestBytes,
        public int $kept,
        public ?BackupRun $lastRun,
        public ?BackupRun $unfinished,
    ) {}

    public static function current(): self
    {
        $destination = BackupDestination::create(
            (string) config('backup.backup.destination.disks.0'),
            (string) config('backup.backup.name'),
        );
        $backups = $destination->backups();
        $newest = $backups->newest();

        return new self(
            $newest?->date(),
            $newest?->sizeInBytes(),
            $backups->count(),
            BackupRun::query()->finished()->latest('finished_at')->latest('id')->first(),
            BackupRun::query()->unfinished()->latest('id')->first(),
        );
    }

    public function isBusy(): bool
    {
        return $this->unfinished !== null;
    }

    /**
     * «Último respaldo: 6 oct 2026, 02:30 · correcto», or «… nunca».
     */
    public function lastBackupLine(): string
    {
        if ($this->newestAt === null) {
            return 'Último respaldo: nunca';
        }

        $line = 'Último respaldo: '.self::date($this->newestAt);

        return $this->lastRun?->succeeded() ? $line.' · correcto' : $line;
    }

    /**
     * «3,3 MB · 3 copias guardadas en el servidor», when there are copies.
     */
    public function copiesLine(): ?string
    {
        if ($this->newestAt === null) {
            return null;
        }

        $copies = $this->kept === 1 ? '1 copia guardada' : "{$this->kept} copias guardadas";

        return self::size((float) $this->newestBytes)." · {$copies} en el servidor";
    }

    /**
     * Shown when the last attempt failed, whatever copies remain.
     */
    public function failureLine(): ?string
    {
        if ($this->lastRun === null || $this->lastRun->succeeded()) {
            return null;
        }

        return 'El intento del '.self::date($this->lastRun->finished_at).' terminó con error.';
    }

    public function progressLine(): ?string
    {
        return match ($this->unfinished?->status) {
            BackupRun::STATUS_PENDING => 'Programado: se hará en el próximo minuto.',
            BackupRun::STATUS_RUNNING => 'En curso: el respaldo se está haciendo ahora.',
            default => null,
        };
    }

    private static function date(CarbonInterface $date): string
    {
        return $date->copy()->timezone(config('app.timezone'))->locale('es')->isoFormat('D MMM YYYY, HH:mm');
    }

    private static function size(float $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1, ',', '.').' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1, ',', '.').' MB',
            default => max(1, (int) ceil($bytes / 1024)).' KB',
        };
    }
}
