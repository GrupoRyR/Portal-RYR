<?php

namespace App\Http\Requests\Account;

use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * «Mi cumpleaños» on /cuenta: a day and a month that exist together (29
 * February included), never a year, and whether the company sees it.
 * Only accounts with a company directory (DirectoryEntry::companyIdFor())
 * have the section; any other gets a 404, like the page answers emergency
 * accounts.
 */
class BirthdayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return self::allows($this->user());
    }

    /**
     * Whether the account has «Mi cumpleaños»: the people who would see it
     * are its company directory.
     */
    public static function allows(mixed $user): bool
    {
        return $user instanceof User && DirectoryEntry::companyIdFor($user) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'birthday_day' => ['required', 'integer', 'between:1,31'],
            'birthday_month' => ['required', 'integer', 'between:1,12'],
            'show_birthday' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The day must exist in that month of a leap year: 31 April never
     * does, 29 February does.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! checkdate((int) $this->input('birthday_month'), (int) $this->input('birthday_day'), 2024)) {
                    $validator->errors()->add('birthday_day', 'Ese día no existe en el mes que elegiste.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birthday_day.required' => 'Elige el día.',
            'birthday_day.integer' => 'Elige un día de la lista.',
            'birthday_day.between' => 'Elige un día de la lista.',
            'birthday_month.required' => 'Elige el mes.',
            'birthday_month.integer' => 'Elige un mes de la lista.',
            'birthday_month.between' => 'Elige un mes de la lista.',
            'show_birthday.boolean' => 'La casilla no es válida.',
        ];
    }

    public function day(): int
    {
        return (int) $this->validated('birthday_day');
    }

    public function month(): int
    {
        return (int) $this->validated('birthday_month');
    }

    /**
     * A 404, never a 403: the account has no such section.
     */
    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    /**
     * Back to the section, with the errors beside it.
     */
    protected function getRedirectUrl(): string
    {
        return route('account.show').'#cumpleanos';
    }
}
