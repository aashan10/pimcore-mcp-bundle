<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Repository;

use Pimcore\Model\Property\Predefined;

/**
 * CRUD access layer over Pimcore's predefined properties
 * ({@see Predefined}, stored via the location-aware config / settings store).
 */
final class PredefinedPropertyRepository
{
    /** Property value types. */
    public const TYPES = ['text', 'document', 'asset', 'object', 'bool', 'select'];

    /** Element types a predefined property can be attached to. */
    public const CTYPES = ['document', 'asset', 'object'];

    /**
     * @return Predefined[]
     */
    public function all(): array
    {
        return (new Predefined\Listing())->load();
    }

    public function find(string $id): ?Predefined
    {
        return Predefined::getById($id);
    }

    public function create(string $name, string $key, string $type, string $ctype, array $optional): Predefined
    {
        $this->assertType($type);
        $this->assertCtype($ctype);

        $property = new Predefined();
        $property->setName($name);
        $property->setKey($key);
        $property->setType($type);
        $property->setCtype($ctype);
        $this->apply($property, $optional);
        $property->save();

        return $property;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes): ?Predefined
    {
        $property = Predefined::getById($id);
        if ($property === null) {
            return null;
        }

        if (\array_key_exists('type', $changes)) {
            $this->assertType((string) $changes['type']);
        }
        if (\array_key_exists('ctype', $changes)) {
            $this->assertCtype((string) $changes['ctype']);
        }

        $this->apply($property, $changes);
        $property->save();

        return $property;
    }

    public function delete(string $id): bool
    {
        $property = Predefined::getById($id);
        if ($property === null) {
            return false;
        }

        $property->delete();

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function apply(Predefined $property, array $values): void
    {
        if (\array_key_exists('name', $values)) {
            $property->setName((string) $values['name']);
        }
        if (\array_key_exists('key', $values)) {
            $property->setKey((string) $values['key']);
        }
        if (\array_key_exists('type', $values)) {
            $property->setType((string) $values['type']);
        }
        if (\array_key_exists('ctype', $values)) {
            $property->setCtype((string) $values['ctype']);
        }
        if (\array_key_exists('data', $values)) {
            $property->setData($values['data'] !== null ? (string) $values['data'] : null);
        }
        if (\array_key_exists('config', $values)) {
            $property->setConfig((string) ($values['config'] ?? ''));
        }
        if (\array_key_exists('description', $values)) {
            $property->setDescription($values['description'] !== null ? (string) $values['description'] : null);
        }
        if (\array_key_exists('inheritable', $values)) {
            $property->setInheritable((bool) $values['inheritable']);
        }
    }

    private function assertType(string $type): void
    {
        if (!\in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid property type "%s". Allowed: %s.', $type, implode(', ', self::TYPES)),
            );
        }
    }

    private function assertCtype(string $ctype): void
    {
        if (!\in_array($ctype, self::CTYPES, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid ctype "%s". Allowed: %s.', $ctype, implode(', ', self::CTYPES)),
            );
        }
    }
}
