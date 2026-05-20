<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Nightwatch\Api\NightwatchProjectPickerService;
use Illuminate\Http\JsonResponse;

final class NightwatchProjectController extends Controller
{
    public function __construct(
        private readonly NightwatchProjectPickerService $service,
    ) {
        //
    }

    public function index(): JsonResponse
    {
        return response()->json($this->service->index());
    }
}
