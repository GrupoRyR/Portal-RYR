<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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

    /** Colors before the manual (brief, 2026-10-05). */
    private const PREVIOUS = [
        'segurex' => ['color_primary' => '#FF671F', 'color_accent' => '#666666', 'color_dark' => '#8F360B'],
        'derivados' => ['color_primary' => '#183F94', 'color_accent' => '#3E60A8', 'color_dark' => '#03256C'],
    ];

    public function up(): void
    {
        foreach (self::MANUAL as $slug => $colors) {
            DB::table('companies')->where('slug', $slug)->update([...$colors, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS as $slug => $colors) {
            DB::table('companies')->where('slug', $slug)->update([
                ...$colors,
                'color_button' => null,
                'color_ink' => null,
                'color_text' => null,
                'color_ink_dark_mode' => null,
                'color_text_dark_mode' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
