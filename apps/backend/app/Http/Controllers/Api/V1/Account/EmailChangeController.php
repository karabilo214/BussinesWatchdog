<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Support\Account\AccountRejected;
use App\Support\Account\EmailChangeService;
use App\Support\Account\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailChangeController extends Controller
{
    public function __construct(private readonly EmailChangeService $changes) {}

    /** The same answer whether or not another account uses the address. */
    public function store(Request $request, StepUp $stepUp): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string', 'max:128'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $stepUp->require($user, $validated['current_password'], $validated['code'] ?? null);

        try {
            $pending = $this->changes->request($user, $validated['email']);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json($this->dto($pending), 202);
    }

    public function resend(Request $request): JsonResponse
    {
        try {
            return response()->json($this->dto($this->changes->resend($request->user())), 202);
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            $this->changes->cancel($request->user());
        } catch (AccountRejected $exception) {
            return $this->rejected($exception);
        }

        return response()->json(null, 204);
    }

    /** Opened from the new address; works without a session and lands on sign-in with the new address. */
    public function confirm(string $request, string $token): RedirectResponse
    {
        return redirect('/app/login?'.http_build_query(['email_changed' => $this->changes->confirm($request, $token) ? '1' : '0']));
    }

    /** Opened from the old address ("this was not me"). */
    public function cancelFromOldAddress(string $request, string $token): RedirectResponse
    {
        return redirect('/app/login?'.http_build_query(['email_change_cancelled' => $this->changes->cancelFromOldAddress($request, $token) ? '1' : '0']));
    }

    /**
     * @return array{email: string, expires_at: string}
     */
    private function dto(EmailChangeRequest $pending): array
    {
        return ['email' => $pending->new_email, 'expires_at' => $pending->expires_at->toJSON()];
    }

    private function rejected(AccountRejected $exception): JsonResponse
    {
        return response()->json(['code' => $exception->reasonCode, 'message' => 'The request was rejected.'], $exception->status);
    }
}
