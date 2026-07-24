<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Repository\TranslationRepository;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\Translation;

/**
 * MCP tools for managing Pimcore translations (shared and admin domains).
 */
final class TranslationTool
{
    public function __construct(
        private readonly TranslationRepository $translations,
    ) {}

    /**
     * List translations for a domain, optionally filtered by key substring.
     *
     * @param string      $domain  "messages" (shared/frontend) or "admin".
     * @param string|null $keyLike Case-insensitive substring match on the key.
     * @param int         $limit   Max rows (default 200).
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'list_translations', description: 'List Pimcore translations for a domain ("messages" = shared, or "admin"), optionally filtered by key substring.')]
    public function list(string $domain = Translation::DOMAIN_DEFAULT, ?string $keyLike = null, int $limit = 200): array
    {
        if (!$this->validDomain($domain)) {
            return $this->invalidDomain();
        }

        $items = array_map(
            static fn (Translation $t): array => [
                'key' => $t->getKey(),
                'translations' => $t->getTranslations(),
            ],
            $this->translations->all($domain, $keyLike, $limit),
        );

        return ['count' => \count($items), 'domain' => $domain, 'translations' => $items];
    }

    /**
     * Create or update a translation (upsert). Provided languages are merged
     * into any existing entry; other languages are left untouched.
     *
     * @param string $key          The translation key.
     * @param string $translations JSON object of language => text, e.g. {"en":"Hello","de":"Hallo"}.
     * @param string $domain       "messages" (shared) or "admin".
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'set_translation', description: 'Create or update a Pimcore translation. "translations" is a JSON object of language => text; provided languages are merged into any existing entry.')]
    public function set(string $key, string $translations, string $domain = Translation::DOMAIN_DEFAULT): array
    {
        if (!$this->validDomain($domain)) {
            return $this->invalidDomain();
        }
        if ($key === '') {
            return ['error' => 'The "key" argument is required.'];
        }

        $decoded = json_decode($translations, true);
        if (!\is_array($decoded) || $decoded === []) {
            return ['error' => 'The "translations" argument must be a non-empty JSON object of language => text.'];
        }

        $translation = $this->translations->set($key, $domain, $decoded);

        return [
            'saved' => true,
            'key' => $translation->getKey(),
            'domain' => $translation->getDomain(),
            'translations' => $translation->getTranslations(),
        ];
    }

    /**
     * Delete a translation by key.
     *
     * @param string $key    The translation key.
     * @param string $domain "messages" (shared) or "admin".
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'delete_translation', description: 'Delete a Pimcore translation by key from a domain.')]
    public function delete(string $key, string $domain = Translation::DOMAIN_DEFAULT): array
    {
        if (!$this->validDomain($domain)) {
            return $this->invalidDomain();
        }

        if (!$this->translations->delete($key, $domain)) {
            return ['error' => \sprintf('Translation "%s" was not found in domain "%s".', $key, $domain)];
        }

        return ['deleted' => true, 'key' => $key, 'domain' => $domain];
    }

    private function validDomain(string $domain): bool
    {
        return \in_array($domain, TranslationRepository::DOMAINS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidDomain(): array
    {
        return ['error' => \sprintf('Invalid domain. Allowed: %s.', implode(', ', TranslationRepository::DOMAINS))];
    }
}
