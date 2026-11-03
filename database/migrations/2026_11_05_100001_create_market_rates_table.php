<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The market figures of the home band (odd/tasks/mercados.md): the TRM
     * and the euro in Colombian pesos, copied twice a day by
     * `portal:sync-markets` (App\Markets\MarketSync). One row per code and
     * start date, so a run that repeats a day updates its row.
     */
    public function up(): void
    {
        Schema::create('market_rates', function (Blueprint $table) {
            $table->id();
            // `trm` or `eur` (App\Models\MarketRate::CODES).
            $table->string('code', 8);
            // Colombian pesos.
            $table->decimal('value', 14, 4);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            // Where it came from: «datos.gov.co» or «ecb.europa.eu».
            $table->string('source', 40);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['code', 'valid_from'], 'market_rates_code_from_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_rates');
    }
};
