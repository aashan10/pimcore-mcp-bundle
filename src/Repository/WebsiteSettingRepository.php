<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Cache\RuntimeCache;
use Pimcore\Model\WebsiteSetting;

/**
 * CRUD access layer over Pimcore's `website_settings` table via the
 * {@see WebsiteSetting} model.
 */
final class WebsiteSettingRepository
{
    /**
     * Allowed values of the `type` column (enum in the DB).
     */
    public const TYPES = ['text', 'document', 'asset', 'object', 'bool'];

    private const ELEMENT_TYPES = ['document', 'asset', 'object'];

    /**
     * @return WebsiteSetting[]
     */
    public function list(?string $nameLike, ?int $siteId, ?string $language, int $limit, int $offset): array
    {
        $listing = new WebsiteSetting\Listing();

        $conditions = [];
        $variables = [];
        if ($nameLike !== null && $nameLike !== '') {
            $conditions[] = 'name LIKE ?';
            $variables[] = '%' . $nameLike . '%';
        }
        if ($siteId !== null) {
            $conditions[] = 'siteId = ?';
            $variables[] = $siteId;
        }
        if ($language !== null && $language !== '') {
            $conditions[] = 'language = ?';
            $variables[] = $language;
        }
        if ($conditions !== []) {
            $listing->setCondition(implode(' AND ', $conditions), $variables);
        }

        if ($limit > 0) {
            $listing->setLimit($limit);
        }
        if ($offset > 0) {
            $listing->setOffset($offset);
        }
        $listing->setOrderKey('name');
        $listing->setOrder('ASC');

        return $listing->getSettings();
    }

    public function find(int $id): ?WebsiteSetting
    {
        return WebsiteSetting::getById($id);
    }

    public function findByName(string $name, ?int $siteId, ?string $language): ?WebsiteSetting
    {
        return WebsiteSetting::getByName($name, $siteId, $language);
    }

    public function create(string $name, string $type, mixed $data, ?string $language, ?int $siteId): WebsiteSetting
    {
        $this->assertType($type);

        $setting = new WebsiteSetting();
        $setting->setName($name);
        $setting->setType($type);
        $setting->setData($this->coerce($type, $data));
        $setting->setLanguage($language ?? '');
        $setting->setSiteId($siteId);
        $setting->save();
        $this->evictRuntimeCache($setting->getId());

        return $setting;
    }

    /**
     * @param array<string, mixed> $changes Only the keys present are applied.
     */
    public function update(int $id, array $changes): ?WebsiteSetting
    {
        $setting = WebsiteSetting::getById($id);
        if ($setting === null) {
            return null;
        }

        if (\array_key_exists('name', $changes)) {
            $setting->setName((string) $changes['name']);
        }
        if (\array_key_exists('type', $changes)) {
            $this->assertType((string) $changes['type']);
            $setting->setType((string) $changes['type']);
        }
        if (\array_key_exists('language', $changes)) {
            $setting->setLanguage((string) ($changes['language'] ?? ''));
        }
        if (\array_key_exists('siteId', $changes)) {
            $setting->setSiteId($changes['siteId'] !== null ? (int) $changes['siteId'] : null);
        }
        // Coerce data against the effective (possibly just-changed) type.
        if (\array_key_exists('data', $changes)) {
            $setting->setData($this->coerce($setting->getType() ?? 'text', $changes['data']));
        }

        $setting->save();
        $this->evictRuntimeCache($setting->getId());

        return $setting;
    }

    public function delete(int $id): bool
    {
        $setting = WebsiteSetting::getById($id);
        if ($setting === null) {
            return false;
        }

        $setting->delete();
        $this->evictRuntimeCache($id);

        return true;
    }

    private function assertType(string $type): void
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid type "%s". Allowed types: %s.', $type, implode(', ', self::TYPES)),
            );
        }
    }

    private function coerce(string $type, mixed $data): mixed
    {
        return match ($type) {
            'bool' => filter_var($data, \FILTER_VALIDATE_BOOLEAN),
            'document', 'asset', 'object' => ($data === null || $data === '') ? null : (int) $data,
            default => $data === null ? null : (string) $data,
        };
    }

    public function isElementType(?string $type): bool
    {
        return \in_array($type, self::ELEMENT_TYPES, true);
    }

    /**
     * WebsiteSetting::getById() memoises instances in the runtime cache and
     * neither save() nor delete() clears it. In the long-lived stdio server
     * that means stale reads after a mutation, so we evict the entry ourselves.
     */
    private function evictRuntimeCache(?int $id): void
    {
        if ($id === null) {
            return;
        }

        RuntimeCache::getInstance()->offsetUnset('website_setting_' . $id);
    }
}
