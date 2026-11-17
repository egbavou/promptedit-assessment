<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreditPurchaseController extends Controller
{
    public function __invoke(Request $request, Ledger $ledger): RedirectResponse
    {
        $validated = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        try {
            $ledger->purchase($request->user(), $validated['idempotency_key'], 5_000_000_000);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This purchase key has already been used. Please refresh and try again.',
            ]);
        }

        return back()->with('success', '$5.0000 in credits added.');
    }
}
