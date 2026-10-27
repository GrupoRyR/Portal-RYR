<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

return new class extends Migration
{
    /**
     * Official brand manual SGX/SDC (2026-10-23), rows matched by slug:
     * accent, button, ink, text and dark, plus the manual's dark-mode ink
     * and text. Other companies keep their colors.
     */
    private const MANUAL = [
        'segurex' => [
            'color_primary' => '#F26522',
            'color_accent' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
            'color_ink' => '#943E15',
            'color_text' => '#58595B',
            'color_ink_dark_mode' => '#F26522',
            'color_text_dark_mode' => '#A8A9AA',
        ],
        'derivados' => [
            'color_primary' => '#0B5FA5',
            'color_accent' => '#0B5FA5',
            'color_dark' => '#073B66',
            'color_button' => '#0B5FA5',
            'color_ink' => '#073B66',
            'color_text' => '#073B66',
            'color_ink_dark_mode' => '#4D8ABD',
            'color_text_dark_mode' => '#4D8ABD',
        ],
    ];

    /** Activity log of companies (App\Models\Company::AUDIT_LOG). */
    private const AUDIT_LOG = 'companies';

    /** Colors before the manual (brief, 2026-10-05). */
    private const PREVIOUS = [
        'segurex' => ['color_primary' => '#FF671F', 'color_accent' => '#666666', 'color_dark' => '#8F360B'],
        'derivados' => ['color_primary' => '#183F94', 'color_accent' => '#3E60A8', 'color_dark' => '#03256C'],
    ];

    public function up(): void
    {
        foreach (self::MANUAL as $slug => $colors) {
            $this->recolor($slug, $colors);
        }
    }

    public function down(): void
    {
        $noManualColors = array_fill_keys(['color_button', 'color_ink', 'color_text', 'color_ink_dark_mode', 'color_text_dark_mode'], null);

        foreach (self::PREVIOUS as $slug => $colors) {
            $this->recolor($slug, [...$colors, ...$noManualColors]);
        }
    }

    /**
     * Updates the row through the query builder (the migration must not
     * depend on how the model evolves) and writes the same "updated" entry
     * the model would, with no causer: Auditoría shows it as «Sistema».
     *
     * @param  array<string, ?string>  $colors
     */
    private function recolor(string $slug, array $colors): void
    {
        $row = DB::table('companies')->where('slug', $slug)->first();

        if ($row === null) {
            return;
        }

        $old = array_intersect_key((array) $row, $colors);
        $changed = array_diff_assoc($colors, $old);

        DB::table('companies')->where('id', $row->id)->update([...$colors, 'updated_at' => now()]);

        if ($changed === []) {
            return;
        }

        activity(self::AUDIT_LOG)
            ->causedByAnonymous()
            ->event('updated')
            ->withProperties([
                'old' => array_intersect_key($old, $changed),
                'attributes' => $changed,
            ])
            ->tap(function (Activity $activity) use ($row) {
                $activity->subject_type = Company::class;
                $activity->subject_id = $row->id;
            })
            ->log('updated');
    }
};
