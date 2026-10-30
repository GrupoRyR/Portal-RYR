<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Whether the Claude connector is on for the whole portal: the one place
 * that decides (middleware `mcp.enabled`, /cuenta, Configuración).
 *
 * It is on only while both are on:
 * - the deploy flag PORTAL_MCP_ENABLED (`portal.mcp.enabled`), the hard
 *   kill switch of the server: off, nothing else is read;
 * - the Admin toggle «Conector de Claude» (Configuración, super admins),
 *   stored in `portal_settings` under {@see SETTING}. Never saved means on,
 *   so the deploy flag alone decides until someone turns it off; a saved
 *   value other than JSON `true` is off.
 *
 * The toggle is read from the table on every request that asks (one
 * primary-key lookup, kept for the life of this object), never from a
 * cache: turning it off closes every connector route on the very next
 * request of any PHP process, with no stale copy anywhere. The production
 * cache store is the database too, so a cache would cost the same query.
 */
class McpSwitch
{
    /** Key of the Admin toggle in `portal_settings` (JSON true/false). */
    public const SETTING = 'mcp_enabled';

    /** Activity log event of the toggle (log `security`). */
    public const SWITCHED = 'mcp.switched';

    private ?bool $turnedOn = null;

    public function enabled(): bool
    {
        return $this->deployed() && $this->turnedOn();
    }

    /**
     * The deploy flag PORTAL_MCP_ENABLED.
     */
    public function deployed(): bool
    {
        return config('portal.mcp.enabled') === true;
    }

    /**
     * The Admin toggle; on while it was never saved. A saved value is on
     * only when it is exactly JSON `true`: anything else (`false`, `0`,
     * `off`, a hand-edited or corrupt value) is off, so it fails closed.
     */
    public function turnedOn(): bool
    {
        if ($this->turnedOn !== null) {
            return $this->turnedOn;
        }

        $stored = DB::table('portal_settings')->where('key', self::SETTING)->value('value');

        return $this->turnedOn = $stored === null || json_decode((string) $stored) === true;
    }

    /**
     * Turns the Admin toggle on or off and audits it (`mcp.switched`, with
     * the deploy flag at that moment). Returns false, writing nothing, when
     * it already was that way.
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
                ->withProperties(['enabled' => $on, 'deployed' => $this->deployed()])
                ->log(self::SWITCHED);
        });

        $this->turnedOn = $on;

        return true;
    }
}
