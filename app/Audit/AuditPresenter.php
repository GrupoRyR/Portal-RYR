<?php

namespace App\Audit;

use App\Api\StatusReporter;
use App\Backups\BackupRequests;
use App\Livewire\Admin\RoleManager;
use App\Models\Announcement;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\AppLaunch;
use App\Models\Company;
use App\Models\Integration;
use App\Models\User;
use App\Settings\PortalSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Turns activity log entries and app launches into Auditoría rows: who,
 * a readable «Sobre qué» and, for changes, the before/after of each field.
 * Known attribute names get a Spanish label; unknown ones are shown raw.
 */
final class AuditPresenter
{
    public const SYSTEM = 'Sistema';

    /** Verb of each named event; created/updated/deleted are generic. */
    private const VERBS = [
        'created' => 'Creó',
        'updated' => 'Cambió',
        'deleted' => 'Borró',
        'company.created' => 'Registró',
        'company.tenant_changed' => 'Cambió el inquilino de',
        'company.consent_verified' => 'Se verificó el consentimiento de',
        'company.activated' => 'Activó',
        'company.deactivated' => 'Desactivó',
        'app.status_changed' => 'Cambió el estado de',
        'app.access_rule_added' => 'Agregó un acceso a',
        'app.access_rule_removed' => 'Quitó un acceso de',
        'app.companies_changed' => 'Cambió las empresas de',
        'app.external_status_cleared' => 'Limpió las fallas reportadas de',
        'announcement.withdrawn' => 'Retiró',
        'announcement.republished' => 'Volvió a publicar',
        'announcement.companies_changed' => 'Cambió las empresas de',
        'category.moved' => 'Movió',
        'role.assigned' => 'Hizo editor de anuncios a',
        'role.removed' => 'Quitó como editor de anuncios a',
        'settings.updated' => 'Cambió',
        'api.status_reported' => 'Reportó el estado de',
        'integration.created' => 'Creó el token de',
        'integration.rotated' => 'Rotó el token de',
        'integration.revoked' => 'Revocó el token de',
        'integration.abilities_changed' => 'Cambió los permisos de',
        'integration.ips_changed' => 'Cambió las IP permitidas de',
        'backup.requested' => 'Pidió',
    ];

    private const REJECTION_REASONS = [
        'tenant_not_allowed' => 'organización sin acceso',
        'no_company' => 'organización sin acceso',
        'missing_email' => 'sin correo',
        'missing_claims' => 'datos incompletos',
        'emergency_account' => 'cuenta de emergencia',
        'provider_error' => 'error de Microsoft',
    ];

    private const UNLINK_REASONS = [
        'company_changed' => 'por cambio de empresa',
        'admin' => 'por un administrador',
        'user' => 'por la misma persona',
    ];

    private const ROLE_LABELS = [
        'super_admin' => 'Súper administrador',
        'portal_admin' => 'Administrador',
        'announcements_editor' => 'Editor de anuncios',
    ];

