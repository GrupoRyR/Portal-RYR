<?php

namespace App\Livewire\Admin;

use App\Models\AppCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Categories administration (brief §5.2, docs/diseno/AdminCategories.dc.html):
 * the order of this list is the order of the home screen. Empty categories
 * (no app at all, hidden ones included) are collapsed into one row and
 * are the only ones that can be deleted.
 */
class CategoryManager extends Component
{
    use AuthorizesRequests;

    public const MAX_NAME = 60;

    #[Locked]
    public ?int $renamingId = null;

    public string $renameValue = '';

    public string $newName = '';

    #[Locked]
    public bool $showEmpty = false;

    /** Confirmation of the last change, read by screen readers. */
    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AppCategory::class);
    }

    public function toggleEmpty(): void
    {
        $this->showEmpty = ! $this->showEmpty;
    }

    public function moveUp(int $id): void
    {
        $this->move($id, -1);
    }

    public function moveDown(int $id): void
    {
        $this->move($id, 1);
    }

    public function startRename(int $id): void
    {
        $category = AppCategory::findOrFail($id);
        $this->authorize('update', $category);

        $this->renamingId = $category->id;
        $this->renameValue = $category->name;
        $this->resetValidation();
    }

    public function cancelRename(): void
    {
        $this->renamingId = null;
        $this->renameValue = '';
        $this->resetValidation();
    }

    public function saveRename(): void
    {
        if ($this->renamingId === null) {
            return;
        }

        $category = AppCategory::findOrFail($this->renamingId);
        $this->authorize('update', $category);

        $name = $this->checkName('renameValue', $this->renameValue, $category->id);

        $category->update(['name' => $name]);

        $this->cancelRename();
        $this->notice = "Se cambió el nombre a «{$name}».";
    }

    public function create(): void
    {
        $this->authorize('create', AppCategory::class);

        $name = $this->checkName('newName', $this->newName);

        AppCategory::create([
            'name' => $name,
            'slug' => $this->freeSlug($name),
            'sort_order' => (int) AppCategory::query()->max('sort_order') + 1,
        ]);

        $this->newName = '';
        $this->notice = "Se agregó «{$name}» al final. Las vacías no se muestran en el inicio.";
    }

    public function delete(int $id): void
    {
        $category = AppCategory::withCount('apps')->findOrFail($id);
        $this->authorize('delete', $category);

        if ($category->apps_count > 0) {
            $count = $category->apps_count === 1 ? '1 app' : "{$category->apps_count} apps";
            $them = $category->apps_count === 1 ? 'muévela' : 'muévelas';

            throw ValidationException::withMessages([
                'categories' => "No se puede borrar «{$category->name}»: tiene {$count}. Primero {$them} a otra categoría.",
            ]);
        }

        $category->delete();

        if ($this->renamingId === $id) {
            $this->cancelRename();
        }

        $this->notice = "Se borró «{$category->name}».";
    }

    public function render(): View
    {
        $categories = $this->categories();
        $empty = $categories->filter(fn (AppCategory $category) => $category->apps_count === 0)->values();

        return view('livewire.admin.category-manager', [
            'visible' => $this->visible($categories),
            'empty' => $empty,
        ]);
    }

    /**
     * Swaps the category with its neighbour in the list as shown (collapsed
     * empty categories are skipped), then numbers the whole list 1..n.
     */
    private function move(int $id, int $step): void
    {
        $this->authorize('update', AppCategory::findOrFail($id));

        $categories = $this->categories();
        $visible = $this->visible($categories)->pluck('id')->values();
        $index = $visible->search($id);

        if ($index === false || ! $visible->has($index + $step)) {
            return;
        }

        $order = $categories->pluck('id')->values()->all();
        $from = array_search($id, $order, true);
        $to = array_search($visible[$index + $step], $order, true);
        [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

        DB::transaction(function () use ($categories, $order) {
            foreach ($order as $position => $categoryId) {
                $category = $categories->firstWhere('id', $categoryId);

                if ($category->sort_order !== $position + 1) {
                    $category->update(['sort_order' => $position + 1]);
                }
            }
        });

        $moved = $categories->firstWhere('id', $id);
        $moved->audit('category.moved', [
            'old' => ['position' => $from + 1],
            'attributes' => ['position' => $to + 1],
        ]);

        $this->notice = "«{$moved->name}» quedó en la posición ".($to + 1).'.';
    }

    /**
     * @return Collection<int, AppCategory>
     */
    private function categories(): Collection
    {
        return AppCategory::query()->ordered()->withCount('apps')->get();
    }

    /**
     * @param  Collection<int, AppCategory>  $categories
     * @return Collection<int, AppCategory>
     */
    private function visible(Collection $categories): Collection
    {
        return $this->showEmpty
            ? $categories
            : $categories->filter(fn (AppCategory $category) => $category->apps_count > 0)->values();
    }

    private function checkName(string $field, string $value, ?int $except = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($name === '') {
            throw ValidationException::withMessages([$field => 'Escribe el nombre de la categoría.']);
        }

        if (mb_strlen($name) > self::MAX_NAME) {
            throw ValidationException::withMessages([$field => 'Usa como máximo '.self::MAX_NAME.' caracteres.']);
        }

        $taken = AppCategory::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->get(['id', 'name'])
            ->contains(fn (AppCategory $category) => mb_strtolower($category->name) === mb_strtolower($name));

        if ($taken) {
            throw ValidationException::withMessages([$field => 'Ya hay una categoría con ese nombre.']);
        }

        return $name;
    }

    private function freeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;

        for ($n = 2; AppCategory::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
