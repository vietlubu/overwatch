<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Nightwatch\Api\NightwatchDashboardScreenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NightwatchDashboardController extends Controller
{
    public function __construct(
        private readonly NightwatchDashboardScreenService $service,
    ) {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:nw_projects,id'],
            'environment' => ['prohibited'],
            'range' => ['nullable', 'in:1h,24h,7d,14d,30d'],
        ]);

        return response()->json($this->service->index($filters));
    }
}