    /** Spanish labels of the audited attributes. */
    public const FIELD_LABELS = [
        'name' => 'Nombre',
        'short_name' => 'Nombre corto',
        'slug' => 'Identificador',
        'tenant_id' => 'Inquilino',
        'is_primary_for_tenant' => 'Principal del inquilino',
        'domains' => 'Dominios',
        'logo_light_path' => 'Logo claro',
        'logo_dark_path' => 'Logo oscuro',
        'icon_path' => 'Ícono',
        'icon_dark_path' => 'Ícono oscuro',
        'color_primary' => 'Color principal',
        'color_accent' => 'Color de acento',
        'color_dark' => 'Color oscuro',
        'quick_links' => 'Accesos rápidos',
        'is_active' => 'Activa',
        'consent_verified_at' => 'Consentimiento verificado',
        'description' => 'Descripción',
        'accent_color' => 'Color de acento',
        'url' => 'URL',
        'type' => 'Tipo',
        'open_in' => 'Abrir en',
        'hosting' => 'Alojamiento',
        'login_type' => 'Inicio de sesión',
        'app_category_id' => 'Categoría',
        'available_to_all_companies' => 'Todas las empresas',
        'sort_order' => 'Orden',
        'status' => 'Estado',
        'maintenance_message' => 'Mensaje de mantenimiento',
        'maintenance_until' => 'Mantenimiento hasta',
        'local_instructions' => 'Instrucciones',
        'download_url' => 'URL de descarga',
        'external_status' => 'Estado reportado',
        'external_status_message' => 'Mensaje reportado',
        'title' => 'Título',
        'body' => 'Texto',
        'image_path' => 'Imagen',
        'audience' => 'Para quién',
        'starts_at' => 'Desde',
        'ends_at' => 'Hasta',
        'is_pinned' => 'Fijado arriba',
        'author_id' => 'Autor',
        'companies' => 'Empresas',
        'position' => 'Posición',
        'role' => 'Rol',
        'abilities' => 'Permisos',
        'allowed_ips' => 'IP permitidas',
        PortalSettings::SUPPORT_URL => 'Dirección de soporte TI',
        PortalSettings::IDLE_TIMEOUT => 'Cierre por inactividad (minutos)',
        PortalSettings::MICROSOFT_LOGOUT => 'Cerrar también Microsoft',
        PortalSettings::ALLOWED_APP_HOSTS => 'Dominios permitidos',
        PortalSettings::API_STATUS_TTL => 'Quitar «Con fallas» después de (horas)',
    ];

    private const MAX_VALUE = 300;

    public function activity(Activity $activity): AuditEntry
    {
        $causer = $activity->causer instanceof User ? $activity->causer : null;
        $system = $activity->causer instanceof Integration ? $activity->causer : null;
        $security = $activity->log_name === SecurityLog::LOG;

        return new AuditEntry(
            AuditEntry::KIND_ACTIVITY,
            (int) $activity->id,
            CarbonImmutable::instance($activity->created_at),
            $causer?->name ?? $system?->name ?? self::SYSTEM,
            $causer?->email,
            match (true) {
                $security => AuditEntry::CATEGORY_SECURITY,
                $activity->log_name === StatusReporter::AUDIT_LOG => AuditEntry::CATEGORY_API,
                default => AuditEntry::CATEGORY_CHANGE,
            },
            $security ? $this->security($activity) : $this->change($activity),
            $causer?->company?->short_name ?: $causer?->company?->name,
            null,
            $security ? [] : $this->changes($activity),
        );
    }

    public function launch(AppLaunch $launch): AuditEntry
    {
        return new AuditEntry(
            AuditEntry::KIND_LAUNCH,
            (int) $launch->id,
            CarbonImmutable::instance($launch->launched_at),
            $launch->user?->name ?? self::SYSTEM,
            $launch->user?->email,
            AuditEntry::CATEGORY_LAUNCH,
            $launch->app?->name ?? 'App eliminada',
            $launch->company?->short_name ?: $launch->company?->name,
            $launch->device,
        );
    }

    private function change(Activity $activity): string
    {
        $event = (string) ($activity->event ?: $activity->description);
        $verb = self::VERBS[$event] ?? null;
        $subject = $this->subject($activity);

        return $verb === null ? trim("{$event} · {$subject}") : "{$verb} {$subject}";
    }

    private function subject(Activity $activity): string
    {
        if ($activity->log_name === PortalSettings::AUDIT_LOG) {
            return 'la configuración del portal';
        }

        if ($activity->log_name === BackupRequests::AUDIT_LOG) {
            return 'un respaldo inmediato de la base de datos';
        }

        $model = $activity->subject;
        $name = fn (string $attribute) => $model?->getAttribute($attribute)
            ?? data_get($activity->properties, "attributes.{$attribute}")
            ?? data_get($activity->properties, "old.{$attribute}");

        return match ($activity->subject_type) {
            (new Company)->getMorphClass() => 'la empresa '.($name('name') ?? '(eliminada)'),
            (new App)->getMorphClass() => 'la app '.($name('name') ?? '(eliminada)'),
            (new AppCategory)->getMorphClass() => 'la categoría '.($name('name') ?? '(eliminada)'),
            (new Announcement)->getMorphClass() => 'el anuncio «'.($name('title') ?? '(eliminado)').'»',
            (new User)->getMorphClass() => $name('name') ?? '(persona eliminada)',
            (new Integration)->getMorphClass() => $name('name') ?? '(sistema eliminado)',
            default => $model instanceof Model ? class_basename($model).' #'.$model->getKey() : '',
        };
    }

