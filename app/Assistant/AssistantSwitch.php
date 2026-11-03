<?php

namespace App\Assistant;

use App\Assistant\Model\ProviderRegistry;
use App\Audit\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether the portal assistant (/asistente) is on: the one place that
 * decides, for the page, its entry links and Configuración.
 *
 * It is on only while both are on:
 * - the deploy flag PORTAL_ASSISTANT_ENABLED (`assistant.enabled`), the
 *   hard kill switch of the server: off, nothing else is read;
 * - the Admin toggle «Asistente» (Configuración, super admins), stored in
 *   `portal_settings` under {@see SETTING}. Unlike the Claude connector
 *   (App\Mcp\McpSwitch), never saved means OFF: each question costs money
 *   and sends portal data to Anthropic, so someone has to turn it on.
 *   Only a saved JSON `true` is on, so a corrupt value fails closed.
 *
 * Read from the table on every request that asks (one primary-key lookup,
 * kept for the life of this object), never from a cache, like McpSwitch.
 *
 * Whether the API key is present ({@see configured()}) is separate: the
 * page exists while the switch is on, and says the assistant is not
 * available while the key is missing.
 */
class AssistantSwitch
{
    /** Key of the Admin toggle in `portal_settings` (JSON true/false). */
    public const SETTING = 'assistant_enabled';

    /** Activity log event of the toggle (log `security`). */
    public const SWITCHED = 'assistant.switched';

    private ?bool $turnedOn = null;

    public function enabled(): bool
    {
        return $this->deployed() && $this->turnedOn();
    }

    /**
     * The deploy flag PORTAL_ASSISTANT_ENABLED.
     */
    public function deployed(): bool
    {
        return config('assistant.enabled') === true;
    }

    /**
     * The readers' and the editors' conversations each have a provider
     * with its key: the primary (ANTHROPIC_API_KEY by default) or, when
     * set, the failover secondary (App\Assistant\Model\ProviderRegistry).
     */
    public function configured(): bool
    {
        $settings = AssistantSettings::fromConfig();
        $registry = app(ProviderRegistry::class);

        $served = fn (bool $editor) => $registry->configured(($editor ? $settings->editorModel : $settings->model)->provider)
            || (($secondary = $settings->secondaryFor($editor)) !== null && $registry->configured($secondary->provider));

        return $served(false) && $served(true);
    }

    /**
     * The Admin toggle; off while it was never saved.
     */
    public function turnedOn(): bool
    {
        if ($this->turnedOn !== null) {
            return $this->turnedOn;
        }

        $stored = DB::table('portal_settings')->where('key', self::SETTING)->value('value');

        return $this->turnedOn = $stored !== null && json_decode((string) $stored) === true;
    }

    /**
     * Turns the Admin toggle on or off and audits it (`assistant.switched`,
     * with the deploy flag and the key at that moment). Returns false,
     * writing nothing, when it already was that way.
     */
    public function turn(bool $on, User $by): bool
    {
        $this->turnedOn = null;

        if ($this->turnedOn() === $on) {
            return false;
        }

        DB::transaction(function () use ($on, $by) {
            DB::table('portal_settings')->updateOrInsert(
                ['key' => self::SETTING],
                ['value' => json_encode($on), 'updated_at' => now()],
            );

            activity(SecurityLog::LOG)
                ->event(self::SWITCHED)
                ->causedBy($by)
                ->performedOn($by)
                ->withProperties(['enabled' => $on, 'deployed' => $this->deployed(), 'configured' => $this->configured()])
                ->log(self::SWITCHED);
        });

        $this->turnedOn = $on;

        return true;
    }
}
