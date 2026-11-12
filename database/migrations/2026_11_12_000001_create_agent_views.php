<?php

use App\Agent\Views\AgentViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The read-only views of the multi-agent system (App\Agent\Views\AgentViews):
     * the helper `aux_empresa` and the five `v_*` views. Created on
     * MariaDB/MySQL, and on SQLite so the test database has them. Dropping
     * first makes running it again harmless. Granting SELECT on `v_*` to the
     * agent's database user is a manual step of IT (docs/).
     */
    public function up(): void
    {
        AgentViews::install(DB::connection());
    }

    public function down(): void
    {
        AgentViews::drop(DB::connection());
    }
};
