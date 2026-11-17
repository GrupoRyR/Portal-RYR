<?php

namespace Tests\Feature\Management;

use App\Management\SapFigures;
use App\Management\SapFigureSet;
use App\Management\SapSeller;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Management\Concerns\FakesBasesSap;
use Tests\TestCase;

/**
 * The figures of the «Panel gerencial» read from the «Bases de datos SAP»
 * views (odd/tasks/panel-gerencial-sap.md, «Reglas de cálculo»): one
 * company, the current month in Bogotá, aggregate queries only.
 */
class SapFiguresTest extends TestCase
{
    use FakesBasesSap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'America/Bogota'));
        $this->fakeBasesSap();
    }

    private function figures(string $company = 'SGX'): ?SapFigureSet
    {
        return app(SapFigures::class)->forCompany($company);
    }

    /**
     * @return list<array{connection: string, sql: string}>
     */
    private function queriesDuring(callable $callback): array
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = ['connection' => $query->connectionName, 'sql' => $query->sql];
        });

        $callback();

        return $queries;
    }

    // --- Venta neta -----------------------------------------------------------

    public function test_net_sales_subtract_credit_notes(): void
    {
        $this->sapSale(['total' => 1000000]);
        $this->sapSale(['tipo_objeto' => 14, 'clase_documento' => 'nota_credito', 'total' => -300000, 'ganancia_bruta' => -75000]);

        $this->assertSame(700000.0, $this->figures()->netSales);
    }

    public function test_a_voided_invoice_and_its_void_cancel_out_without_a_status_filter(): void
    {
        $this->sapSale(['total' => 1000000]);
        $this->sapSale(['estado_documento' => 'anulado', 'total' => 500000, 'ganancia_bruta' => 100000]);
        $this->sapSale(['tipo_objeto' => 14, 'clase_documento' => 'nota_credito', 'estado_documento' => 'anulacion', 'total' => -500000, 'ganancia_bruta' => -100000]);
        $this->sapSale(['estado_documento' => 'abierto', 'total' => 200000]);

        $figures = $this->figures();

        $this->assertSame(1200000.0, $figures->netSales);
        $this->assertSame(500000.0, $figures->grossProfit);
    }

    public function test_other_companies_and_other_months_are_left_out(): void
    {
        $this->sapSale(['total' => 1000000]);
        $this->sapSale(['fecha_contabilizacion' => '2026-11-01', 'total' => 10]);
        $this->sapSale(['fecha_contabilizacion' => '2026-11-30', 'total' => 20]);
        $this->sapSale(['empresa' => 'SDC', 'total' => 9000000]);
        $this->sapSale(['fecha_contabilizacion' => '2026-10-31', 'total' => 8000000]);
        $this->sapSale(['fecha_contabilizacion' => '2026-12-01', 'total' => 7000000]);

        $this->assertSame(1000030.0, $this->figures()->netSales);
        $this->assertSame(9000000.0, $this->figures('SDC')->netSales);
    }

    public function test_the_month_is_the_one_of_bogota(): void
    {
        // 03:00 UTC on 1 December is still 30 November, 22:00, in Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 03:00', 'UTC'));
        $this->sapSale(['fecha_contabilizacion' => '2026-11-30', 'total' => 1000000]);
        $this->sapSale(['fecha_contabilizacion' => '2026-12-01', 'total' => 5000000]);

        $figures = $this->figures();

        $this->assertSame(1000000.0, $figures->netSales);
        $this->assertSame('2026-11-01', $figures->month->toDateString());
    }

    // --- Ganancia bruta y margen ---------------------------------------------

    public function test_the_margin_counts_only_lines_with_gross_profit_and_never_the_cost(): void
    {
        // costo_total would give a 10 % margin: it must never be used.
        $this->sapSale(['total' => 1000000, 'costo_total' => 900000, 'ganancia_bruta' => 250000]);
        $this->sapSale(['total' => 3000000, 'costo_total' => 2700000, 'ganancia_bruta' => null]);

        $figures = $this->figures();

        $this->assertSame(4000000.0, $figures->netSales);
        $this->assertSame(250000.0, $figures->grossProfit);
        $this->assertSame(1000000.0, $figures->salesWithProfit);
        $this->assertSame(0.25, $figures->margin());
        $this->assertSame(0.25, $figures->coverage());
    }

    public function test_every_line_with_gross_profit_covers_the_whole_net_sale(): void
    {
        $this->sapSale(['total' => 1000000, 'ganancia_bruta' => 300000]);
        $this->sapSale(['tipo_objeto' => 14, 'clase_documento' => 'nota_credito', 'total' => -200000, 'ganancia_bruta' => -60000]);

        $figures = $this->figures();

        $this->assertSame(240000.0, $figures->grossProfit);
        $this->assertEqualsWithDelta(0.3, $figures->margin(), 0.000001);
        $this->assertSame(1.0, $figures->coverage());
    }

    public function test_without_lines_with_gross_profit_there_is_no_margin(): void
    {
        $this->sapSale(['total' => 1000000, 'ganancia_bruta' => null]);

        $figures = $this->figures();

        $this->assertSame(0.0, $figures->grossProfit);
        $this->assertNull($figures->margin());
        $this->assertSame(0.0, $figures->coverage());
    }

    public function test_a_month_without_sales_has_zeros_and_no_margin(): void
    {
        $figures = $this->figures();

        $this->assertSame(0.0, $figures->netSales);
        $this->assertNull($figures->margin());
        $this->assertNull($figures->coverage());
        $this->assertSame([], $figures->topSellers);
    }

    // --- Cartera --------------------------------------------------------------

    public function test_receivables_add_up_the_balance_by_range_of_the_company(): void
    {
        $this->sapReceivable(['sin_vencer' => 100000]);
        $this->sapReceivable(['sin_vencer' => 0, 'd_0_30' => 20000, 'd_31_60' => 30000]);
        $this->sapReceivable(['sin_vencer' => 0, 'd_61_90' => 1, 'd_91_120' => 2, 'd_121_150' => 3, 'd_151_180' => 4, 'd_mas_180' => 5000]);
        $this->sapReceivable(['empresa' => 'SDC', 'sin_vencer' => 999999]);

        $figures = $this->figures();

        $this->assertSame(155010.0, $figures->receivables);
        $this->assertSame([
            'sin_vencer' => 100000.0,
            'd_0_30' => 20000.0,
            'd_31_60' => 30000.0,
            'd_61_90' => 1.0,
            'd_91_120' => 2.0,
            'd_121_150' => 3.0,
            'd_151_180' => 4.0,
            'd_mas_180' => 5000.0,
        ], $figures->ranges);
        $this->assertSame(155010.0, $figures->rangesTotal());
        $this->assertSame(0.0, $figures->rangesDifference());
        $this->assertTrue($figures->rangesMatch());
        $this->assertSame(999999.0, $this->figures('SDC')->receivables);
    }

    public function test_ranges_that_do_not_add_up_to_the_balance_are_flagged(): void
    {
        $this->sapReceivable(['sin_vencer' => 100000, 'saldo' => 150000]);

        $figures = $this->figures();

        $this->assertSame(150000.0, $figures->receivables);
        $this->assertSame(50000.0, $figures->rangesDifference());
        $this->assertFalse($figures->rangesMatch());
    }

    public function test_a_difference_of_cents_still_matches(): void
    {
        $this->sapReceivable(['sin_vencer' => 100000.40, 'saldo' => 100000.00]);
        $this->sapReceivable(['sin_vencer' => 0, 'd_0_30' => 50000.33, 'saldo' => 50000.00]);

        $figures = $this->figures();

        $this->assertEqualsWithDelta(-0.73, $figures->rangesDifference(), 0.0001);
        $this->assertTrue($figures->rangesMatch());
    }

    // --- Top 5 vendedores ------------------------------------------------------

    public function test_the_top_five_sellers_by_net_sales_of_the_company(): void
    {
        foreach ([[1, 'Uno', 100], [2, 'Dos', 600], [3, 'Tres', 300], [4, 'Cuatro', 500], [5, 'Cinco', 200], [6, 'Seis', 400]] as [$code, $name, $total]) {
            $this->sapSale(['slp_code' => $code, 'vendedor' => $name, 'total' => $total]);
        }
        // A credit note lowers its seller: «Dos» ends at 250, below «Tres».
        $this->sapSale(['slp_code' => 2, 'vendedor' => 'Dos', 'tipo_objeto' => 14, 'clase_documento' => 'nota_credito', 'total' => -350]);
        // Another company's seller with the same code never mixes in.
        $this->sapSale(['empresa' => 'SDC', 'slp_code' => 1, 'vendedor' => 'Uno SDC', 'total' => 100000]);
        // Another month neither.
        $this->sapSale(['fecha_contabilizacion' => '2026-10-15', 'slp_code' => 1, 'vendedor' => 'Uno', 'total' => 100000]);

        $top = $this->figures()->topSellers;

        $this->assertCount(SapFigures::TOP_SELLERS, $top);
        $this->assertContainsOnlyInstancesOf(SapSeller::class, $top);
        $this->assertSame(['Cuatro', 'Seis', 'Tres', 'Dos', 'Cinco'], array_map(fn (SapSeller $seller) => $seller->name, $top));
        $this->assertSame([500.0, 400.0, 300.0, 250.0, 200.0], array_map(fn (SapSeller $seller) => $seller->netSales, $top));
        $this->assertSame([4, 6, 3, 2, 5], array_map(fn (SapSeller $seller) => $seller->code, $top));
    }

    public function test_sales_without_a_seller_show_as_sin_vendedor(): void
    {
        $this->sapSale(['slp_code' => -1, 'vendedor' => null, 'total' => 900]);
        $this->sapSale(['slp_code' => 7, 'vendedor' => 'Ana Ruiz', 'total' => 100]);
        $this->sapSale(['slp_code' => 8, 'vendedor' => null, 'total' => 50]);

        $top = $this->figures()->topSellers;

        $this->assertSame(['Sin vendedor', 'Ana Ruiz', 'Vendedor 8'], array_map(fn (SapSeller $seller) => $seller->name, $top));
    }

    // --- Queries, cache and failures -------------------------------------------

    public function test_every_query_is_an_aggregate_of_a_view_filtered_by_company(): void
    {
        $this->sapSale();
        $this->sapReceivable();

        $queries = $this->queriesDuring(fn () => $this->figures());

        $this->assertCount(3, $queries);
        $this->assertEqualsCanonicalizing(['sap_ventas', 'sap_ventas', 'sap_cartera'], array_column($queries, 'connection'));

        foreach (array_column($queries, 'sql') as $sql) {
            $this->assertMatchesRegularExpression('/from "v_(ventas|cartera)"/', $sql);
            $this->assertStringContainsString('"empresa" = ?', $sql);
            $this->assertStringContainsString('sum(', strtolower($sql));
            $this->assertStringNotContainsString('*', $sql);
            $this->assertStringNotContainsString('costo_total', $sql);
            $this->assertStringNotContainsString('estado_documento', $sql);
        }
    }

    public function test_the_figures_are_cached_per_company_until_forgotten(): void
    {
        $this->sapSale(['total' => 1000]);
        $this->assertSame(1000.0, $this->figures()->netSales);

        $this->sapSale(['total' => 500]);
        $this->assertSame([], $this->queriesDuring(fn () => $this->assertSame(1000.0, $this->figures()->netSales)));
        $this->assertSame(0.0, $this->figures('SDC')->netSales, 'Each company has its own entry.');

        app(SapFigures::class)->forget('SGX');
        $this->assertSame(1500.0, $this->figures()->netSales);

        $this->travel(SapFigures::CACHE_SECONDS + 1)->seconds();
        $this->sapSale(['total' => 1]);
        $this->assertSame(1501.0, $this->figures()->netSales);
    }

    public function test_without_configuration_nothing_is_read(): void
    {
        config(['database.connections.sap_cartera.username' => null]);

        $this->assertFalse(app(SapFigures::class)->configured());
        $this->assertSame([], $this->queriesDuring(fn () => $this->assertNull($this->figures())));
    }

    public function test_each_part_of_the_configuration_is_required(): void
    {
        $this->assertTrue(app(SapFigures::class)->configured());

        foreach (['host', 'username', 'database'] as $key) {
            foreach (['sap_ventas', 'sap_cartera'] as $connection) {
                $this->fakeBasesSap(withViews: false);
                config(["database.connections.{$connection}.{$key}" => '']);

                $this->assertFalse(app(SapFigures::class)->configured(), "{$connection}.{$key}");
            }
        }
    }

    public function test_an_unknown_company_code_reads_nothing(): void
    {
        $this->assertSame([], $this->queriesDuring(fn () => $this->assertNull($this->figures('XYZ'))));
    }

    public function test_a_failing_connection_is_reported_and_gives_no_figures(): void
    {
        Exceptions::fake();
        $this->fakeBasesSap(withViews: false);

        $this->assertNull($this->figures());

        Exceptions::assertReported(QueryException::class);
        // A failure is remembered briefly, so a broken server does not slow every page.
        $this->assertSame([], $this->queriesDuring(fn () => $this->assertNull($this->figures())));
    }

    public function test_the_company_code_comes_from_the_slug(): void
    {
        $this->assertSame('SGX', (new Company(['slug' => 'segurex']))->sapCode());
        $this->assertSame('SDC', (new Company(['slug' => 'derivados']))->sapCode());
        $this->assertNull((new Company(['slug' => 'otra']))->sapCode());
    }
}
