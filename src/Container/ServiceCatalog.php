<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Container;

use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * DB-free introspection of the Symfony service container.
 *
 * Service tags and (for private services) classes are compile-time metadata:
 * the live runtime container has stripped them. To recover the full picture we
 * reload the debug container XML dump (the same source `bin/console
 * debug:container` reads) into a fresh {@see ContainerBuilder} and inspect its
 * definitions — no service is instantiated and no database is touched.
 *
 * The dump only exists when the kernel runs in debug mode; when it is missing
 * {@see self::isAvailable()} returns false and the tools degrade gracefully.
 */
final class ServiceCatalog
{
    private bool $loaded = false;
    private ?ContainerBuilder $builder = null;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * Whether the debug container dump is available and could be loaded.
     */
    public function isAvailable(): bool
    {
        return $this->builder() instanceof ContainerBuilder;
    }

    /**
     * Filtered list of service definitions.
     *
     * @param array{
     *     tag?: ?string,
     *     class?: ?string,
     *     idLike?: ?string,
     *     public?: ?bool,
     *     limit?: int,
     *     offset?: int,
     * } $criteria
     *
     * @return array{total: int, count: int, services: list<array<string, mixed>>}
     */
    public function services(array $criteria = []): array
    {
        $builder = $this->builder();
        if (!$builder instanceof ContainerBuilder) {
            return ['total' => 0, 'count' => 0, 'services' => []];
        }

        $tag = self::str($criteria['tag'] ?? null);
        $class = self::str($criteria['class'] ?? null);
        $idLike = self::str($criteria['idLike'] ?? null);
        $public = $criteria['public'] ?? null;
        $limit = max(1, (int) ($criteria['limit'] ?? 100));
        $offset = max(0, (int) ($criteria['offset'] ?? 0));

        $classNeedle = $class !== null ? strtolower($class) : null;
        $idNeedle = $idLike !== null ? strtolower($idLike) : null;

        $matched = [];
        foreach ($builder->getDefinitions() as $id => $definition) {
            $serviceClass = $definition->getClass();

            if ($tag !== null && !$definition->hasTag($tag)) {
                continue;
            }
            if ($classNeedle !== null && !str_contains(strtolower((string) $serviceClass), $classNeedle)) {
                continue;
            }
            if ($idNeedle !== null && !str_contains(strtolower((string) $id), $idNeedle)) {
                continue;
            }
            if ($public !== null && $definition->isPublic() !== $public) {
                continue;
            }

            $matched[(string) $id] = $this->summary((string) $id, $definition);
        }

        ksort($matched);
        $services = array_values($matched);

        return [
            'total' => \count($services),
            'count' => \count(\array_slice($services, $offset, $limit)),
            'services' => array_values(\array_slice($services, $offset, $limit)),
        ];
    }

    /**
     * Every tag name in the container with the number of services carrying it.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function tags(): array
    {
        $builder = $this->builder();
        if (!$builder instanceof ContainerBuilder) {
            return [];
        }

        $counts = [];
        foreach ($builder->getDefinitions() as $definition) {
            foreach (array_keys($definition->getTags()) as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        ksort($counts);

        return array_map(
            static fn (string $name, int $count): array => ['tag' => $name, 'count' => $count],
            array_keys($counts),
            array_values($counts),
        );
    }

    /**
     * Full detail of one service (or alias), or null if the id is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $id): ?array
    {
        $builder = $this->builder();
        if (!$builder instanceof ContainerBuilder) {
            return null;
        }

        if ($builder->hasAlias($id)) {
            $alias = $builder->getAlias($id);

            return [
                'id' => $id,
                'kind' => 'alias',
                'aliasFor' => (string) $alias,
                'public' => $alias->isPublic(),
            ];
        }

        if (!$builder->hasDefinition($id)) {
            return null;
        }

        $definition = $builder->getDefinition($id);

        return [
            'id' => $id,
            'kind' => 'service',
            'class' => $definition->getClass(),
            'public' => $definition->isPublic(),
            'shared' => $definition->isShared(),
            'abstract' => $definition->isAbstract(),
            'lazy' => $definition->isLazy(),
            'synthetic' => $definition->isSynthetic(),
            'deprecated' => $definition->isDeprecated(),
            'autowired' => $definition->isAutowired(),
            'file' => $definition->getFile(),
            'factory' => $this->factory($definition),
            'tags' => $definition->getTags(),
            'arguments' => \count($definition->getArguments()),
            'methodCalls' => array_map(
                static fn (array $call): string => (string) ($call[0] ?? ''),
                $definition->getMethodCalls(),
            ),
            'aliases' => $this->aliasesOf($builder, $id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $id, Definition $definition): array
    {
        return [
            'id' => $id,
            'class' => $definition->getClass(),
            'public' => $definition->isPublic(),
            'tags' => array_keys($definition->getTags()),
        ];
    }

    /**
     * @return list<string>
     */
    private function aliasesOf(ContainerBuilder $builder, string $id): array
    {
        $aliases = [];
        foreach ($builder->getAliases() as $name => $alias) {
            if ((string) $alias === $id) {
                $aliases[] = (string) $name;
            }
        }
        sort($aliases);

        return $aliases;
    }

    private function factory(Definition $definition): ?string
    {
        $factory = $definition->getFactory();
        if ($factory === null) {
            return null;
        }
        if (\is_string($factory)) {
            return $factory;
        }
        if (\is_array($factory)) {
            [$target, $method] = $factory + [null, null];
            $target = $target instanceof \Symfony\Component\DependencyInjection\Reference
                ? '@' . (string) $target
                : (\is_object($target) ? $target::class : (string) $target);

            return \sprintf('%s::%s', $target, (string) $method);
        }

        return null;
    }

    /**
     * Load (once) the debug container XML dump into a standalone ContainerBuilder.
     * Nothing is compiled and no service is instantiated — we only read the
     * definitions, so this is safe and side-effect free.
     */
    private function builder(): ?ContainerBuilder
    {
        if ($this->loaded) {
            return $this->builder;
        }
        $this->loaded = true;

        $container = $this->kernel->getContainer();
        if (!$this->kernel->isDebug() || !$container->hasParameter('debug.container.dump')) {
            return $this->builder = null;
        }

        $dump = $container->getParameter('debug.container.dump');
        if (!\is_string($dump) || $dump === '' || !is_file($dump)) {
            return $this->builder = null;
        }
        // A stale dump would misreport the container; require it fresh.
        if (!(new ConfigCache($dump, true))->isFresh()) {
            return $this->builder = null;
        }

        try {
            $builder = new ContainerBuilder(new EnvPlaceholderParameterBag());
            (new XmlFileLoader($builder, new FileLocator()))->load($dump);
        } catch (\Throwable) {
            return $this->builder = null;
        }

        return $this->builder = $builder;
    }

    private static function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
