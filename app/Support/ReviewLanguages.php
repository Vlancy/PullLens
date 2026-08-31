<?php

namespace App\Support;

use ResourceBundle;

/**
 * ISO 639-1 language list sourced from ICU data via PHP's intl extension.
 * No hardcoded labels — names are maintained by the Unicode CLDR project.
 */
class ReviewLanguages
{
    /**
     * All ISO 639-1 languages as {value, label} pairs, sorted alphabetically by label.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function all(): array
    {
        $bundle = ResourceBundle::create('en', 'ICUDATA-lang');
        $languages = [];

        foreach ($bundle->get('Languages') as $code => $label) {
            if (preg_match('/^[a-z]{2}$/', $code) && is_string($label) && $label !== '') {
                $languages[] = ['value' => $code, 'label' => $label];
            }
        }

        usort($languages, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $languages;
    }

    /**
     * All valid ISO 639-1 language codes — used for validation.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_column(static::all(), 'value');
    }
}
