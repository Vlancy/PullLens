<?php

namespace App\Support\Seo;

/**
 * The schema.org graph the landing page publishes.
 *
 * Search engines use it for rich results; answer engines use it as the machine
 * readable version of the page, which is the only version some of them read in
 * full. Everything here restates something a visitor can see on the page - an
 * entity described only in JSON-LD is a structured data violation, not a
 * shortcut.
 *
 * One graph rather than four separate blocks, so the entities can reference each
 * other by @id: the application is published by the organisation, the FAQ is
 * part of the same page, and a crawler resolves all of it in a single parse.
 */
final class StructuredData
{
    /** Where the project and its source live. */
    private const SOURCE_URL = 'https://github.com/Vlancy/PullLens';

    private const VENDOR_URL = 'https://vlancy.com';

    /**
     * Build the graph for the landing page.
     *
     * @return array<string, mixed>
     */
    public static function forLandingPage(): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $name = (string) config('app.name');
        $description = (string) config('pulllens.meta.description');
        $image = $base.'/'.ltrim((string) config('pulllens.meta.image'), '/');

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $base.'/#organization',
                    'name' => 'Vlancy LTD',
                    'url' => self::VENDOR_URL,
                    'logo' => $base.'/logo.png',
                    'sameAs' => [self::SOURCE_URL, self::VENDOR_URL],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $base.'/#website',
                    'url' => $base.'/',
                    'name' => $name,
                    'description' => $description,
                    'inLanguage' => 'en',
                    'publisher' => ['@id' => $base.'/#organization'],
                ],
                [
                    '@type' => 'WebPage',
                    '@id' => $base.'/#webpage',
                    'url' => $base.'/',
                    'name' => (string) config('pulllens.meta.title'),
                    'description' => $description,
                    'inLanguage' => 'en',
                    'isPartOf' => ['@id' => $base.'/#website'],
                    'about' => ['@id' => $base.'/#software'],
                    'primaryImageOfPage' => $image,
                ],
                self::softwareApplication($base, $name, $description, $image),
                self::faqPage($base),
            ],
        ];
    }

    /**
     * The product itself. `offers` at zero is what makes a free tool eligible
     * for the price treatment in a rich result; omitting it reads as "unknown".
     *
     * @return array<string, mixed>
     */
    private static function softwareApplication(string $base, string $name, string $description, string $image): array
    {
        return [
            '@type' => 'SoftwareApplication',
            '@id' => $base.'/#software',
            'name' => $name,
            'url' => $base.'/',
            'description' => $description,
            'applicationCategory' => 'DeveloperApplication',
            'applicationSubCategory' => 'Code review',
            'operatingSystem' => 'Linux, macOS, Windows (Docker)',
            'softwareRequirements' => 'Docker, PostgreSQL, Redis',
            'image' => $image,
            'screenshot' => $image,
            'license' => self::SOURCE_URL.'/blob/main/LICENSE',
            'isAccessibleForFree' => true,
            'codeRepository' => self::SOURCE_URL,
            'downloadUrl' => self::SOURCE_URL,
            'installUrl' => $base.'/docs/guide/installation.html',
            'softwareHelp' => ['@type' => 'CreativeWork', 'url' => $base.'/docs'],
            'publisher' => ['@id' => $base.'/#organization'],
            'author' => ['@id' => $base.'/#organization'],
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
            ],
            'featureList' => [
                'Automatic AI review of every pull request',
                'Inline comments and threaded replies on GitHub',
                'Security, quality and risk findings before merge',
                'Tasks extracted from review findings',
                'Team, repository and developer quality reports',
                'AI token usage and cost reporting',
                'Bring your own model across twelve AI providers',
                'Self-hosted on your own infrastructure',
                'Role based access control',
            ],
        ];
    }

    /**
     * The FAQ, built from the same array the visible section renders.
     *
     * @return array<string, mixed>
     */
    private static function faqPage(string $base): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $base.'/#faq',
            'isPartOf' => ['@id' => $base.'/#webpage'],
            'mainEntity' => array_map(static fn (array $entry): array => [
                '@type' => 'Question',
                'name' => $entry['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $entry['answer'],
                ],
            ], LandingPageFaq::all()),
        ];
    }

    /**
     * The graph as the JSON that goes inside the script tag.
     *
     * JSON_UNESCAPED_SLASHES keeps the URLs readable when somebody views source
     * to debug a rich result, and matches what the testing tools echo back.
     * JSON_HEX_TAG escapes angle brackets, so no value here can ever close the
     * script element early - the content is ours today, but a future field read
     * from configuration should not be able to turn this into an injection.
     */
    public static function json(): string
    {
        return (string) json_encode(
            self::forLandingPage(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
    }
}
