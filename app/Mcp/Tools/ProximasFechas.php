<?php

namespace App\Mcp\Tools;

use App\Calendar\ColombianHolidays;
use App\Mcp\SeenByAccounts;
use App\Models\Event;
use App\View\Home\EventDate;
use App\View\Home\HolidayDay;
use App\View\Home\HomePageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `proximas_fechas`: «Próximas fechas» of the home screen over a longer
 * window, for every account of the call:
 *
 * - the Colombian holidays (App\Calendar\ColombianHolidays, grouped by day
 *   with their «Festivo» / «Puente festivo» kind by App\View\Home\HolidayDay),
 *   the same for every company, so listed with all of the call's companies;
 * - the events each account sees (Event::visibleSoonest(), with yearly ones
 *   on their next occurrence by App\View\Home\EventDate), each once with the
 *   companies of the accounts that see it.
 *
 * From today to `dias` days ahead (both included; an event under way
 * counts), soonest first in the home screen's order
 * (HomePageBuilder::sortKey()), at most LIMIT. One query per account.
 */
#[Name('proximas_fechas')]
#[Description('Próximas fechas del Portal RYR: festivos de Colombia («Festivo» o «Puente festivo» cuando cae lunes o viernes), eventos de las empresas de esta conexión y fechas que se repiten cada año («Cada año»). Desde hoy hasta `dias` días adelante (de 1 a 90, 30 si no se indica), de la más próxima a la más lejana, máximo 30. Cada fecha trae tipo, título, fecha (ISO 8601, hora de Bogotá) y su texto en español, lugar, descripción, empresas y enlace a /eventos.')]
#[IsReadOnly]
class ProximasFechas extends PortalTool
{
    public const LIMIT = 30;

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 90;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $days = (int) ($request->validate(
            ['dias' => ['sometimes', 'integer', 'between:1,'.self::MAX_DAYS]],
            self::messages(),
        )['dias'] ?? self::DEFAULT_DAYS);

        $today = self::today();
        $last = $today->addDays($days);

        /** @var SeenByAccounts<HolidayDay|EventDate> $seen */
        $seen = new SeenByAccounts;

        foreach (self::holidays($today, $last) as $holiday) {
            foreach ($accounts as $account) {
                $seen->add('holiday:'.$holiday->isoDate(), self::company($account), $holiday);
            }
        }

        foreach ($accounts as $account) {
            $events = Event::visibleSoonest($account, self::LIMIT, ['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly']);

            foreach (EventDate::soonestFirst($events, $today) as $event) {
                if ($event->start < $last->addDay()) {
                    $seen->add('event:'.$event->id, self::company($account), $event);
                }
            }
        }

        $entries = $seen->all();
        usort($entries, fn (array $a, array $b) => HomePageBuilder::sortKey($a['item']) <=> HomePageBuilder::sortKey($b['item']));

        return Response::structured([
            'fechas' => array_map(
                fn (array $entry) => self::describe($entry['item'], $entry['companies'], $today),
                array_slice($entries, 0, self::LIMIT),
            ),
            'limite' => self::LIMIT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()
                ->min(1)
                ->max(self::MAX_DAYS)
                ->default(self::DEFAULT_DAYS)
                ->description('Cuántos días hacia adelante mirar, desde hoy (de 1 a 90).'),
        ];
    }

    /**
     * Days off from today to $last, grouped by day; none past the
     * calendar's supported range, as on the home screen.
     *
     * @return list<HolidayDay>
     */
    private static function holidays(CarbonImmutable $today, CarbonImmutable $last): array
    {
        try {
            return HolidayDay::group(app(ColombianHolidays::class)->between($today, $last), $today);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * @param  list<string>  $companies
     * @return array<string, mixed>
     */
    private static function describe(HolidayDay|EventDate $date, array $companies, CarbonImmutable $today): array
    {
        if ($date instanceof HolidayDay) {
            return [
                'tipo' => $date->kind(),
                'titulo' => $date->label(),
                'fecha' => self::iso($date->date),
                // As EventDate::when() writes an all-day event.
                'fecha_texto' => Str::ucfirst($date->longDate())
                    .($date->date->year === $today->year ? '' : " de {$date->date->year}")
                    .' · Todo el día',
                'lugar' => null,
                'descripcion' => null,
                'empresas' => $companies,
                'url' => null,
            ];
        }

        return [
            'tipo' => $date->kind(),
            'titulo' => $date->title,
            'fecha' => self::iso($date->start),
            'fecha_texto' => $date->listedWhen(),
            'lugar' => $date->location,
            'descripcion' => $date->description,
            'empresas' => $companies,
            'url' => $date->href(),
        ];
    }
}
