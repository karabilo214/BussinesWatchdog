<?php

namespace App\Http\Controllers\Internal\Browser;

use App\Http\Controllers\Controller;
use App\Http\Requests\Browser\CompleteAttemptRequest;
use App\Models\BrowserWorker;
use App\Models\CheckRun;
use App\Support\Browser\ArtifactStore;
use App\Support\Browser\BrowserLeaseService;
use App\Support\Browser\LeaseConflict;
use App\Support\Site\PublicStorefrontLeaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class BrowserLeaseController extends Controller
{
    public function __construct(
        private readonly BrowserLeaseService $leases,
        private readonly PublicStorefrontLeaseService $publicStorefront,
    ) {}

    public function lease(Request $request): JsonResponse|Response
    {
        $validated = $request->validate([
            'browser_version' => ['required', 'string', 'min:1', 'max:100'],
            'location' => ['required', 'string', 'min:1', 'max:100'],
            'browser_versions' => ['sometimes', 'array:'.implode(',', CheckRun::BROWSERS)],
            'browser_versions.*' => ['string', 'min:1', 'max:100'],
        ]);
        $browserVersions = $validated['browser_versions'] ?? [];

        $worker = $this->worker($request);
        if ((bool) config('watchdog.public_check.prefer_public_leases') && ! app()->environment('production')) {
            $lease = $this->publicStorefront->lease($worker, $validated['browser_version'], $validated['location'])
                ?? $this->leases->lease($worker, $validated['browser_version'], $validated['location'], $browserVersions);
        } else {
            $lease = $this->leases->lease($worker, $validated['browser_version'], $validated['location'], $browserVersions)
                ?? $this->publicStorefront->lease($worker, $validated['browser_version'], $validated['location']);
        }

        return $lease === null ? response()->noContent() : response()->json($lease);
    }

    public function heartbeat(Request $request, string $attemptId): JsonResponse
    {
        $validated = $request->validate(['fencing_token' => ['required', 'integer', 'min:1']]);

        try {
            return response()->json($this->leases->heartbeat($this->worker($request), $attemptId, $this->leaseToken($request), (int) $validated['fencing_token']));
        } catch (LeaseConflict $conflict) {
            if ($conflict->reasonCode === BrowserLeaseService::ERROR_LEASE_NOT_FOUND) {
                try {
                    return response()->json($this->publicStorefront->heartbeat($this->worker($request), $attemptId, $this->leaseToken($request), (int) $validated['fencing_token']));
                } catch (LeaseConflict $publicConflict) {
                    return $this->problem($publicConflict);
                }
            }

            return $this->problem($conflict);
        }
    }

    public function result(CompleteAttemptRequest $request, string $attemptId): JsonResponse
    {
        try {
            $this->leases->complete($this->worker($request), $attemptId, $this->leaseToken($request), $request->all());
        } catch (LeaseConflict $conflict) {
            if ($conflict->reasonCode === BrowserLeaseService::ERROR_LEASE_NOT_FOUND) {
                try {
                    $this->publicStorefront->complete($this->worker($request), $attemptId, $this->leaseToken($request), $request->all());
                } catch (LeaseConflict $publicConflict) {
                    return $this->problem($publicConflict);
                }

                return response()->json(['request_id' => (string) Str::uuid(), 'accepted' => true]);
            }

            return $this->problem($conflict);
        }

        return response()->json(['request_id' => (string) Str::uuid(), 'accepted' => true]);
    }

    public function artifact(Request $request, string $attemptId, ArtifactStore $artifacts): JsonResponse
    {
        try {
            $artifact = $artifacts->storeScreenshot(
                $this->worker($request),
                $attemptId,
                $this->leaseToken($request),
                (int) $request->header('X-BW-Fencing-Token', '0'),
                strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0])),
                strtolower((string) $request->header('X-BW-Sha256', '')),
                (string) $request->header('X-BW-Redaction-Version', ''),
                (string) $request->getContent(),
            );
        } catch (LeaseConflict $conflict) {
            if ($conflict->reasonCode === BrowserLeaseService::ERROR_LEASE_NOT_FOUND) {
                try {
                    $artifact = $this->publicStorefront->storeScreenshot(
                        $this->worker($request),
                        $attemptId,
                        $this->leaseToken($request),
                        (int) $request->header('X-BW-Fencing-Token', '0'),
                        strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0])),
                        strtolower((string) $request->header('X-BW-Sha256', '')),
                        (string) $request->header('X-BW-Redaction-Version', ''),
                        (string) $request->getContent(),
                    );
                } catch (LeaseConflict $publicConflict) {
                    return $this->problem($publicConflict);
                }

                return response()->json(['artifact_id' => $artifact->id, 'state' => $artifact->state], 201);
            }

            return $this->problem($conflict);
        }

        return response()->json(['artifact_id' => $artifact->id, 'state' => $artifact->state], 201);
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
