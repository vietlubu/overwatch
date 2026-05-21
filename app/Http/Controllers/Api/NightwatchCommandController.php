<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Nightwatch\Api\NightwatchCommandScreenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NightwatchCommandController extends Controller
{
    public function __construct(
        private readonly NightwatchCommandScreenService $service,
    ) {
        //
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:nw_projects,id'],
            'environment' => ['prohibited'],
            'range' => ['nullable', 'in:1h,24h,7d,14d,30d'],
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->service->index($filters));
    }

    public function show(Request $request, string $groupHash): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:nw_projects,id'],
            'environment' => ['prohibited'],
        ]);

        return response()->json($this->service->show($groupHash, $filters));
    }
}
