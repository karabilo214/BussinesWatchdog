<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Platform\StaffOnboarding;
use App\Support\Platform\StaffRejected;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffOnboarding $onboarding) {}

    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:128']]);

        return $this->guard(fn (): JsonResponse => response()->json($this->onboarding->lookup($validated['token'])));
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:128', Password::min(12)],
        ]);

        return $this->guard(fn (): JsonResponse => response()->json($this->onboarding->start($request->session(), $validated['token'], $validated['name'] ?? null, $validated['password'])));
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        return $this->guard(fn (): JsonResponse => response()->json($this->onboarding->confirm($request->session(), $validated['token'], $validated['code'])));
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function guard(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (StaffRejected $exception) {
            return response()->json(['code' => $exception->reasonCode, 'message' => 'The invitation cannot be used.'], $exception->status);
        }
    }
}
