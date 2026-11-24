<?php

namespace App\Http\Controllers;

use App\Support\PurchaseOrderScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;

class PurchaseOrderScanController extends Controller
{
    public function __invoke(Request $request, PurchaseOrderScan $scan): JsonResponse
    {
        $validated = $request->validate([
            'scan' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png'])->max(2048)],
        ]);

        return response()->json(['fields' => (object) $scan->extract($validated['scan'])])
            ->header('Cache-Control', 'no-store');
    }
}
