<?php

namespace App\Announcements;

use App\Models\Announcement;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Saves an announcement with its companies and image in one go. The image
 * is written inside the transaction; when anything fails the new file is
 * deleted, and the file it replaced is only deleted after the commit, so
 * no failed save leaves an orphan or a broken image behind.
 *
 * Authorization is the caller's job (App\Policies\AnnouncementPolicy).
 */
class AnnouncementPublisher
{
    public function __construct(private readonly AnnouncementImageStore $images) {}

    /**
     * @param  array<string, mixed>  $attributes  Fillable attributes except the image.
     * @param  array<int|string>  $companyIds  Ignored when the audience is every company.
     */
    public function save(
        Announcement $announcement,
        array $attributes,
        array $companyIds = [],
        ?UploadedFile $image = null,
        bool $removeImage = false,
    ): Announcement {
        unset($attributes['image_path']);

        $oldPath = $announcement->image_path;
        $newPath = null;
        $creating = ! $announcement->exists;

        try {
            DB::transaction(function () use ($announcement, $attributes, $companyIds, $image, $removeImage, $creating, &$newPath) {
                $oldCompanies = $creating ? [] : $this->companyNames($announcement->companies()->pluck('companies.id')->all());

                $announcement->fill($attributes);

                if ($removeImage) {
                    $announcement->image_path = null;
                }

                if ($creating) {
                    // The image folder is named after the id.
                    $announcement->save();
                }

                if ($image !== null) {
                    $newPath = $this->images->store($announcement, $image);
                    $announcement->image_path = $newPath;
                }

                $announcement->save();

                $ids = $announcement->isForAllCompanies() ? [] : array_values(array_unique(array_map('intval', $companyIds)));
                $announcement->companies()->sync($ids);

                $newCompanies = $this->companyNames($ids);

                if ($newCompanies !== $oldCompanies) {
                    $announcement->audit('announcement.companies_changed', [
                        'old' => ['companies' => $oldCompanies],
                        'attributes' => ['companies' => $newCompanies],
                    ]);
                }
            });
        } catch (Throwable $e) {
            $this->images->delete($newPath);
            $announcement->image_path = $oldPath;

            if ($creating) {
                // The insert was rolled back too.
                $announcement->exists = false;
                $announcement->setAttribute($announcement->getKeyName(), null);
            }

            throw $e;
        }

        if ($oldPath !== $announcement->image_path) {
            $this->images->delete($oldPath);
        }

        return $announcement;
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<string>
     */
    private function companyNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Company::query()->whereKey($ids)->get(['id', 'name', 'short_name'])
            ->map(fn (Company $company) => $company->short_name ?: $company->name)
            ->sort()
            ->values()
            ->all();
    }
}
