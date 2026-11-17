<?php

declare(strict_types=1);

namespace App\Enums;

enum FailureReason: string
{
    case PromptRejected = 'prompt_rejected';
    case DailyQuotaReached = 'daily_quota_reached';
    case ProviderBusy = 'provider_busy';
    case ProviderError = 'provider_error';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    public function message(): string
    {
        return match ($this) {
            self::PromptRejected => 'Pixelforge rejected this prompt under its content policy. Nothing was charged.',
            self::DailyQuotaReached => "Pixelforge's daily generation quota is used up. Nothing was charged; please try again after midnight UTC.",
            self::ProviderBusy => 'Pixelforge was too busy to accept this generation. Nothing was charged; please try again.',
            self::ProviderError => 'Pixelforge could not accept this generation. Nothing was charged.',
            self::GenerationFailed => 'Pixelforge could not produce an image for this prompt. Nothing was charged.',
            self::InternalError => 'Pixelforge failed while generating. Nothing was charged; retrying usually works.',
        };
    }
}
