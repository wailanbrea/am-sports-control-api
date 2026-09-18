<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Accounting\LedgerReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerEntryController extends Controller
{
    public function reverse(Request $request, int $ledgerEntry, LedgerReversalService $service): JsonResponse
    {
        $data = $request->validate([
            'business_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $companyId = $request->user()->companies()->wherePivot('status', 'active')->value('companies.id');
        abort_unless($companyId, 403, 'El usuario no tiene una empresa activa.');

        $reversal = $service->reverse($request->user(), $companyId, $ledgerEntry, $data);

        return response()->json(['success' => true, 'message' => 'Asiento revertido correctamente.', 'data' => $reversal], 201);
    }
}
