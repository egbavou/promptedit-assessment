<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\GenerationValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

final class EstimateGenerationRequest extends FormRequest
{
    use GenerationValidationRules;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->generationRules(promptRequired: false);
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => (new ValidationException($validator))->getMessage(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
