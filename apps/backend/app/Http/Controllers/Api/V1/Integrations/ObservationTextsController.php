<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Support\Checkout\ObservationTexts;
use Illuminate\Http\JsonResponse;

class ObservationTextsController extends Controller
{
    public function show(ObservationTexts $texts): JsonResponse
    {
        return response()->json($texts->all());
    }
}
