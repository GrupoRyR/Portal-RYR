<?php

namespace App\Calendar;

use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * «Agregar a mi calendario»: one event as an iCalendar file (RFC 5545)
 * that Outlook, Google Calendar and phones import. Timed events are in UTC
 * (`Z`), so no VTIMEZONE block is needed; all-day events are dates
 * (`VALUE=DATE`) with the exclusive end the day after the last one. Text
 * is escaped, lines are folded at 75 octets and end in CRLF.
 */
final class EventCalendarFile
{
    public const CONTENT_TYPE = 'text/calendar; charset=utf-8';

    private const LINE_OCTETS = 75;

    /**
     * @param  string  $host  Makes the UID unique to this portal (`event-{id}@{host}`).
     */
    public static function for(Event $event, string $host, CarbonInterface $stamp): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Grupo RYR//Portal RYR//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-'.$event->id.'@'.$host,
            'DTSTAMP:'.self::utc($stamp),
        ];

        if ($event->all_day) {
            $lines[] = 'DTSTART;VALUE=DATE:'.self::date($event->starts_at);
            $lines[] = 'DTEND;VALUE=DATE:'.self::date($event->lastDay()->addDay());
        } else {
            $lines[] = 'DTSTART:'.self::utc($event->starts_at);
            // Without an end the event is a moment (RFC 5545 §3.6.1).
            if ($event->ends_at !== null) {
                $lines[] = 'DTEND:'.self::utc($event->ends_at);
            }
        }

        $lines[] = 'SUMMARY:'.self::text((string) $event->title);

        foreach (['DESCRIPTION' => $event->description, 'LOCATION' => $event->location] as $name => $value) {
            if (trim((string) $value) !== '') {
                $lines[] = $name.':'.self::text((string) $value);
            }
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(fn (string $line) => self::fold($line)."\r\n", $lines));
    }

    /**
     * «reunion-general.ics»; «evento-12.ics» when the title has no letters.
     */
    public static function filename(Event $event): string
    {
        $slug = Str::limit(Str::slug((string) $event->title), 60, '');

        return ($slug !== '' ? rtrim($slug, '-') : 'evento-'.$event->id).'.ics';
    }

    /**
     * A TEXT value (RFC 5545 §3.3.11): backslash, semicolon, comma and
     * line breaks escaped; other control characters dropped.
     */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value) ?? '';

        return strtr($value, ['\\' => '\\\\', ';' => '\;', ',' => '\,', "\n" => '\n']);
    }

    /**
     * Lines longer than 75 octets go on as continuation lines that start
     * with a space, never cutting a UTF-8 character in two.
     */
    public static function fold(string $line): string
    {
        $parts = [];
        $current = '';

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > self::LINE_OCTETS) {
                $parts[] = $current;
                $current = ' ';
            }
            $current .= $character;
        }
        $parts[] = $current;

        return implode("\r\n", $parts);
    }

    private static function utc(CarbonInterface $moment): string
    {
        return $moment->toImmutable()->setTimezone('UTC')->format('Ymd\THis\Z');
    }

    /**
     * The calendar day in the app timezone, where all-day events live.
     */
    private static function date(CarbonInterface $day): string
    {
        return $day->toImmutable()->setTimezone(config('app.timezone'))->format('Ymd');
    }
}
