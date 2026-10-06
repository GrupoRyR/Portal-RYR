<?php

namespace App\Backups;

use Spatie\Backup\Notifications\Notifiable;

/**
 * Receiver of the backup alerts (config/backup.php): the super admins of
 * config('portal.super_admin_emails'), the same list that grants the role.
 * With no super admin configured no mail is sent.
 */
class BackupNotifiable extends Notifiable
{
    /**
     * @return list<string>
     */
    public function routeNotificationForMail(): array
    {
        return array_values(config('portal.super_admin_emails', []));
    }
}
