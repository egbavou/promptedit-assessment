<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\GenerationValidationRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreGenerationRequest extends FormRequest
{
    use GenerationValidationRules;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->generationRules(promptRequired: true);
    }
}
