<?php

namespace App\Assistant\Tools;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * `borrador_de_anuncio`: only PROPOSES an announcement. It writes nothing:
 * the draft goes back to /asistente, which shows it in a form with
 * «Publicar»; only the person's own POST of that form, with the on-screen
 * confirmation, creates the announcement, through the announcement policy
 * and App\Announcements\AnnouncementPublisher
 * (App\Http\Controllers\Assistant\AssistantDraftController). So text in
 * portal content can never publish anything by itself.
 *
 * Only for accounts that may create announcements today
 * (AnnouncementPolicy::create) and have a company, which is the draft's
 * audience. The rules are the announcement editor's: one-line title of up
 * to Announcement::TITLE_MAX characters, text of up to BODY_MAX.
 */
final class BorradorDeAnuncio implements AssistantTool
{
    public const NAME = 'borrador_de_anuncio';

    public const PROPOSED = 'El borrador quedó en la pantalla de la persona para que lo revise, lo corrija y decida si lo publica. Todavía no se ha publicado: no digas que se publicó.';

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Propone el borrador de un anuncio para la empresa de la cuenta: título (una línea, máximo '.Announcement::TITLE_MAX
            .' caracteres), texto (texto plano, máximo '.Announcement::BODY_MAX.' caracteres) y si pide confirmar la lectura. '
            .'No publica nada: la persona ve el borrador en pantalla, lo puede corregir y lo publica ella misma con «Publicar». '
            .'Úsala solo cuando la persona pida redactar un anuncio.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'titulo' => ['type' => 'string', 'description' => 'Título del anuncio, en una sola línea.', 'maxLength' => Announcement::TITLE_MAX],
                'texto' => ['type' => 'string', 'description' => 'Texto del anuncio, en texto plano.', 'maxLength' => Announcement::BODY_MAX],
                'lectura_obligatoria' => ['type' => 'boolean', 'description' => 'Si cada persona debe confirmar que lo leyó.'],
            ],
            'required' => ['titulo', 'texto'],
        ];
    }

    public function availableTo(User $user): bool
    {
        return $user->company_id !== null && Gate::forUser($user)->allows('create', Announcement::class);
    }

    public function run(User $user, array $input): ToolOutcome
    {
        if (! $this->availableTo($user)) {
            return ToolOutcome::error('Esta cuenta no puede crear anuncios.');
        }

        $validator = Validator::make($input, [
            'titulo' => ['required', 'string', 'max:'.Announcement::TITLE_MAX, 'not_regex:/[\r\n]/'],
            'texto' => ['required', 'string', 'max:'.Announcement::BODY_MAX],
            'lectura_obligatoria' => ['sometimes', 'boolean'],
        ], [
            'titulo.required' => 'Falta el título.',
            'titulo.max' => 'El título admite como máximo '.Announcement::TITLE_MAX.' caracteres.',
            'titulo.not_regex' => 'El título va en una sola línea.',
            'texto.required' => 'Falta el texto.',
            'texto.max' => 'El texto admite como máximo '.Announcement::BODY_MAX.' caracteres.',
            'string' => 'El campo :attribute debe ser texto.',
            'boolean' => 'El campo :attribute debe ser verdadero o falso.',
        ]);

        if ($validator->fails()) {
            return ToolOutcome::error((string) $validator->errors()->first());
        }

        $draft = new AnnouncementDraft(
            trim((string) $input['titulo']),
            trim((string) $input['texto']),
            filter_var($input['lectura_obligatoria'] ?? false, FILTER_VALIDATE_BOOL),
        );

        return ToolOutcome::data(['estado' => self::PROPOSED], $draft);
    }
}
