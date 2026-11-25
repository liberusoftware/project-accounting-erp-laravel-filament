<?php

declare(strict_types=1);

namespace Liberu\Accounting\GeneralLedgerApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Liberu\Accounting\Erp\Services\GeneralLedgerService;
use Illuminate\Routing\Controller;

/**
 * Read-only general-ledger reporting endpoints.
 */
class ErpGeneralLedgerController extends Controller
{
    public function __construct(private readonly GeneralLedgerService $ledger) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $date = $request->date('date') ?? now();

        return response()->json($this->ledger->getTrialBalance($date)->values());
    }

    public function balances(Request $request): JsonResponse
    {
        $start = $request->date('start_date') ?? now()->startOfYear();
        $end = $request->date('end_date') ?? now();

        return response()->json($this->ledger->getAccountBalances($start, $end)->values());
    }
}
