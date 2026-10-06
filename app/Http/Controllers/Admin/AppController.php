<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppCategory;
use App\Models\Company;
use App\View\Admin\AppAdminRow;
use App\View\Admin\AppAdminStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Apps administration (brief §5.2, docs/diseno/AdminApps.dc.html): summary
 * tiles and the filtered list. Filters are GET parameters so a filtered
 * view can be bookmarked.
 */
class AppController extends Controller
{
    public function index(Request $request): View
    {
        $companies = Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'slug']);
        $categories = AppCategory::query()->ordered()->get(['id', 'name', 'slug']);

        // Only plain strings are read: `?estado[]=x` is ignored, not an error.
        $param = fn (string $key) => is_string($value = $request->query($key)) ? trim($value) : '';

        $filters = [
            'q' => mb_substr($param('q'), 0, 100),
            'empresa' => $companies->firstWhere('slug', $param('empresa'))?->slug,
            'estado' => AppAdminStatus::tryFrom($param('estado'))?->value,
            'categoria' => $categories->firstWhere('slug', $param('categoria'))?->slug,
        ];

        $apps = $this->filtered($filters, $companies->firstWhere('slug', $filters['empresa']), $categories->firstWhere('slug', $filters['categoria']))
            ->with(['category', 'companies', 'accessRules.company'])
            ->orderBy('name')
            ->get();

        return view('admin.apps.index', [
            'rows' => $apps->map(fn (App $app) => AppAdminRow::from($app)),
            'tiles' => $this->tiles(),
            'filters' => $filters,
            'filtered' => array_filter($filters) !== [],
            'companies' => $companies,
            'categories' => $categories,
            'statuses' => AppAdminStatus::cases(),
        ]);
    }

    /**
     * @param  array{q: string, empresa: ?string, estado: ?string, categoria: ?string}  $filters
     * @return Builder<App>
     */
    private function filtered(array $filters, ?Company $company, ?AppCategory $category): Builder
    {
        $query = App::query();

        if ($filters['q'] !== '') {
            $term = '%'.addcslashes(mb_strtolower($filters['q']), '%_\\').'%';
            $query->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(slug) LIKE ?', [$term]));
        }

        if ($company !== null) {
            $query->where(fn (Builder $query) => $query
                ->where('available_to_all_companies', true)
                ->orWhereHas('companies', fn (Builder $query) => $query->whereKey($company->id)));
        }

        if ($filters['estado'] !== null) {
            AppAdminStatus::from($filters['estado'])->scope($query);
        }

        if ($category !== null) {
            $query->where('app_category_id', $category->id);
        }

        return $query;
    }

    /**
     * Counts over the whole catalog, whatever the filters.
     *
     * @return array{published: int, failing: int, maintenance: int, microsoft: int, web: int}
     */
    private function tiles(): array
    {
        $count = fn (callable $scope) => tap(App::query(), $scope)->count();
        $publishedWeb = fn (Builder $query) => $query
            ->where('status', '!=', App::STATUS_HIDDEN)
            ->where('type', App::TYPE_WEB);

        return [
            'published' => $count(fn (Builder $query) => $query->where('status', '!=', App::STATUS_HIDDEN)),
            'failing' => $count(fn (Builder $query) => AppAdminStatus::Failing->scope($query)),
            'maintenance' => $count(fn (Builder $query) => AppAdminStatus::Maintenance->scope($query)),
            'microsoft' => $count(fn (Builder $query) => $publishedWeb($query)->where('login_type', 'microsoft')),
            'web' => $count($publishedWeb),
        ];
    }
}
