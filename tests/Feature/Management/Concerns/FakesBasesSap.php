<?php

namespace Tests\Feature\Management\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stands in for the «Bases de datos SAP» views (docs/guia-apps.md of that
 * repository): the connections `sap_ventas`, `sap_cartera` and
 * `sap_maestros` point at in-memory SQLite databases, and `v_ventas` and
 * `v_cartera` are plain tables with the columns the Portal reads (plus
 * `costo_total`, which it must never read). Nothing touches a real server.
 */
trait FakesBasesSap
{
    /** The connections of config/database.php, each its own in-memory database. */
    private const SAP_CONNECTIONS = ['sap_ventas', 'sap_cartera', 'sap_maestros'];

    private function fakeBasesSap(bool $withViews = true): void
    {
        foreach (self::SAP_CONNECTIONS as $connection) {
            config(["database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
                // What makes a connection «configured» in production.
                'host' => 'localhost',
                'username' => 'portal_lectura',
            ]]);
            DB::purge($connection);
        }

        if (! $withViews) {
            return;
        }

        Schema::connection('sap_ventas')->create('v_ventas', function (Blueprint $table) {
            $table->string('empresa', 3);
            $table->integer('tipo_objeto');
            $table->integer('doc_entry');
            $table->integer('linea');
            $table->string('clase_documento');
            $table->string('estado_documento');
            $table->date('fecha_contabilizacion');
            $table->integer('slp_code');
            $table->string('vendedor')->nullable();
            $table->decimal('total', 19, 2);
            $table->decimal('costo_total', 19, 2)->nullable();
            $table->decimal('ganancia_bruta', 19, 2)->nullable();
        });

        Schema::connection('sap_cartera')->create('v_cartera', function (Blueprint $table) {
            $table->string('empresa', 3);
            $table->string('codigo_sn');
            $table->string('documento');
            $table->integer('slp_code')->nullable();
            foreach (['sin_vencer', 'd_0_30', 'd_31_60', 'd_61_90', 'd_91_120', 'd_121_150', 'd_151_180', 'd_mas_180', 'saldo'] as $column) {
                $table->decimal($column, 19, 2)->nullable();
            }
        });
    }

    /**
     * One line of an invoice (or of a credit note, with a negative total).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sapSale(array $attributes = []): void
    {
        static $docEntry = 0;
        $docEntry++;

        DB::connection('sap_ventas')->table('v_ventas')->insert([
            'empresa' => 'SGX',
            'tipo_objeto' => 13,
            'doc_entry' => $docEntry,
            'linea' => 0,
            'clase_documento' => 'factura',
            'estado_documento' => 'cerrado',
            'fecha_contabilizacion' => '2026-11-10',
            'slp_code' => 7,
            'vendedor' => 'Ana Ruiz',
            'total' => 1000000,
            'costo_total' => 900000,
            'ganancia_bruta' => 250000,
            ...$attributes,
        ]);
    }

    /**
     * One document of the receivables: by default 100.000 not yet due.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sapReceivable(array $attributes = []): void
    {
        static $document = 0;
        $document++;

        $ranges = ['sin_vencer' => 0, 'd_0_30' => 0, 'd_31_60' => 0, 'd_61_90' => 0, 'd_91_120' => 0, 'd_121_150' => 0, 'd_151_180' => 0, 'd_mas_180' => 0];
        $row = [...$ranges, 'sin_vencer' => 100000, ...$attributes];

        DB::connection('sap_cartera')->table('v_cartera')->insert([
            'empresa' => 'SGX',
            'codigo_sn' => 'C0001',
            'documento' => (string) $document,
            'slp_code' => 7,
            // By default the ranges add up to the balance.
            'saldo' => array_sum(array_intersect_key($row, $ranges)),
            ...$row,
        ]);
    }
}
