<?php

namespace App\Livewire\Admin;

use App\Announcements\AnnouncementImageStore;
use App\Announcements\AnnouncementListEmails;
use App\Announcements\AnnouncementPublisher;
use App\Announcements\AnnouncementReminders;
use App\Announcements\ListEmailRetryRefused;
use App\Announcements\ReminderNotAllowed;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use App\View\Home\AnnouncementCard;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Creates or edits one announcement (brief §4 «Anuncios»,
 * docs/diseno/AdminAnnouncementEdit.dc.html): content, audience, window,
 * pin, mandatory reading, and a live preview built from the same partials
 * as the home screen. A saved mandatory announcement also shows «X de Y
 * personas lo leyeron» and the people who are missing: counted once and
 * kept for READ_REPORT_SECONDS (typing in the form does not recount), while
 * who may see it (`viewReads`) is re-checked on every render. From there,
 * «Enviar recordatorio» asks the cron to email the people who are missing
 * (App\Announcements\AnnouncementReminders, at most once a day).
 *
 * «Enviar también por correo a la lista de cada empresa» shows up when a
 * company of the audience has an all-staff list (Admin › Empresas); the
 * cron sends it once (App\Announcements\AnnouncementListEmails), and the
 * editor shows how it went per company, with «Reintentar» for a failure.
 *
 * Announcements editors only get their own company as audience; the
 * policy (`publishTo`) is checked on every save, whatever the form sent.
 */
