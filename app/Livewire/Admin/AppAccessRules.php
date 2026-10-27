<?php

namespace App\Livewire\Admin;

use App\Microsoft\UserGroup;
use App\Models\App;
use App\Models\AppAccessRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Quién la ve» of the app editor (brief §5.2): the access rules of one
 * app, each saved at once. Rules are only created through the
 * AppAccessRule helpers, which also stop duplicate «whole company» rules
 * that the unique index cannot catch.
 */
class AppAccessRules extends Component
{
    use AuthorizesRequests;

    public const MODES = [AppAccessRule::TYPE_GROUP, AppAccessRule::TYPE_USER, AppAccessRule::TYPE_COMPANY];

    /** Microsoft groups created for the portal follow this convention. */
    public const GROUP_PREFIX = 'APP-';

    public const MAX_RESULTS = 8;

    public const DUPLICATE_MESSAGE = 'Esa regla ya existe.';

    #[Locked]
    public App $app;

    /** The add form being shown: group, user, company or none. */
    public string $mode = '';

    public string $companyId = '';

    public string $groupName = '';

    public string $userSearch = '';

    public function mount(App $app): void
    {
        $this->authorize('update', $app);

        $this->app = $app;
        $this->companyId = (string) ($app->companies()->orderBy('name')->value('companies.id')
            ?? Company::query()->orderBy('name')->value('id')
            ?? '');
    }

