<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformStaff;
use App\Support\Account\AccountRejected;
use App\Support\Account\PasswordResetService;
use App\Support\Platform\PlatformSession;
use App\Support\Platform\StaffProfiles;
use App\Support\Platform\StaffRejected;
use App\Support\Site\SiteRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;

/** Name, photo and password of staff (Step 268); who may edit whom is checked by StaffProfiles on every call. */
class StaffProfileController extends Controller
{
    public function __construct(private readonly StaffProfiles $profiles) {}

    public function update(Request $request, string $staff): JsonResponse|Response
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        return $this->guard(function () use ($request, $staff, $validated): JsonResponse {
            $target = $this->profiles->target($this->actor($request), $staff);
            $this->profiles->rename($this->actor($request), $target, $validated['first_name'] ?? null, $validated['last_name']);

            return response()->json($this->profile($target->refresh()->load('user'), $this->actor($request)));
        });
    }

    public function storePhoto(Request $request, string $staff): JsonResponse|Response
    {
        $request->validate(['photo' => ['required', 'file']]);

        return $this->guard(function () use ($request, $staff): JsonResponse {
            $target = $this->profiles->target($this->actor($request), $staff);
            $this->profiles->setPhoto($this->actor($request), $target, $request->file('photo'));

            return response()->json($this->profile($target->refresh()->load('user'), $this->actor($request)));
        });
    }

    public function destroyPhoto(Request $request, string $staff): JsonResponse|Response
    {
        return $this->guard(function () use ($request, $staff): JsonResponse {
            $target = $this->profiles->target($this->actor($request), $staff);
            $this->profiles->removePhoto($this->actor($request), $target);

            return response()->json($this->profile($target->refresh()->load('user'), $this->actor($request)));
        });
    }

    /** Any active staff member may see colleagues' photos: they appear in the staff list, the header and the audit. */
    public function photo(string $staff): Response
    {
        $target = PlatformStaff::query()->find($staff);
        $bytes = $target === null ? null : $this->profiles->photo($target);

        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function changePassword(Request $request, PlatformSession $session): JsonResponse|Response
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
            'confirmation_code' => ['required', 'string', 'max:32'],
        ]);

        return $this->guard(function () use ($request, $validated): Response {
            $version = $this->profiles->changeOwnPassword($this->actor($request), $validated['current_password'], $validated['password'], $validated['confirmation_code'], $request->session()->getId());
            $request->session()->put(PlatformSession::PERMISSION_VERSION, $version);

            return response()->noContent();
        });
    }

    public function sendPasswordLink(Request $request, string $staff): JsonResponse|Response
    {
        $validated = $request->validate(['confirmation_code' => ['required', 'string', 'max:32']]);

        return $this->guard(function () use ($request, $staff, $validated): Response {
            $target = $this->profiles->target($this->actor($request), $staff);
            $this->profiles->sendPasswordLink($this->actor($request), $target, $validated['confirmation_code']);

            return response()->noContent();
        });
    }

    /** The one-time link from the letter, opened in the owner panel; every session of that person ends. */
    public function completePasswordReset(Request $request, PasswordResetService $resets): JsonResponse|Response
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)],
        ]);
        $email = mb_strtolower(trim($validated['email']));

        try {
            $resets->complete($email, $validated['token'], $validated['password']);
        } catch (AccountRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
        }

        $this->profiles->endSessionsAfterReset($email);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(PlatformStaff $target, PlatformStaff $actor): array
    {
        return [
            'id' => $target->id,
            'name' => $target->user?->name,
            'first_name' => $target->user?->first_name,
            'last_name' => $target->user?->last_name,
            'photo_url' => $target->photoUrl(),
            'can_edit' => $this->profiles->canEdit($actor, $target),
        ];
    }

    /**
     * @param  Closure(): (JsonResponse|Response)  $action
     */
    private function guard(Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (StaffRejected|SiteRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The profile change was rejected.'], $exception->status);
        }
    }

    private function actor(Request $request): PlatformStaff
    {
        /** @var PlatformStaff $staff */
        $staff = $request->attributes->get('platform_staff');

        return $staff;
    }
}
