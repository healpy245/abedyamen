<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AiChatbot\ChatbotInstance;

/**
 * Brand + geography for Malan-family internet bots (Malan, Speedcom).
 * Campaigns and CRM tools stay shared; only display copy and default city change.
 */
final class InternetCompanyProfile
{
    public const SLUG_MALAN = 'malan';

    public const SLUG_SPEEDCOM = 'speedcom';

    public function __construct(
        public readonly string $slug,
        public readonly string $companyName,
        public readonly string $companyNameFull,
        public readonly string $campaignDefaultCity,
        public readonly string $supportedLocations,
    ) {}

    public static function malan(): self
    {
        return new self(
            slug: self::SLUG_MALAN,
            companyName: 'ملان',
            companyNameFull: 'ملان انترنت',
            campaignDefaultCity: 'الطيبة',
            supportedLocations: 'كفرقاسم، كفربرا، الطيبة، الرملة، قلنسوة، وعارة/عرعرة قريب.',
        );
    }

    public static function speedcom(): self
    {
        return new self(
            slug: self::SLUG_SPEEDCOM,
            companyName: 'سبيدكوم',
            companyNameFull: 'سبيدكوم',
            campaignDefaultCity: 'رهط',
            supportedLocations: 'رهط وضواحيها، واللقية.',
        );
    }

    public static function forInstance(?ChatbotInstance $instance): self
    {
        if ($instance === null) {
            return self::malan();
        }

        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $slug = strtolower(trim((string) ($settings['company_slug'] ?? $instance->integration_type ?? self::SLUG_MALAN)));

        return $slug === self::SLUG_SPEEDCOM ? self::speedcom() : self::malan();
    }

    public function isSpeedcom(): bool
    {
        return $this->slug === self::SLUG_SPEEDCOM;
    }

    /**
     * Rewrite Malan/Taybee campaign copy for this company without forking the prompt.
     */
    public function localize(string $text): string
    {
        if ($this->slug === self::SLUG_MALAN || $text === '') {
            return $text;
        }

        $city = $this->campaignDefaultCity;
        $company = $this->companyName;
        $full = $this->companyNameFull;

        return strtr($text, [
            'ملان إنترنت' => $full,
            'ملان انترنت' => $full,
            'شركة ملان' => 'شركة '.$company,
            'بملان' => 'ب'.$company,
            'Malan' => 'Speedcom',
            'Taybee' => $city,
            'للطيبه' => 'ل'.$city,
            'للطيبة' => 'ل'.$city,
            'بالطيبة' => 'ب'.$city,
            'الطيبة' => $city,
            'ملان' => $company,
        ]);
    }
}
