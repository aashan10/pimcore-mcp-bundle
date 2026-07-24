<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\Translation;

/**
 * Access layer over Pimcore shared/admin translations ({@see Translation}).
 */
final class TranslationRepository
{
    public const DOMAINS = [Translation::DOMAIN_DEFAULT, Translation::DOMAIN_ADMIN];

    /**
     * @return Translation[]
     */
    public function all(string $domain, ?string $keyLike, int $limit): array
    {
        $listing = new Translation\Listing();
        $listing->setDomain($domain);
        if ($keyLike !== null && $keyLike !== '') {
            $listing->setCondition('`key` LIKE ?', ['%' . $keyLike . '%']);
        }
        if ($limit > 0) {
            $listing->setLimit($limit);
        }

        return $listing->load();
    }

    public function find(string $key, string $domain): ?Translation
    {
        return Translation::getByKey($key, $domain);
    }

    /**
     * Create or update (upsert) a translation, merging the provided languages.
     *
     * @param array<string, string> $translations language => text
     */
    public function set(string $key, string $domain, array $translations): Translation
    {
        $translation = Translation::getByKey($key, $domain);
        if ($translation === null) {
            $translation = new Translation();
            $translation->setKey($key);
            $translation->setDomain($domain);
        }

        foreach ($translations as $language => $text) {
            $translation->addTranslation((string) $language, (string) $text);
        }
        $translation->save();

        return $translation;
    }

    public function delete(string $key, string $domain): bool
    {
        $translation = Translation::getByKey($key, $domain);
        if ($translation === null) {
            return false;
        }

        $translation->delete();

        return true;
    }
}