    public function open(string $mode): void
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : '';
        $this->groupName = '';
        $this->userSearch = '';
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->open('');
    }

    public function addGroup(): void
    {
        $this->authorize('update', $this->app);

        $company = $this->company();
        $name = trim($this->groupName);

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['groupName' => 'Escribe el nombre del grupo, por ejemplo APP-Cartera.']);
        }

        $exists = $this->app->accessRules()
            ->where('type', AppAccessRule::TYPE_GROUP)
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(value) = ?', [mb_strtolower($name)])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['groupName' => self::DUPLICATE_MESSAGE]);
        }

        $this->added(AppAccessRule::forGroup($this->app, $company, $name));
    }

    public function addUser(int $userId): void
    {
        $this->authorize('update', $this->app);

        $company = $this->company();
        $user = $this->companyUsers($company)->whereKey($userId)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['userSearch' => 'Elige una persona de '.$this->companyLabel($company).'.']);
        }

        $exists = $this->app->accessRules()
            ->where('type', AppAccessRule::TYPE_USER)
            ->where('value', (string) $user->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['userSearch' => self::DUPLICATE_MESSAGE]);
        }

        $this->added(AppAccessRule::forUser($this->app, $user));
    }

    public function addCompany(): void
    {
        $this->authorize('update', $this->app);

        $company = $this->company();

        $exists = $this->app->accessRules()
            ->where('type', AppAccessRule::TYPE_COMPANY)
            ->where('company_id', $company->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['companyId' => self::DUPLICATE_MESSAGE]);
        }

        $this->added(AppAccessRule::forCompany($this->app, $company));
    }

    public function remove(int $ruleId): void
    {
        $this->authorize('update', $this->app);

        // Only rules of this app: an id from elsewhere is ignored.
        $rule = $this->app->accessRules()->with('company')->whereKey($ruleId)->first();

        if ($rule === null) {
            return;
        }

        $description = $this->describe($rule);
        $rule->delete();

        $this->app->audit('app.access_rule_removed', ['old' => $description]);
    }

    public function render(): View
    {
        $rules = $this->app->accessRules()->with('company')->orderBy('type')->orderBy('value')->get();
        $users = User::query()
            ->whereKey($rules->where('type', AppAccessRule::TYPE_USER)->pluck('value')->map(fn ($id) => (int) $id))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $linked = $this->app->available_to_all_companies
            ? null
            : $this->app->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all();
        $company = Company::find((int) $this->companyId);

        return view('livewire.admin.app-access-rules', [
            'rules' => $rules,
            'users' => $users,
            'linked' => $linked,
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'short_name']),
            'suggestions' => $this->mode === AppAccessRule::TYPE_GROUP && $company ? $this->groupSuggestions($company) : collect(),
            'microsoft365Only' => $this->microsoft365OnlyRules($rules),
            'matches' => $this->mode === AppAccessRule::TYPE_USER && $company ? $this->userMatches($company) : new Collection,
        ]);
    }

    /**
     * What a rule grants, worded for the audit log.
     *
     * @return array{type: string, value: ?string, company: ?string}
     */
    private function describe(AppAccessRule $rule): array
    {
        $value = $rule->value;

        if ($rule->type === AppAccessRule::TYPE_USER) {
            $user = User::find((int) $rule->value);
            $value = $user ? "{$user->name} <{$user->email}>" : $rule->value;
        }

        return [
            'type' => $rule->type,
            'value' => $value,
            'company' => $rule->company ? $this->companyLabel($rule->company) : null,
        ];
    }

    private function added(AppAccessRule $rule): void
    {
        $this->app->audit('app.access_rule_added', ['attributes' => $this->describe($rule->load('company'))]);

        $this->open('');
    }

    private function company(): Company
    {
        $company = Company::find((int) $this->companyId);

        if ($company === null) {
            throw ValidationException::withMessages(['companyId' => 'Elige una empresa de la lista.']);
        }

        return $company;
    }

    private function companyLabel(Company $company): string
    {
        return $company->short_name ?: $company->name;
    }

    /**
     * @return Builder<User>
     */
    private function companyUsers(Company $company): Builder
    {
        return User::query()->where('company_id', $company->id)->where('is_emergency', false);
    }

    /**
     * Ids of the group rules whose name is seen among the rule company's
     * users only as a non-security group (a Microsoft 365 group or Team):
     * such a rule grants nothing. Names not seen, or only seen before the
     * flag was known, are not flagged.
     *
     * @param  Collection<int, AppAccessRule>  $rules
     * @return list<int>
     */
    private function microsoft365OnlyRules(Collection $rules): array
    {
        $flagged = [];

        foreach ($rules->where('type', AppAccessRule::TYPE_GROUP)->groupBy('company_id') as $companyId => $companyRules) {
            $seen = UserGroup::query()
                ->whereIn('user_id', User::query()->where('company_id', $companyId)->select('id'))
                ->whereNotNull('security_group')
                ->get(['display_name', 'security_group'])
                ->groupBy(fn (UserGroup $group) => mb_strtolower(trim($group->display_name)));

            foreach ($companyRules as $rule) {
                $flags = $seen->get(mb_strtolower(trim((string) $rule->value)));

                if ($flags !== null && ! $flags->contains('security_group', true)) {
                    $flagged[] = (int) $rule->id;
                }
            }
        }

        return $flagged;
    }

    /**
     * Distinct APP-… security group names seen among the company's users,
     * matching what was typed so far. Free text stays allowed.
     *
     * @return BaseCollection<int, string>
     */
    private function groupSuggestions(Company $company): BaseCollection
    {
        $typed = mb_strtolower(trim($this->groupName));

        return UserGroup::query()
            ->whereIn('user_id', $this->companyUsers($company)->select('id'))
            ->where('security_group', true)
            ->where('display_name', 'like', self::GROUP_PREFIX.'%')
            ->distinct()
            ->orderBy('display_name')
            ->pluck('display_name')
            ->filter(fn (string $name) => str_starts_with($name, self::GROUP_PREFIX)
                && ($typed === '' || str_contains(mb_strtolower($name), $typed)))
            ->unique(fn (string $name) => mb_strtolower($name))
            ->take(20)
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function userMatches(Company $company): Collection
    {
        $search = trim($this->userSearch);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';

        return $this->companyUsers($company)
            ->where(fn (Builder $query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'email']);
    }
}
