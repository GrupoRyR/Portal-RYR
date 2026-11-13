<?php

namespace Tests\Unit\View;

use App\View\Home\AppsView;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The views of «Mis aplicaciones» (odd/tasks/vistas-apps.md V1): one class
 * owns the allowed values and the default.
 */
class AppsViewTest extends TestCase
{
    public function test_the_allowed_values_are_the_four_views(): void
    {
        $this->assertSame(['categories', 'all', 'list', 'launcher'], AppsView::values());
    }

    public function test_the_default_is_by_categories(): void
    {
        $this->assertSame(AppsView::Categories, AppsView::DEFAULT);
    }

    #[DataProvider('storedValues')]
    public function test_a_stored_value_reads_back_as_its_view_or_the_default(?string $stored, AppsView $expected): void
    {
        $this->assertSame($expected, AppsView::fromStored($stored));
    }

    /**
     * @return array<string, array{0: string|null, 1: AppsView}>
     */
    public static function storedValues(): array
    {
        return [
            'never chosen' => [null, AppsView::Categories],
            'categories' => ['categories', AppsView::Categories],
            'all' => ['all', AppsView::All],
            'list' => ['list', AppsView::List],
            'launcher' => ['launcher', AppsView::Launcher],
            'a value no longer offered' => ['mosaic', AppsView::Categories],
            'empty' => ['', AppsView::Categories],
        ];
    }

    public function test_only_the_categories_view_groups_the_apps(): void
    {
        $this->assertFalse(AppsView::Categories->isFlat());
        $this->assertTrue(AppsView::All->isFlat());
        $this->assertTrue(AppsView::List->isFlat());
        $this->assertTrue(AppsView::Launcher->isFlat());
    }

    public function test_each_view_has_its_spanish_label(): void
    {
        $this->assertSame(
            ['Categorías', 'Todas', 'Lista', 'Íconos'],
            array_map(fn (AppsView $view) => $view->label(), AppsView::cases()),
        );
    }
}
