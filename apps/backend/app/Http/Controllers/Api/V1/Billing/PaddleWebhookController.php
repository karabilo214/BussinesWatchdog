<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingEventInbox;
use App\Support\Billing\Paddle\PaddleConfig;
use App\Support\Billing\Paddle\PaddleEventRecorder;
use App\Support\Billing\Paddle\PaddleSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Paddle → us: the signature is checked over the raw body, the event is stored sanitized and acknowledged; work happens in `billing:process-events`. */
class PaddleWebhookController extends Controller
{
    public function store(Request $request, PaddleEventRecorder $recorder): JsonResponse
    {
        $secret = PaddleConfig::webhookSecret();

        if ($secret === null) {
            return response()->json(['code' => 'webhook_not_configured'], 409);
        }

        if (! PaddleSignature::valid((string) $request->header('Paddle-Signature'), $request->getContent(), $secret)) {
            return response()->json(['code' => 'signature_invalid'], 400);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event)) {
            return response()->json(['code' => 'payload_invalid'], 400);
        }

        $outcome = $recorder->record($event, BillingEventInbox::SOURCE_WEBHOOK);

        return $outcome === 'invalid'
            ? response()->json(['code' => 'payload_invalid'], 400)
            : response()->json(['received' => true, 'outcome' => $outcome]);
    }
}