    private function security(Activity $activity): string
    {
        $properties = $activity->properties;

        return match ($activity->event) {
            SecurityLog::MICROSOFT => 'Entró con Microsoft'.$this->roleChanges($properties->get('roles_added', []), $properties->get('roles_removed', [])),
            SecurityLog::MICROSOFT_REJECTED => 'Inicio con Microsoft rechazado · '
                .(self::REJECTION_REASONS[$properties->get('reason')] ?? (string) $properties->get('reason'))
                .($properties->get('tenant_id') ? ' · inquilino '.$properties->get('tenant_id') : ''),
            SecurityLog::EMERGENCY => 'Entró con la cuenta de emergencia',
            SecurityLog::EMERGENCY_FAILED => 'Intento fallido con la cuenta de emergencia'
                .($properties->get('email') ? ' ('.$properties->get('email').')' : '')
                .($properties->get('step') === 'code' ? ' · código' : ' · contraseña'),
            SecurityLog::REAUTH_CONFIRMED => 'Confirmó su identidad con Microsoft',
            SecurityLog::ROLES_SYNCED => 'Sincronizó los administradores: '.$properties->get('name')
                .($properties->get('email') ? ' ('.$properties->get('email').')' : '')
                .$this->roleChanges($properties->get('roles_added', []), $properties->get('roles_removed', [])),
            SecurityLog::REAUTH_FAILED => 'Otra cuenta de Microsoft intentó confirmar su identidad, o no fue un inicio reciente',
            SecurityLog::REAUTH_UNAVAILABLE => 'No se pudo comprobar su identidad: falta configurar auth_time en Microsoft',
            SecurityLog::ACCOUNT_LINKED => 'Vinculó su cuenta de '.$this->account($properties->get('linked_company'), $properties->get('linked_email')),
            SecurityLog::ACCOUNT_UNLINKED => 'Desvinculó la cuenta de '.$this->account($properties->get('company'), $properties->get('email'))
                .' · '.(self::UNLINK_REASONS[$properties->get('reason')] ?? (string) $properties->get('reason')),
            default => (string) $activity->description,
        };
    }

    /**
     * «Empresa (correo)» of a linked account.
     */
    private function account(?string $company, ?string $email): string
    {
        return ($company ?? 'otra empresa').($email ? " ({$email})" : '');
    }

    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     */
    private function roleChanges(array $added, array $removed): string
    {
        $label = fn (string $role) => self::ROLE_LABELS[$role] ?? $role;
        $parts = [
            ...array_map(fn (string $role) => 'ahora es '.$label($role), $added),
            ...array_map(fn (string $role) => 'ya no es '.$label($role), $removed),
        ];

        return $parts === [] ? '' : ' · '.implode(', ', $parts);
    }

    /**
     * @return list<array{label: string, old: string, new: string}>
     */
    private function changes(Activity $activity): array
    {
        $old = (array) $activity->properties->get('old', []);
        $new = (array) $activity->properties->get('attributes', []);

        if ($activity->log_name === RoleManager::AUDIT_LOG) {
            $new = ['role' => self::ROLE_LABELS[$activity->properties->get('role')] ?? $activity->properties->get('role')];
        }

        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'label' => self::FIELD_LABELS[$field] ?? (string) $field,
                'old' => self::value($old[$field] ?? null),
                'new' => self::value($new[$field] ?? null),
            ];
        }

        return $rows;
    }

    public static function value(mixed $value): string
    {
        $text = match (true) {
            $value === null, $value === '', $value === [] => '—',
            is_bool($value) => $value ? 'Sí' : 'No',
            is_array($value) => collect($value)->map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE))->implode(', '),
            default => (string) $value,
        };

        return Str::limit($text, self::MAX_VALUE);
    }
}
