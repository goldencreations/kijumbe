<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\SelcomC2BService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SelcomC2BController extends Controller
{
    public function lookup(Request $request, SelcomC2BService $service): JsonResponse
    {
        $service->authenticate($request->header('Authorization'));

        return response()->json($service->lookup($request->json()->all()));
    }

    public function validation(Request $request, SelcomC2BService $service): JsonResponse
    {
        $service->authenticate($request->header('Authorization'));

        return response()->json($service->validation($request->json()->all()));
    }

    public function notification(Request $request, SelcomC2BService $service): JsonResponse
    {
        $service->authenticate($request->header('Authorization'));

        return response()->json($service->notification($request->json()->all()));
    }
}
