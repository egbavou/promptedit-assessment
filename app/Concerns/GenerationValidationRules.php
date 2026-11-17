<?php

declare(strict_types=1);

namespace App\Concerns;

trait GenerationValidationRules
{
    /** @return array<string, list<string>> */
    protected function generationRules(bool $promptRequired): array
    {
        return [
            'prompt' => [$promptRequired ? 'required' : 'nullable', 'string', 'max:4000'],
            'size' => ['required', 'string', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['required', 'string', 'in:low,medium,high'],
            'reference_images' => ['array', 'list', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
        ]);
    }
}
