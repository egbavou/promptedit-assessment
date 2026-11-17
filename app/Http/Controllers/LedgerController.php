<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LedgerEntryResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Ledger/Index', [
            'entries' => LedgerEntryResource::collection($request->user()->ledgerEntries()
                ->with('generation')->latest()->orderByDesc('id')->simplePaginate(30)),
        ]);
    }
}
