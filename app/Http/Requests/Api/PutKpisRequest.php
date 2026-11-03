<?php

namespace App\Http\Requests\Api;

use App\Models\CompanyKpi;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `PUT /api/v1/kpis` (docs/api.md §8): the whole set of management
 * figures of the token's company. The company never comes from the body;
 * unknown fields are dropped.
 *
 * No message repeats a submitted value: the figures are confidential and
 * the response may end up in the robot's logs.
 */
class PutKpisRequest extends FormRequest
{
    /** ISO 8601 date and time with a time zone, seconds optional. */
    private const DATE_TIME = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-](\d{2}):(\d{2}))\z/';

    /**
     * Control characters, line and paragraph separators, bidi overrides,
     * isolates and marks, the zero-width space and the byte order mark: all
     * of them can disguise a label (same rule as the work item titles).
     */
    private const NOT_ONE_LINE = '/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{200E}\x{200F}\x{061C}\x{FEFF}]/u';

    /**
     * The `kpis:write` ability is checked by the route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > CompanyKpi::MAX_BODY_BYTES, 413, 'The request body must not be larger than 8 KB.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period_label' => ['bail', 'required', 'string', 'max:'.CompanyKpi::MAX_PERIOD_LABEL, $this->oneLine(...)],
            'as_of' => ['bail', 'required', $this->dateTimeWithZone(...)],
            'source_label' => ['bail', 'required', 'string', 'max:'.CompanyKpi::MAX_SOURCE_LABEL, $this->oneLine(...)],
            'items' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.CompanyKpi::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            'items.*.key' => ['bail', 'required', 'string', 'max:'.CompanyKpi::MAX_KEY, 'regex:'.CompanyKpi::KEY_PATTERN, 'distinct'],
            'items.*.label' => ['bail', 'required', 'string', 'max:'.CompanyKpi::MAX_LABEL, $this->oneLine(...)],
            'items.*.value' => ['bail', 'required', $this->number(...)],
            'items.*.unit' => ['bail', 'nullable', 'string', 'max:'.CompanyKpi::MAX_UNIT, $this->oneLine(...)],
            'items.*.goal' => ['bail', 'nullable', $this->number(...)],
            'items.*.note' => ['bail', 'nullable', 'string', 'max:'.CompanyKpi::MAX_NOTE, $this->oneLine(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.key.regex' => 'The key must be a lowercase slug: letters, digits, and single - or _ between them.',
            'items.*.key.distinct' => 'The key is repeated in the request.',
        ];
    }

    private function oneLine(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::NOT_ONE_LINE, $value) !== 0) {
            $fail("The {$attribute} field must be one line of plain text, without control characters.");
        }
    }

    /**
     * A JSON number (never a string or a boolean) small enough for the
     * column.
     */
    private function number(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs((float) $value) > CompanyKpi::MAX_ABS_VALUE) {
            $fail("The {$attribute} field must be a number between -10^15 and 10^15.");
        }
    }

    private function dateTimeWithZone(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isDateTimeWithZone($value)) {
            $fail("The {$attribute} field must be an ISO 8601 date and time with a time zone.");
        }
    }

    private static function isDateTimeWithZone(string $value): bool
    {
        if (preg_match(self::DATE_TIME, $value, $part) !== 1) {
            return false;
        }

        [, $year, $month, $day, $hour, $minute] = $part;
        $second = $part[6] ?? '';
        $offsetHours = $part[7] ?? '';
        $offsetMinutes = $part[8] ?? '';

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $hour <= 23 && (int) $minute <= 59 && (int) ($second ?: 0) <= 59
            && (int) ($offsetHours ?: 0) <= 14 && (int) ($offsetMinutes ?: 0) <= 59;
    }
}
