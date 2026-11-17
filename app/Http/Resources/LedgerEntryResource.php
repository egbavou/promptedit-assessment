<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Support\Usd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin LedgerEntry */
class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type_label' => match ($this->type) {
                LedgerEntryType::Purchase => 'Purchase',
                LedgerEntryType::Hold => 'Hold',
                LedgerEntryType::HoldRelease => 'Hold released',
                LedgerEntryType::Charge => 'Charge',
            },
            'amount' => $this->amount_nanodollars < 0
                ? str_replace('-$', '−$', Usd::display($this->amount_nanodollars))
                : '+'.Usd::display($this->amount_nanodollars),
            'created_at' => $this->created_at->toIso8601String(),
            'show_url' => $this->generation_id === null ? null : route('generations.show', $this->generation_id),
            'prompt' => $this->generation === null ? null : Str::limit($this->generation->prompt, 60, '…'),
        ];
    }
}
