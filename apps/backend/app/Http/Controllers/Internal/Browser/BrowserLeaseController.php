<?php

namespace App\Http\Controllers\Internal\Browser;

use App\Http\Controllers\Controller;
use App\Http\Requests\Browser\CompleteAttemptRequest;
use App\Models\BrowserWorker;
use App\Support\Browser\BrowserLeaseService;
use App\Support\Browser\LeaseConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BrowserLeaseController extends Controller
{
    public function __construct(
        private readonly BrowserLeaseService $leases,
    ) {}

    public function lease(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'browser_version' => ['required', 'string', 'min:1', 'max:100'],
            'location' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $lease = $this->leases->lease($this->worker($request), $validated['browser_version'], $validated['location']);

        return $lease === null ? response()->noContent() : response()->json($lease);
    }

    public function heartbeat(Request $request, string $attemptId): JsonResponse
    {
        $validated = $request->validate(['fencing_token' => ['required', 'integer', 'min:1']]);

        try {
            return response()->json($this->leases->heartbeat($this->worker($request), $attemptId, $this->leaseToken($request), (int) $validated['fencing_token']));
        } catch (LeaseConflict $conflict) {
            return $this->problem($conflict);
        }
    }

    public function result(CompleteAttemptRequest $request, string $attemptId): JsonResponse
    {
        try {
            $this->leases->complete($this->worker($request), $attemptId, $this->leaseToken($request), $request->all());
        } catch (LeaseConflict $conflict) {
            return $this->problem($conflict);
        }

        return response()->json(['request_id' => (string) Str::uuid(), 'accepted' => true]);
    }

    private function worker(Request $request): BrowserWorker
    {
        return $request->attributes->get('browser_worker');
    }

    private function leaseToken(Request $request): string
    {
        return (string) $request->header('X-BW-Lease-Token', '');
    }

    private function problem(LeaseConflict $conflict): JsonResponse
    {
        return response()->json([
            'code' => $conflict->reasonCode,
            'message' => 'The attempt lease is not valid for this request.',
            'request_id' => (string) Str::uuid(),
        ], $conflict->httpStatus);
    }
}
