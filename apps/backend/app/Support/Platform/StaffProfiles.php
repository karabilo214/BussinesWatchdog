<?php

namespace App\Support\Platform;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Account\MfaService;
use App\Support\Account\PasswordResetService;
use App\Support\Site\SafeFiles;
use App\Support\Site\SiteRejected;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Name, photo and password of platform staff (Step 268). The platform owner edits anyone; a member with
 * `staff.manage` edits themselves and other members; everyone else only themselves. Another person's password is
 * never set for them — they get a one-time link (owner's decision 2026-10-26). No date of birth is collected.
 */
class StaffProfiles
{
    public const PHOTO_SIDE = 512;

    public const PHOTO_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly SafeFiles $files,
        private readonly MfaService $mfa,
        private readonly PasswordResetService $passwordResets,
    ) {}

    public function canEdit(PlatformStaff $actor, PlatformStaff $target): bool
    {
        if (! $actor->active()) {
            return false;
        }

        return $actor->role === PlatformStaff::ROLE_OWNER
            || $actor->id === $target->id
            || (PlatformCapabilities::allows($actor, PlatformCapabilities::STAFF_MANAGE) && $target->role !== PlatformStaff::ROLE_OWNER);
    }

    public function target(PlatformStaff $actor, string $staffId): PlatformStaff
    {
        /** @var PlatformStaff|null $target */
        $target = Str::isUuid($staffId) ? PlatformStaff::query()->with('user')->find($staffId) : null;

        if ($target === null || $target->user === null) {
            throw new StaffRejected('staff_not_found', 404);
        }

        if (! $this->canEdit($actor, $target)) {
            $this->audit->record($actor->id, 'staff.profile_updated', 'staff', $target->id, PlatformAudit::RESULT_DENIED, ['stage' => 'permission']);

            throw new StaffRejected('staff_profile_forbidden', 403);
        }

        return $target;
    }

    public function rename(PlatformStaff $actor, PlatformStaff $target, ?string $firstName, string $lastName): void
    {
        /** @var User $user */
        $user = $target->user;
        $before = ['first_name' => $user->first_name, 'last_name' => $user->last_name];
        $after = ['first_name' => $firstName === null || trim($firstName) === '' ? null : trim($firstName), 'last_name' => trim($lastName)];

        if ($before === $after) {
            return;
        }

        $user->forceFill($after)->save();
        $this->audit->record($actor->id, 'staff.profile_updated', 'staff', $target->id, PlatformAudit::RESULT_SUCCEEDED, ['before' => $before, 'after' => $after]);
    }

    /** Recognised by content, decoded and encoded again without metadata, cut to a centred square (Step 225 rules). */
    public function setPhoto(PlatformStaff $actor, PlatformStaff $target, UploadedFile $file): void
    {
        $clean = $this->files->clean($file, ['jpeg', 'png', 'webp'], self::PHOTO_MAX_BYTES, [
            'max_side' => (int) config('watchdog.site.media_max_side'),
            'max_pixels' => (int) config('watchdog.site.media_max_pixels'),
            'resize_max_side' => 0,
        ]);
        $bytes = $this->square($clean['bytes']);
        $key = 'staff-photos/'.Str::uuid7().'.jpg';

        try {
            $this->disk()->put($key, $bytes, ['visibility' => 'private', 'ContentType' => 'image/jpeg']);
        } catch (Throwable $exception) {
            report($exception);

            throw new SiteRejected('media_storage_unavailable', 503);
        }

        $previous = $target->photo_key;
        $target->forceFill(['photo_key' => $key, 'photo_updated_at' => Carbon::now()])->save();
        $this->forget($previous);
        $this->audit->record($actor->id, 'staff.photo_updated', 'staff', $target->id, PlatformAudit::RESULT_SUCCEEDED, ['photo' => $previous === null ? 'added' : 'replaced']);
    }

    public function removePhoto(PlatformStaff $actor, PlatformStaff $target): void
    {
        if ($target->photo_key === null) {
            return;
        }

        $previous = $target->photo_key;
        $target->forceFill(['photo_key' => null, 'photo_updated_at' => Carbon::now()])->save();
        $this->forget($previous);
        $this->audit->record($actor->id, 'staff.photo_removed', 'staff', $target->id, PlatformAudit::RESULT_SUCCEEDED);
    }

    public function photo(PlatformStaff $target): ?string
    {
        try {
            return $target->photo_key === null ? null : $this->disk()->get($target->photo_key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Own password: the current one and the authenticator code. Other sessions end; this one stays.
     *
     * @return int the new permission version for the current session
     */
    public function changeOwnPassword(PlatformStaff $actor, string $current, string $password, ?string $code, string $currentSessionId): int
    {
        /** @var User $user */
        $user = $actor->user;

        if (! Hash::check($current, $user->password_hash)) {
            $this->audit->record($actor->id, 'staff.password_changed', 'staff', $actor->id, PlatformAudit::RESULT_DENIED, ['stage' => 'password']);

            throw new StaffRejected('current_password_invalid', 422);
        }

        if (! $this->mfa->verify($user, $code)) {
            $this->audit->record($actor->id, 'staff.password_changed', 'staff', $actor->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            throw new StaffRejected('step_up_failed', 403);
        }

        return DB::transaction(function () use ($actor, $user, $password, $currentSessionId): int {
            $user->forceFill(['password_hash' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $currentSessionId)->delete();
            DB::table('trusted_devices')->where('user_id', $user->id)->delete();
            $version = $actor->permission_version + 1;
            $actor->forceFill(['permission_version' => $version])->save();
            $this->audit->record($actor->id, 'staff.password_changed', 'staff', $actor->id, PlatformAudit::RESULT_SUCCEEDED, ['password' => 'changed', 'sessions_revoked' => true]);

            return $version;
        });
    }

    /** Another person's password: they get a one-time link to set it themselves; the actor confirms with their code. */
    public function sendPasswordLink(PlatformStaff $actor, PlatformStaff $target, string $code): void
    {
        if ($actor->id === $target->id) {
            throw new StaffRejected('use_own_password_change', 422);
        }

        if (! $this->mfa->verify($actor->user, $code)) {
            $this->audit->record($actor->id, 'staff.password_link_sent', 'staff', $target->id, PlatformAudit::RESULT_DENIED, ['stage' => 'step_up']);

            throw new StaffRejected('step_up_failed', 403);
        }

        /** @var User $user */
        $user = $target->user;
        $this->passwordResets->sendTo($user, '/owner/reset-password');
        $this->audit->record($actor->id, 'staff.password_link_sent', 'staff', $target->id, PlatformAudit::RESULT_SUCCEEDED, ['to' => $user->email]);
    }

    /** After a reset link was used every owner-panel session of that person ends too. */
    public function endSessionsAfterReset(string $email): void
    {
        $userId = User::query()->where('email', $email)->value('id');

        if ($userId !== null) {
            PlatformStaff::query()->where('user_id', $userId)->increment('permission_version');
        }
    }

    private function square(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new SiteRejected('media_unreadable');
        }

        $side = min(imagesx($image), imagesy($image));
        $target = imagecreatetruecolor(self::PHOTO_SIDE, self::PHOTO_SIDE);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, intdiv(imagesx($image) - $side, 2), intdiv(imagesy($image) - $side, 2), self::PHOTO_SIDE, self::PHOTO_SIDE, $side, $side);
        ob_start();

        try {
            imagejpeg($target, null, 85);
        } finally {
            $encoded = (string) ob_get_clean();
        }

        return $encoded;
    }

    private function forget(?string $key): void
    {
        if ($key === null) {
            return;
        }

        try {
            $this->disk()->delete($key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('watchdog.site.media_disk'));
    }
}
