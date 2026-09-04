<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\PaymentBundle\Tests;

use c975L\ConfigBundle\Entity\Config;
use PHPUnit\Framework\TestCase;

// Guards config/configs.json, the list ConfigBundle seeds the backoffice from for this bundle - an entry whose label/description has no translation shows up there as a raw "label.some_key" string
class ConfigsJsonTest extends TestCase
{
    private const array LOCALES = ['en', 'es', 'fr'];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadConfigs(): array
    {
        // Every configs*.json, not just configs.json: ConfigDeclarationLocator globs them all at runtime, so a bundle shipping a second declaration file would otherwise ship it untested
        $files = glob(__DIR__ . '/../config/configs*.json') ?: [];
        $this->assertNotSame([], $files);

        $configs = [];
        foreach ($files as $file) {
            $declared = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($declared, basename($file) . ' is not a list of entries');
            $configs = array_merge($configs, $declared);
        }

        return $configs;
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        $xliff = simplexml_load_file(__DIR__ . '/../translations/site_config.' . $locale . '.xlf');
        $translations = [];
        foreach ($xliff->file->body->{'trans-unit'} as $unit) {
            $translations[(string) $unit->source] = (string) $unit->target;
        }

        return $translations;
    }

    // Two entries sharing a slug would have the second silently shadow the first
    public function testSlugsAreUnique(): void
    {
        $slugs = array_column($this->loadConfigs(), 'slug');

        $this->assertSame(array_unique($slugs), $slugs);
    }

    // Every entry carries the keys ConfigBundle reads
    public function testEntriesCarryTheExpectedKeys(): void
    {
        foreach ($this->loadConfigs() as $config) {
            foreach (['label', 'slug', 'sensitive', 'restricted', 'value', 'kind', 'group', 'severity', 'description'] as $key) {
                $this->assertArrayHasKey($key, $config, sprintf('Config "%s" misses the "%s" key', $config['slug'] ?? '?', $key));
            }
        }
    }

    // A kind outside Config::TYPES is not refused, it is ignored: the entry falls back on a plain text field, so a date, a switch or a select silently becomes a box to type in
    public function testKindsAreOnesConfigBundleDraws(): void
    {
        foreach ($this->loadConfigs() as $config) {
            $this->assertContains($config['kind'], Config::TYPES, sprintf('Config "%s" declares the kind "%s", which is none of Config::TYPES', $config['slug'], $config['kind']));
        }
    }

    // Same for a severity, which colours the entry in the listing and is read as none when it is not one of Config::SEVERITIES
    public function testSeveritiesAreOnesConfigBundleReads(): void
    {
        foreach ($this->loadConfigs() as $config) {
            if (null === $config['severity']) {
                continue;
            }

            $this->assertContains($config['severity'], Config::SEVERITIES, sprintf('Config "%s" declares the severity "%s", which is none of Config::SEVERITIES', $config['slug'], $config['severity']));
        }
    }

    // A "choice" entry is only worth its kind if it says what it accepts, and if its own default is part of it - the select is built from that list alone (see ConfigCrudController::buildChoiceField)
    public function testChoiceEntriesDeclareTheValuesTheyAccept(): void
    {
        foreach ($this->loadConfigs() as $config) {
            if (Config::TYPE_CHOICE !== $config['kind']) {
                continue;
            }

            $slug = $config['slug'];
            $this->assertArrayHasKey('choices', $config, sprintf('Config "%s" is a choice but declares no choices', $slug));
            $this->assertNotSame([], $config['choices'], sprintf('Config "%s" declares an empty choices list', $slug));

            if (null !== $config['value']) {
                $this->assertContains($config['value'], $config['choices'], sprintf('Default value of "%s" is not one of its choices', $slug));
            }
        }
    }

    // What the backoffice displays is the translation of the key derived from the slug, not of the stored label (see ConfigLabelResolver): an entry whose label says anything else shows up as that raw key, however well translated it is
    public function testLabelsAreTheKeyDerivedFromTheirSlug(): void
    {
        foreach ($this->loadConfigs() as $config) {
            $this->assertSame('label.' . str_replace('-', '_', $config['slug']), $config['label'], sprintf('Config "%s" declares a label key its slug does not derive', $config['slug']));
        }
    }

    // Both the label and the description of every entry are translated in each shipped locale
    public function testLabelsAndDescriptionsAreTranslatedInEveryLocale(): void
    {
        $configs = $this->loadConfigs();

        foreach (self::LOCALES as $locale) {
            $translations = $this->loadTranslations($locale);
            foreach ($configs as $config) {
                foreach ([$config['label'], $config['description']] as $key) {
                    $this->assertArrayHasKey($key, $translations, sprintf('"%s" has no %s translation', $key, $locale));
                    $this->assertNotSame('', $translations[$key], sprintf('"%s" has an empty %s translation', $key, $locale));
                }
            }
        }
    }

    // The drawer an entry is filed under either belongs to ConfigBundle - one of the shared ones an editor goes looking in (see Config::GROUPS) - or is named by this bundle, which then ships its label in the "config" domain (see ECOSYSTEM.md §15). A drawer named and not labelled shows up on the "pick a group" screen as a raw "label.group_x" string
    public function testGroupsAreEitherSharedOrLabelledByThisBundle(): void
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (array $config): ?string => $config['group'] ?? null,
            $this->loadConfigs()
        ))));

        $own = array_diff($groups, Config::GROUPS);
        if ([] === $own) {
            $this->assertSame([], $own);

            return;
        }

        foreach (self::LOCALES as $locale) {
            $translations = $this->loadGroupLabels($locale);
            foreach ($own as $group) {
                $key = 'label.group_' . $group;
                $this->assertArrayHasKey($key, $translations, sprintf('"%s" has no %s translation, its drawer would read as that key', $key, $locale));
                $this->assertNotSame('', $translations[$key], sprintf('"%s" has an empty %s translation', $key, $locale));
            }
        }
    }

    /**
     * The "config" domain of this bundle, where a drawer of its own is labelled - absent for a bundle naming none.
     *
     * @return array<string, string>
     */
    private function loadGroupLabels(string $locale): array
    {
        $path = __DIR__ . '/../translations/config.' . $locale . '.xlf';
        if (!file_exists($path)) {
            return [];
        }

        $xliff = simplexml_load_file($path);
        $translations = [];
        foreach ($xliff->file->body->{'trans-unit'} as $unit) {
            $translations[(string) $unit->source] = (string) $unit->target;
        }

        return $translations;
    }
}
