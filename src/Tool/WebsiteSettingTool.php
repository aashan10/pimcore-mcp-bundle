<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Tool;

use Aashan\PimcoreMcpBundle\Repository\WebsiteSettingRepository;
use Mcp\Capability\Attribute\McpTool;
use Pimcore\Model\Element\ElementInterface;
use Pimcore\Model\Element\Service;
use Pimcore\Model\WebsiteSetting;

/**
 * MCP tools for inspecting and managing Pimcore website settings
 * (the `website_settings` table).
 *
 * `data` is always exchanged as a string and coerced to the setting `type`:
 *  - text            → stored verbatim;
 *  - bool            → "1"/"0"/"true"/"false";
 *  - document/asset/object → the referenced element id.
 */
final class WebsiteSettingTool
{
    public function __construct(
        private readonly WebsiteSettingRepository $settings,
    ) {}

    /**
     * List website settings, optionally filtered.
     *
     * @param string|null $nameLike Case-insensitive substring match on the setting name.
     * @param int|null    $siteId   Restrict to a site id (0 = global).
     * @param string|null $language Restrict to a language (e.g. "en").
     * @param int         $limit    Max rows (default 100).
     * @param int         $offset   Rows to skip.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_website_settings',
        description: 'List Pimcore website settings from the website_settings table, optionally filtered by name, site id or language.',
    )]
    public function list(?string $nameLike = null, ?int $siteId = null, ?string $language = null, int $limit = 100, int $offset = 0): array
    {
        $settings = $this->settings->list($nameLike, $siteId, $language, $limit, $offset);

        return [
            'count' => \count($settings),
            'settings' => array_map(fn (WebsiteSetting $s): array => $this->serialize($s), $settings),
            'allowedTypes' => WebsiteSettingRepository::TYPES,
        ];
    }

    /**
     * Get a single website setting by id, or by name (with optional site/language).
     *
     * @param int|null    $id       The setting id. Takes precedence over $name.
     * @param string|null $name     The setting name (used when $id is omitted).
     * @param int|null    $siteId   Site id used for name resolution (0 = global).
     * @param string|null $language Language used for name resolution.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_website_setting',
        description: 'Get one Pimcore website setting by id, or by name with optional site id / language resolution.',
    )]
    public function get(?int $id = null, ?string $name = null, ?int $siteId = null, ?string $language = null): array
    {
        $setting = $id !== null
            ? $this->settings->find($id)
            : ($name !== null ? $this->settings->findByName($name, $siteId, $language) : null);

        if ($setting === null) {
            return ['error' => 'Website setting not found.', '_hint' => 'Provide a valid "id", or a "name" (plus optional siteId/language).'];
        }

        return ['setting' => $this->serialize($setting)];
    }

    /**
     * Create a new website setting.
     *
     * @param string      $name     The setting name.
     * @param string      $type     One of: text, document, asset, object, bool.
     * @param string      $data     The value (coerced to $type — see tool notes).
     * @param string|null $language Language scope (empty = all languages).
     * @param int|null    $siteId   Site scope (0/empty = global).
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_website_setting',
        description: 'Create a new Pimcore website setting. type must be one of text, document, asset, object, bool; data is coerced to that type.',
    )]
    public function create(string $name, string $type, string $data, ?string $language = null, ?int $siteId = null): array
    {
        try {
            $setting = $this->settings->create($name, $type, $data, $language, $siteId);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        return ['created' => true, 'setting' => $this->serialize($setting)];
    }

    /**
     * Update an existing website setting. Only the arguments you pass are changed.
     *
     * @param int         $id       The setting id.
     * @param string|null $name     New name.
     * @param string|null $type     New type (text, document, asset, object, bool).
     * @param string|null $data     New value (coerced to the effective type).
     * @param string|null $language New language scope.
     * @param int|null    $siteId   New site scope.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'update_website_setting',
        description: 'Update an existing Pimcore website setting by id. Only the provided fields are changed.',
    )]
    public function update(int $id, ?string $name = null, ?string $type = null, ?string $data = null, ?string $language = null, ?int $siteId = null): array
    {
        $changes = [];
        if ($name !== null) {
            $changes['name'] = $name;
        }
        if ($type !== null) {
            $changes['type'] = $type;
        }
        if ($data !== null) {
            $changes['data'] = $data;
        }
        if ($language !== null) {
            $changes['language'] = $language;
        }
        if ($siteId !== null) {
            $changes['siteId'] = $siteId;
        }

        if ($changes === []) {
            return ['error' => 'No fields to update were provided.'];
        }

        try {
            $setting = $this->settings->update($id, $changes);
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        }

        if ($setting === null) {
            return ['error' => \sprintf('Website setting with id %d was not found.', $id)];
        }

        return ['updated' => true, 'setting' => $this->serialize($setting)];
    }

    /**
     * Delete a website setting by id.
     *
     * @param int $id The setting id.
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'delete_website_setting',
        description: 'Delete a Pimcore website setting by id. This permanently removes the row from website_settings.',
    )]
    public function delete(int $id): array
    {
        $deleted = $this->settings->delete($id);
        if (!$deleted) {
            return ['error' => \sprintf('Website setting with id %d was not found.', $id)];
        }

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WebsiteSetting $setting): array
    {
        return [
            'id' => $setting->getId(),
            'name' => $setting->getName(),
            'type' => $setting->getType(),
            'language' => $setting->getLanguage() !== '' ? $setting->getLanguage() : null,
            'siteId' => $setting->getSiteId(),
            'data' => $this->serializeData($setting),
            'creationDate' => $setting->getCreationDate(),
            'modificationDate' => $setting->getModificationDate(),
        ];
    }

    private function serializeData(WebsiteSetting $setting): mixed
    {
        $data = $setting->getData();

        // Element-typed settings resolve to a referenced element; expose a
        // lightweight reference instead of the full object.
        if ($data instanceof ElementInterface) {
            return [
                'elementType' => Service::getElementType($data),
                'id' => $data->getId(),
                'path' => $data->getRealFullPath(),
            ];
        }

        return $data;
    }
}
