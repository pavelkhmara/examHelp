<?php

namespace App\Support;

/**
 * Language Helper
 *
 * Provides utilities for working with language codes and names.
 */
class LanguageHelper
{
    /**
     * Map of ISO 639-1 language codes to full language names
     */
    private const LANGUAGE_MAP = [
        'en' => 'English',
        'pl' => 'Polish',
        'de' => 'German',
        'ru' => 'Russian',
        'fr' => 'French',
        'es' => 'Spanish',
        'it' => 'Italian',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'sv' => 'Swedish',
        'no' => 'Norwegian',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'hr' => 'Croatian',
        'sr' => 'Serbian',
        'sl' => 'Slovenian',
        'uk' => 'Ukrainian',
        'be' => 'Belarusian',
        'lt' => 'Lithuanian',
        'lv' => 'Latvian',
        'et' => 'Estonian',
        'tr' => 'Turkish',
        'ar' => 'Arabic',
        'he' => 'Hebrew',
        'zh' => 'Chinese',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'hi' => 'Hindi',
        'th' => 'Thai',
        'vi' => 'Vietnamese',
        'id' => 'Indonesian',
        'ms' => 'Malay',
    ];

    /**
     * Convert language code to full language name
     *
     * @param  string|null  $code  ISO 639-1 language code (e.g., 'en', 'pl')
     * @return string Full language name (e.g., 'English', 'Polish')
     */
    public static function getLanguageName(?string $code): string
    {
        if ($code === null || $code === '') {
            return 'English'; // Default fallback
        }

        // If already a full name (first letter uppercase), return as is
        if (strlen($code) > 2 && ctype_upper($code[0])) {
            return $code;
        }

        // Convert code to lowercase for lookup
        $code = strtolower($code);

        return self::LANGUAGE_MAP[$code] ?? 'English';
    }

    /**
     * Check if a given string is a language code or full name
     *
     * @param  string  $value  Value to check
     * @return string 'code' or 'name'
     */
    public static function detectFormat(string $value): string
    {
        if (strlen($value) === 2 && ctype_lower($value)) {
            return 'code';
        }

        return 'name';
    }

    /**
     * Get all supported language codes
     *
     * @return array<string> Array of language codes
     */
    public static function getSupportedCodes(): array
    {
        return array_keys(self::LANGUAGE_MAP);
    }

    /**
     * Get all supported language names
     *
     * @return array<string> Array of language names
     */
    public static function getSupportedNames(): array
    {
        return array_values(self::LANGUAGE_MAP);
    }
}
