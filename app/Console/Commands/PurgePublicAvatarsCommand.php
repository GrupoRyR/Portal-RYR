<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Storage;

/**
 * Removes the Microsoft profile photos earlier versions saved on the public
 * disk (storage/app/public/avatars/{id}.jpg, reachable by anyone at
 * /storage/avatars/{id}.jpg) and clears `users.photo_path`. Sign-in no
 * longer stores photos, so running it once after the deploy is enough; it
 * is safe to run again. In production it asks first unless --force.
 */
class PurgePublicAvatarsCommand extends Command
{
    use ConfirmableTrait;

    private const DIRECTORY = 'avatars';

    protected $signature = 'portal:purge-public-avatars {--force : Run without asking in production}';

    protected $description = 'Delete the public profile photos and clear users.photo_path';

    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $files = count($disk->allFiles(self::DIRECTORY));

        $disk->deleteDirectory(self::DIRECTORY);

        $users = User::query()->whereNotNull('photo_path')->update(['photo_path' => null]);

        $this->info(sprintf(
            '%d %s, %d %s.',
            $files,
            $files === 1 ? 'foto borrada' : 'fotos borradas',
            $users,
            $users === 1 ? 'usuario actualizado' : 'usuarios actualizados',
        ));

        return self::SUCCESS;
    }
}