class AnnouncementEditor extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    /** How long the open editor keeps «X de Y» before counting again. */
    public const READ_REPORT_SECONDS = 60;

    #[Locked]
    public ?Announcement $announcement = null;

    public string $title = '';

    public string $body = '';

    public string $audience = Announcement::AUDIENCE_ALL;

    /** @var list<int|string> */
    public array $companyIds = [];

    public string $startsAt = '';

    public string $endsAt = '';

    public bool $isPinned = false;

    /** «Lectura obligatoria»: each person who sees it confirms reading it. */
    public bool $requiresRead = false;

    /** «Enviar también por correo a la lista de cada empresa». */
    public bool $emailLists = false;

    public ?TemporaryUploadedFile $image = null;

    /** The stored image goes away on save. */
    public bool $removeImage = false;

    public function mount(?Announcement $announcement = null): void
    {
        if ($announcement === null || ! $announcement->exists) {
            $this->authorize('create', Announcement::class);
            $this->startsAt = Carbon::now()->format(self::DATETIME_FORMAT);

            if ($this->restricted()) {
                $this->audience = Announcement::AUDIENCE_COMPANIES;
                $this->companyIds = [(string) $this->user()->company_id];
            }

            return;
        }

        $this->authorize('update', $announcement);

        $this->announcement = $announcement;
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->audience = $announcement->audience;
        $this->companyIds = $announcement->companies()->orderBy('companies.id')->pluck('companies.id')->map(fn ($id) => (string) $id)->all();
        $this->startsAt = $announcement->starts_at->format(self::DATETIME_FORMAT);
        $this->endsAt = (string) $announcement->ends_at?->format(self::DATETIME_FORMAT);
        $this->isPinned = $announcement->is_pinned;
        $this->requiresRead = $announcement->requires_read;
        $this->emailLists = $announcement->email_lists;
    }

    public function updatedImage(): void
    {
        $this->removeImage = false;
        $this->validateOnly('image');
    }

    /**
     * «Quitar»: drops a just-chosen file and, on save, the stored image.
     */
    public function clearImage(): void
    {
        $this->image = null;
        $this->removeImage = $this->announcement?->image_path !== null;
        $this->resetValidation('image');
    }

    public function save(AnnouncementPublisher $publisher): mixed
    {
        $this->announcement === null
            ? $this->authorize('create', Announcement::class)
            : $this->authorize('update', $this->announcement);

        $this->title = trim($this->title);
        $this->body = trim($this->body);

        $this->validate();

        $companyIds = $this->audience === Announcement::AUDIENCE_COMPANIES ? $this->companyIds : [];
        $this->authorize('publishTo', [Announcement::class, $this->audience, $companyIds]);

        $creating = $this->announcement === null;
        $attributes = [
            'title' => $this->title,
            'body' => $this->body,
            'audience' => $this->audience,
            'starts_at' => self::parse($this->startsAt),
            'ends_at' => $this->endsAt === '' ? null : self::parse($this->endsAt),
            'is_pinned' => $this->isPinned,
            'requires_read' => $this->requiresRead,
            // Only kept while a company of the audience can get it.
            'email_lists' => $this->emailLists && $this->listRecipients()->isNotEmpty(),
        ];

        if ($creating) {
            $attributes['author_id'] = $this->user()->id;
        }

        $announcement = $publisher->save(
            $this->announcement ?? new Announcement,
            $attributes,
            $companyIds,
            $this->image,
            $this->removeImage,
        );

        session()->flash('status', $creating ? 'Anuncio creado.' : 'Cambios guardados.');

        return $this->redirectRoute('admin.announcements.edit', $announcement);
    }

    /**
     * «Retirar»: off the home screen at once (the button asks first).
     */
    public function withdraw(): void
    {
        $this->authorize('update', $this->announcement);

        $this->announcement->withdraw();
        session()->flash('status', 'Anuncio retirado. Ya no se muestra en el inicio.');
    }

    public function republish(): void
    {
        $this->authorize('update', $this->announcement);

        $this->announcement->republish();
        session()->flash('status', 'Anuncio publicado de nuevo.');
    }

    /**
     * «Enviar recordatorio»: records a reminder for the cron to mail. The
     * once-a-day limit and whether it may be sent at all are checked on the
     * server ({@see AnnouncementReminders::request()}), whatever the page
     * showed; a refusal is shown in the read report, and so is a database
     * failure (e.g. a lock wait timeout on the announcement row), logged
     * instead of a server error.
     */
    public function sendReminder(AnnouncementReminders $reminders): void
    {
        abort_if($this->announcement === null, 404);
        $this->authorize('update', $this->announcement);
        $this->resetErrorBag('reminder');

        try {
            $reminders->request($this->announcement, $this->user());
            session()->flash('status', 'Recordatorio en camino: el portal lo envía por correo en los próximos minutos.');
        } catch (ReminderNotAllowed $refused) {
            $this->addError('reminder', $refused->getMessage());
        } catch (QueryException $e) {
            Log::warning('Announcement reminder could not be requested.', [
                'announcement_id' => $this->announcement->id,
                'user_id' => $this->user()->id,
                'exception' => $e::class,
            ]);
            $this->addError('reminder', 'No se pudo pedir el recordatorio. Inténtalo de nuevo.');
        } finally {
            // Count again, with the reminder just requested (or the one that blocked it).
            unset($this->readReport);
        }
    }

    /**
     * «Reintentar» on a failed email to a list: the cron sends it again in
     * its next run. The limit (once every few minutes per email) and
     * whether the email may be retried at all are checked on the server
     * ({@see AnnouncementListEmails::retry()}).
     */
    public function retryListEmail(int $rowId, AnnouncementListEmails $emails): void
    {
        abort_if($this->announcement === null, 404);
        $this->authorize('update', $this->announcement);
        $this->resetErrorBag('listEmail');

        try {
            $row = $emails->retry($this->announcement, $rowId, $this->user());
            $company = $row->company === null ? 'la empresa' : AnnouncementListEmails::label($row->company);
            session()->flash('status', "El correo a la lista de {$company} sale de nuevo en los próximos minutos.");
        } catch (ListEmailRetryRefused $refused) {
            $this->addError('listEmail', $refused->getMessage());
        }
    }

    /**
     * The announcement as the form describes it right now, for the preview.
     */
    public function previewCard(): AnnouncementCard
    {
        $preview = new Announcement([
            'title' => trim($this->title) !== '' ? $this->title : 'Título del anuncio',
            'body' => trim($this->body) !== '' ? $this->body : 'El texto del anuncio aparece aquí.',
            'is_pinned' => $this->isPinned,
            'requires_read' => $this->requiresRead,
            'image_path' => $this->removeImage ? null : $this->announcement?->image_path,
        ]);
        $preview->starts_at = $this->parsedStart() ?? Carbon::now();

        return AnnouncementCard::from($preview, $this->newImageUrl());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $specific = $this->audience === Announcement::AUDIENCE_COMPANIES;

        return [
            'title' => ['required', 'string', 'max:'.Announcement::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.Announcement::BODY_MAX],
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'companyIds' => $specific ? ['required', 'array'] : ['array'],
            'companyIds.*' => ['distinct', Rule::exists('companies', 'id')],
            'startsAt' => ['required', 'date_format:'.self::DATETIME_FORMAT],
            'endsAt' => ['nullable', 'date_format:'.self::DATETIME_FORMAT, 'after:startsAt'],
            'isPinned' => ['boolean'],
            'requiresRead' => ['boolean'],
            'emailLists' => ['boolean'],
            'image' => [
                'nullable', 'file',
                'max:'.AnnouncementImageStore::MAX_KILOBYTES,
                'extensions:'.implode(',', AnnouncementImageStore::EXTENSIONS),
                $this->isUsableImage(...),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'title.required' => 'Escribe el título.',
            'title.max' => 'Usa como máximo '.Announcement::TITLE_MAX.' caracteres.',
            'body.required' => 'Escribe el texto del anuncio.',
            'body.max' => 'Usa como máximo '.Announcement::BODY_MAX.' caracteres.',
            'audience.in' => 'Elige para quién es el anuncio.',
            'companyIds.required' => 'Elige al menos una empresa.',
            'companyIds.*.exists' => 'Elige empresas de la lista.',
            'startsAt.required' => 'Elige desde cuándo se muestra.',
            'startsAt.date_format' => 'Elige una fecha y hora válidas.',
            'endsAt.date_format' => 'Elige una fecha y hora válidas.',
            'endsAt.after' => '«Hasta» debe ser posterior a «Desde».',
            'image.max' => 'La imagen pesa más de 2 MB.',
            'image.extensions' => 'Sube una imagen PNG, JPG o WebP.',
            'file' => 'No se pudo leer el archivo.',
        ];
    }

    public function render(): View
    {
        $restricted = $this->restricted();

        return view('livewire.admin.announcement-editor', [
            'restricted' => $restricted,
            'companies' => $restricted
                ? Company::query()->whereKey($this->user()->company_id)->get(['id', 'name', 'short_name'])
                : Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'is_active']),
            'status' => $this->announcement?->status,
            'preview' => $this->previewCard(),
            'currentImageUrl' => $this->removeImage ? null : $this->announcement?->imageUrl(),
            'newImageUrl' => $this->newImageUrl(),
            'reads' => $this->mayViewReads() ? $this->readReport : null,
            'listRecipients' => $this->listRecipients(),
            'listReport' => $this->announcement !== null && $this->user()->can('update', $this->announcement)
                ? app(AnnouncementListEmails::class)->report($this->announcement)
                : [],
        ]);
    }

    /**
     * The companies whose lists the announcement would reach as the form
     * stands: an announcements editor only ever addresses their company.
     *
     * @return Collection<int, Company>
     */
    private function listRecipients(): Collection
    {
        return $this->restricted()
            ? app(AnnouncementListEmails::class)->recipients(Announcement::AUDIENCE_COMPANIES, [(int) $this->user()->company_id])
            : app(AnnouncementListEmails::class)->recipients($this->audience, $this->companyIds);
    }

    /**
     * Which people read the saved announcement. Livewire keeps it per
     * open editor for READ_REPORT_SECONDS, so the round-trips of typing in
     * the form do not count again; reloading the page counts afresh.
     * Only read after {@see mayViewReads()}.
     *
     * @return array{read: int, total: int, pending: list<array{name: string, companies: list<string>}>, reminder: array<string, mixed>|null}
     */
    #[Computed(persist: true, seconds: self::READ_REPORT_SECONDS)]
    public function readReport(): array
    {
        return [
            ...$this->announcement->readCounts(),
            'pending' => $this->announcement->pendingReaders(),
            'reminder' => app(AnnouncementReminders::class)->lastFor($this->announcement),
        ];
    }

    /**
     * A saved mandatory announcement the user may still see the reads of,
     * checked on every render (the role can go away while the page is open).
     */
    private function mayViewReads(): bool
    {
        return $this->announcement !== null
            && $this->announcement->requires_read
            && $this->user()->can('viewReads', $this->announcement);
    }

    /**
     * Announcements editors (not admins) are limited to their company.
     */
    private function restricted(): bool
    {
        return ! AnnouncementPolicy::manages($this->user());
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }

    private function parsedStart(): ?Carbon
    {
        try {
            return self::parse($this->startsAt);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A datetime-local value in the app time zone (Bogotá), seconds at zero.
     */
    private static function parse(string $value): Carbon
    {
        return Carbon::createFromFormat(self::DATETIME_FORMAT.'|', $value)
            ?: throw new \InvalidArgumentException("Invalid date [{$value}].");
    }

    /**
     * Preview URL of a just-chosen file, when it is an image we accept.
     */
    private function newImageUrl(): ?string
    {
        if (! $this->image instanceof TemporaryUploadedFile
            || ! in_array(strtolower($this->image->getClientOriginalExtension()), AnnouncementImageStore::EXTENSIONS, true)) {
            return null;
        }

        try {
            return $this->image->isPreviewable() ? $this->image->temporaryUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isUsableImage(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof TemporaryUploadedFile && ($problem = app(AnnouncementImageStore::class)->problem($value)) !== null) {
            $fail($problem);
        }
    }
}
