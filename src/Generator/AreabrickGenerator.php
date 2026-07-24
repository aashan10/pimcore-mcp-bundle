<?php

declare(strict_types=1);

namespace Aashan\PimcoreMcpBundle\Generator;

use Doctrine\Inflector\InflectorFactory;

/**
 * Scaffolds a Pimcore document areabrick: the PHP brick class in
 * `src/Document/Areabrick` and its Twig view template in
 * `templates/areas/<brick-id>`.
 *
 * The brick id is derived exactly the way Pimcore's AreabrickPass does
 * (kebab-case of the class short name), so the generated template lands where
 * Pimcore auto-discovers it. Bricks are auto-registered via container
 * autoconfiguration — no manual service tag needed.
 */
final class AreabrickGenerator
{
    private const LOCAL_ABSTRACT = 'App\\Document\\Areabrick\\AbstractAreabrick';
    private const VENDOR_ABSTRACT = 'Pimcore\\Extension\\Document\\Areabrick\\AbstractTemplateAreabrick';

    public function __construct(
        private readonly string $projectDir,
    ) {}

    /**
     * @param list<array{type: string, name: string, label?: string}> $editables
     *
     * @return array{brickId: string, className: string, files: list<string>}
     *
     * @throws \InvalidArgumentException
     */
    public function generate(string $name, ?string $description, array $editables, bool $force): array
    {
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid brick class name "%s". Use PascalCase, e.g. "TeaserRow".', $name),
            );
        }

        $brickId = $this->brickId($name);
        $classPath = $this->projectDir . '/src/Document/Areabrick/' . $name . '.php';
        $templateDir = $this->projectDir . '/templates/areas/' . $brickId;
        $templatePath = $templateDir . '/view.html.twig';

        foreach ([$classPath, $templatePath] as $path) {
            if (!$force && file_exists($path)) {
                throw new \InvalidArgumentException(
                    \sprintf('%s already exists. Pass force=true to overwrite.', $this->relative($path)),
                );
            }
        }

        $normalizedEditables = $this->normalizeEditables($editables);

        $this->write($classPath, $this->classContent($name, $description ?? ''));
        if (!is_dir($templateDir) && !@mkdir($templateDir, 0o775, true) && !is_dir($templateDir)) {
            throw new \RuntimeException(\sprintf('Could not create template directory %s.', $this->relative($templateDir)));
        }
        $this->write($templatePath, $this->templateContent($name, $normalizedEditables));

        return [
            'brickId' => $brickId,
            'className' => 'App\\Document\\Areabrick\\' . $name,
            'files' => [$this->relative($classPath), $this->relative($templatePath)],
        ];
    }

    private function brickId(string $className): string
    {
        $inflector = InflectorFactory::create()->build();

        return str_replace('_', '-', $inflector->tableize($className));
    }

    private function classContent(string $name, string $description): string
    {
        $humanName = $this->humanize($name);

        // Prefer the project's local base class (matches existing bricks);
        // fall back to the framework base if it isn't present.
        if (class_exists(self::LOCAL_ABSTRACT)) {
            $use = '';
            $extends = 'AbstractAreabrick';
        } else {
            $use = "\nuse " . self::VENDOR_ABSTRACT . ";\n";
            $extends = 'AbstractTemplateAreabrick';
        }

        $descriptionLiteral = var_export($description, true);
        $nameLiteral = var_export($humanName, true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace App\\Document\\Areabrick;
            {$use}
            class {$name} extends {$extends}
            {
                public function getName(): string
                {
                    return {$nameLiteral};
                }

                public function getDescription(): string
                {
                    return {$descriptionLiteral};
                }
            }

            PHP;
    }

    /**
     * @param list<array{type: string, name: string, label: ?string}> $editables
     */
    private function templateContent(string $name, array $editables): string
    {
        $humanName = $this->humanize($name);
        $brickId = $this->brickId($name);

        $body = '';
        foreach ($editables as $editable) {
            $label = $editable['label'] !== null ? " {# {$editable['label']} #}" : '';
            $body .= "        <div class=\"{$brickId}__{$editable['name']}\">{{ pimcore_{$editable['type']}('{$editable['name']}') }}</div>{$label}\n";
        }
        if ($body === '') {
            $body = "        {# Add editables, e.g. {{ pimcore_input('headline') }} #}\n"
                . "        {{ pimcore_wysiwyg('content') }}\n";
        }

        return <<<TWIG
            {# {$humanName} areabrick #}
            {% if editmode %}
                <div class="editmode-label">{$humanName}</div>
            {% endif %}

            <section class="{$brickId}">
            {$body}</section>

            TWIG;
    }

    /**
     * @param list<array{type: string, name: string, label?: string}> $editables
     *
     * @return list<array{type: string, name: string, label: ?string}>
     */
    private function normalizeEditables(array $editables): array
    {
        $normalized = [];
        foreach ($editables as $editable) {
            $type = (string) ($editable['type'] ?? '');
            $editableName = (string) ($editable['name'] ?? '');
            if (preg_match('/^[a-z][A-Za-z0-9]*$/', $type) !== 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid editable type "%s".', $type));
            }
            if (preg_match('/^[a-zA-Z][A-Za-z0-9_]*$/', $editableName) !== 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid editable name "%s".', $editableName));
            }
            $normalized[] = [
                'type' => $type,
                'name' => $editableName,
                'label' => isset($editable['label']) ? (string) $editable['label'] : null,
            ];
        }

        return $normalized;
    }

    private function humanize(string $pascalCase): string
    {
        return trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $pascalCase));
    }

    private function write(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException(\sprintf('Could not write %s.', $this->relative($path)));
        }
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, $this->projectDir . '/')
            ? substr($path, \strlen($this->projectDir) + 1)
            : $path;
    }
}
