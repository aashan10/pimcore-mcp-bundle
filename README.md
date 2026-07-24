# Pimcore MCP Bundle

An [MCP](https://modelcontextprotocol.io) server for Pimcore that exposes the
**DataObject schema** (classes, field collections, object bricks) to coding
agents. It is built for *assisting development*: every tool returns the
**essential** information only, and offers an explicit way to expand any single
field into its complete configuration on demand.

Schema is read directly from the generated definition files, so **no database
connection is required**.

## Why not `symfony/mcp-bundle`?

`symfony/mcp-bundle` requires Symfony 7, but Pimcore 11 pins Symfony 6.4. This
bundle therefore depends on the underlying SDK, [`mcp/sdk`](https://packagist.org/packages/mcp/sdk)
(`^0.7`, compatible with Symfony `^6.4`), directly and provides its own thin
wiring: a console command that runs the stdio server plus service registration.

## Installation

```bash
composer require aashan/pimcore-mcp-bundle
```

Register the bundle in `config/bundles.php`:

```php
Aashan\PimcoreMcpBundle\PimcoreMcpBundle::class => ['all' => true],
```

## Running

The server speaks JSON-RPC 2.0 over stdio:

```bash
bin/console pimcore:mcp:serve
```

Point an MCP client at that command. Example (Claude Code / Claude Desktop),
running inside the Pimcore container:

```json
{
  "mcpServers": {
    "pimcore": {
      "command": "docker",
      "args": ["compose", "exec", "-T", "php", "php", "bin/console", "pimcore:mcp:serve"]
    }
  }
}
```

> stdout is the JSON-RPC channel — the command writes nothing else to it.

## Tools

| Tool | Purpose |
| --- | --- |
| `list_data_object_classes` | Index of all DataObject classes (name, title, group, description). |
| `get_data_object_class` | Essential field & layout tree of one class. |
| `describe_field` | Complete, verbatim configuration of one class field. |
| `list_field_collections` | Index of all field collections. |
| `get_field_collection` | Essential field & layout tree of one field collection. |
| `list_object_bricks` | Index of all object bricks, incl. the classes/fields they attach to. |
| `get_object_brick` | Essential field & layout tree of one object brick. |
| `describe_container_field` | Complete configuration of one field on a collection/brick. |
| `get_data_object_methods` | Public PHP method signatures (typed getters/setters) of a generated DataObject class. |
| `get_object_brick_methods` | Public PHP method signatures of a generated object brick data class. |
| `get_field_collection_methods` | Public PHP method signatures of a generated field collection data class. |
| `list_website_settings` | List website settings (filter by name, site id, language). |
| `get_website_setting` | Get one website setting by id, or by name (+ site/language). |
| `create_website_setting` | Create a website setting. |
| `update_website_setting` | Update a website setting by id (only provided fields change). |
| `delete_website_setting` | Delete a website setting by id. |
| `create_definition` | Create a class / field collection / object brick (optionally with fields). |
| `add_field` | Add a field to a class / field collection / object brick definition. |
| `update_field` | Update an existing field's configuration. |
| `remove_field` | Remove a field (drops its DB column). |
| `export_class` / `import_class` | Export a class definition as JSON / create-or-overwrite from JSON. |
| `list_children` | List the children of a document/asset/data-object tree node. |
| `get_element` | Inspect one document/asset/data object (metadata + content). |
| `list_document_types` / `create_document_type` / `update_document_type` / `delete_document_type` | Manage predefined document types. |
| `list_predefined_properties` / `create_predefined_property` / `update_predefined_property` / `delete_predefined_property` | Manage predefined properties. |
| `list_log_files` | List the Monolog log files in the log directory. |
| `list_log_channels` | List the Monolog channels present in the log files. |
| `get_log_entries` | Return the last N log entries, filtered by channel / minimum level. |
| `system_info` | Pimcore/PHP versions, environment, languages, sites, bundles, maintenance mode. |
| `list_container_services` | List Symfony container services, filtered by tag, class (substring / namespace prefix), id and visibility. |
| `list_service_tags` | Index of every service tag with an occurrence count each. |
| `describe_service` | Full definition of one service/alias: class, flags, factory, tags (with attributes), method calls, aliases. |
| `list_translations` / `set_translation` / `delete_translation` | Manage shared/admin translations. |
| `generate_areabrick` | Scaffold a document areabrick (PHP class + Twig template). |
| `find_objects` | Find data objects by class + field conditions (with previews, sorting, paging). |
| `find_elements` | Find documents/assets by path, type and name. |
| `run_maintenance` | Run a safe allowlisted maintenance command (cache clear, classes rebuild, reindex). |
| `list_documentation_topics` | Browse the official docs.pimcore.com navigation for the running product version. |
| `get_documentation_page` | Fetch one documentation page as markdown. |

### Essential vs. expanded

`get_*` tools return a compact tree. Each node carries:

- `kind` — `data` (a value field / getter-setter) or `layout` (a structural container);
- `fieldtype` — the Pimcore type (`input`, `manyToOneRelation`, `localizedfields`, `panel`, …);
- `title`;
- `attributes` — only the highlights that affect usage: `mandatory`, `allowedClasses`
  (relations), `options` / `optionsProvider` (selects), `allowedTypes` (bricks/collections),
  `maxLength`, `unique`, `readOnly`, `invisible`;
- `children` — nested nodes (layout children, or the sub-fields of `localizedfields` / `block`).

Every `get_*` schema response includes a `_note` telling the agent to call
`describe_field` / `describe_container_field` with a field name to retrieve the
field's full configuration (validators, defaults, column types, widths,
tooltips, permissions, …).

### PHP API (method signatures)

The `*_methods` tools reflect the **generated** model classes
(`Pimcore\Model\DataObject\<Class>`, `…\Objectbrick\Data\<Key>`,
`…\Fieldcollection\Data\<Key>`) and return their public method signatures — the
typed getters/setters an agent needs to write correct code, e.g.
`setName(?string $name, ?string $language = null): static` or
`getPower(): ?\Pimcore\Model\DataObject\Data\QuantityValue`. Each accessor is
tagged with the `field` it maps to.

Only schema-specific methods are listed; standard model methods (`save`,
`getById`, `getList`, …) are inherited from the class reported in `extends`.
These tools read compiled PHP classes, so they require the class files to exist
(`bin/console pimcore:deployment:classes-rebuild` regenerates them).

### Website settings (CRUD)

The `*_website_setting(s)` tools read and write the `website_settings` table
via Pimcore's `WebsiteSetting` model — these are **live database
operations** and require a configured DB connection.

`type` is one of `text`, `document`, `asset`, `object`, `bool`. `data` is
exchanged as a string and coerced to the type: `bool` accepts
`true/false/1/0`; `document`/`asset`/`object` take the referenced element id
(element-typed values are returned as a `{elementType, id, path}` reference).
`update_website_setting` only changes the fields you pass; `delete_website_setting`
permanently removes the row.

### Adding fields (schema mutation)

`add_field` appends a field to a class, field collection or object brick
definition and persists it using Pimcore's own layout-tree builder
(`ClassDefinition\Service::generateLayoutTreeFromArray`). **Saving regenerates
the PHP model classes and alters the database tables**, so it requires a DB and
a writable class-definition directory.

Arguments: `scope` (`class` | `fieldcollection` | `objectbrick`), `container`
(class name or collection/brick key), `name`, `fieldtype` (`input`, `numeric`,
`select`, `manyToOneRelation`, …), optional `title`, `mandatory`, `parent`
(name of a layout element / container field such as `localizedfields` to nest
under — defaults to the root), and `config` — a JSON object of field-type
specific settings merged into the definition, e.g.:

- input: `{"maxLength": 50}`
- select: `{"options": [{"key": "A", "value": "a"}]}`
- manyToOneRelation: `{"classes": [{"classes": "Manufacturer"}]}`

The tool returns the updated essential schema. Note: the new typed
getter/setter reported by the `*_methods` tools only appears on the next server
start, because the PHP class is already loaded in the running process.

> Re-saving a definition rewrites its definition file in the current Pimcore
> export format; a file generated by an older version will show reordered
> properties and normalised defaults. This is cosmetic — the schema is unchanged.

The full authoring set:

- `create_definition(scope, name, fields?, options?)` — create a `class`,
  `fieldcollection` or `objectbrick`. `fields` is a JSON array of field specs
  (same shape as `add_field`); `options` is a JSON object (class:
  `description`/`group`/`parentClass`/`allowInherit`/`allowVariants`;
  collection/brick: `title`/`group`; brick also `classDefinitions`).
- `update_field(scope, container, field, config)` — change a field's config
  (title, mandatory, validators, options, …); name/type are immutable.
- `remove_field(scope, container, field)` — remove a field; drops its DB column.
- `export_class(className)` → JSON, and `import_class(className, json, overwrite?)`
  — clone a class or restore it from an exported definition (Pimcore's native
  JSON), useful for scaffolding from a spec or moving a class between projects.

After these, run `run_maintenance` (`rebuild_classes` / `clear_cache`) so the
generated PHP model classes and DB tables are fully in sync.

### Inspecting content (documents, assets, data objects)

`list_children` and `get_element` browse and inspect the actual content trees.
Both take an `elementType` of `document`, `asset` or `object` and address a
node by `id` or `path` (`list_children` defaults to the root `/`).

- `list_children` returns lightweight summaries (`id`, `type`, `key`, `path`,
  `published`, `hasChildren`) plus `total`, for top-down tree navigation and
  pagination (`limit`/`offset`, `includeUnpublished`).
- `get_element` returns full detail: common metadata + properties, and
  type-specific content — data object **field values** (including object
  bricks, field collections and per-language localized fields), document
  **editables** (controller/template/title), or asset **metadata**
  (mime type, size, image dimensions).

Output is bounded: referenced elements become `{type, id, path}` references
(never expanded inline), nested structures stop at a fixed depth, lists are
capped, and long strings (e.g. WYSIWYG) are truncated.

### Predefined admin config (CRUD)

Full CRUD over Pimcore's predefined configuration (stored via the
location-aware config / settings store):

- **Document types** — `list/create/update/delete_document_type`. A document
  type presets the `controller`/`template`/`group` for a document kind
  (`type`: `page`, `snippet`, `email`, `link`, …). Ids are auto-generated UUIDs.
- **Predefined properties** — `list/create/update/delete_predefined_property`.
  `type` is one of `text`, `document`, `asset`, `object`, `bool`, `select`;
  `ctype` (the element it applies to) is `document`, `asset` or `object`;
  plus optional `data` (default value), `config`, `description`, `inheritable`.

`update_*` changes only the fields you pass; `create_*`/`update_*` return the
full record.

### Logs

`list_log_files`, `list_log_channels` and `get_log_entries` read the Monolog
log files in the configured log directory (`%kernel.logs_dir%`, e.g. `var/log`).

`get_log_entries` returns the last `lines` entries (default 100, max 1000) and
respects the Monolog channels written into each record
(`[time] channel.LEVEL: message`):

- `channel` — filter to one channel (e.g. `console`, `request`, `admin_statistics`);
- `level` — minimum severity (`DEBUG` … `EMERGENCY`), so `ERROR` also returns `CRITICAL`+;
- `file` — restrict to one log file; omitted, it searches all files and merges by time;
- `scanKb` — how much of each file's *tail* to scan (default 2048).

Only the tail of each file is read, so this is safe on multi-hundred-MB logs.
Use `list_log_channels` to discover which channels actually appear in the logs.

### Service container introspection

`list_container_services`, `list_service_tags` and `describe_service` expose the
Symfony service container so an agent can discover extension points (event
subscribers, Twig extensions, message handlers, …) and see how a service is
wired.

Service **tags**, and the **classes of private services**, are compile-time
metadata that the live runtime container has already stripped. These tools
therefore read the **debug container XML dump** — the same source
`bin/console debug:container` uses (`%debug.container.dump%`), loaded into a
standalone `ContainerBuilder`. No service is instantiated and no database is
touched. The dump only exists when the kernel runs in **debug mode**
(`APP_ENV=dev` / `APP_DEBUG=1`); otherwise the tools return an `error` saying so.

- `list_container_services(tag?, class?, idLike?, public?, limit?, offset?)` —
  filter by `tag` (exact tag name), `class` (case-insensitive substring, so a
  namespace prefix like `Pimcore\Bundle` scopes by vendor/bundle), `idLike`
  (substring on the service id) and `public` (`true`/`false` visibility).
  Filters combine (AND). Each entry is compact: `id`, `class`, `public` and its
  tag names.
- `list_service_tags()` — every tag name in the container with a count, for
  discovering which tags to filter by (mirrors `list_log_channels`).
- `describe_service(id)` — the full definition of one service or alias: class,
  `public`/`shared`/`abstract`/`lazy`/`synthetic`/`deprecated`/`autowired`
  flags, `factory`, `tags` (with their attributes), `methodCalls` and `aliases`.

### System info & translations

- `system_info` returns Pimcore & PHP versions, environment/debug, valid
  languages, configured sites, enabled bundles and maintenance mode — a quick
  way for an agent to orient itself.
- `list_translations` / `set_translation` / `delete_translation` manage
  translations in the `messages` (shared) or `admin` domain. `set_translation`
  is an upsert: pass `translations` as a JSON object of `language => text`;
  provided languages merge into any existing entry.

### Scaffolding areabricks

`generate_areabrick` creates a document areabrick following this project's
convention: the PHP class in `src/Document/Areabrick/<Name>.php` (extending the
project's `AbstractAreabrick` when present, else the framework
`AbstractTemplateAreabrick`) and its Twig view in
`templates/areas/<brick-id>/view.html.twig`. The brick id is the kebab-case of
the class name (matching Pimcore's `AreabrickPass`), so the template is
auto-discovered, and the brick auto-registers via container autoconfiguration.

Arguments: `name` (PascalCase), optional `description`, optional `editables`
(JSON array of `{type, name, label?}` to prefill the template with
`pimcore_<type>('<name>')` tags), and `force` to overwrite. Run
`run_maintenance` with `clear_cache` to see a new brick in the admin.

### Finding content

- `find_objects` locates data objects of a class by **validated** field
  conditions. `filters` is a JSON array of `{field, operator, value}`; operators
  are `=, !=, >, >=, <, <=, LIKE, NOT LIKE, IN, NOT IN, IS NULL, IS NOT NULL`;
  fields must be class fields or system fields (`id`, `key`, `published`,
  `path`, …), so conditions are built only from known identifiers with bound
  parameters (no arbitrary SQL). Supports `pathPrefix`, `orderBy`/`orderDir`,
  paging, and an optional `fields` list to include value previews per result.
- `find_elements` locates documents/assets by `pathPrefix`, node `type` and
  `nameLike`. Both return lightweight summaries; use `get_element` for detail.

### Maintenance (closing the loop)

`run_maintenance` runs a **fixed allowlist** of Pimcore commands as isolated
subprocesses — no user-supplied command ever reaches the shell:

| action | command |
| --- | --- |
| `clear_cache` | `pimcore:cache:clear` |
| `warmup_cache` | `cache:warmup` |
| `rebuild_classes` | `pimcore:deployment:classes-rebuild` |
| `reindex_search` | `pimcore:search-backend-reindex` |

Use it after `add_field`, `generate_areabrick` or content changes so they take
effect. Returns success, exit code and (tail-truncated) output.

### Official documentation

`list_documentation_topics` and `get_documentation_page` serve the official
Pimcore documentation from [docs.pimcore.com](https://docs.pimcore.com),
**matched to the running product version** — so an agent reads the docs that
correspond to the installed Pimcore, not a random version.

The docs are published per *platform version* under `/platform/<version>`
(e.g. `/platform/2024.4`). The version is resolved automatically:

1. an explicit `docsVersion` argument (`YYYY.N`), if given;
2. otherwise `Pimcore\Version::getPlatformVersion()` (the
   `pimcore/platform-version` package), reduced to `YYYY.N`;
3. otherwise, for Pimcore ≥ 11, the newest published version (never older than
   `2023.3`, the first platform-versioned docs for Pimcore 11).

Pimcore < 11 is reported as unsupported. Every response reports the resolved
`docsVersion`, how it was chosen (`versionSource`), and the `availableVersions`
so the agent can pin a different one.

- `list_documentation_topics(section?, docsVersion?)` reads the sidebar
  navigation (the page's `<aside>`) and returns the topic tree (`title` +
  version-relative `path`). The docs site only expands the subtree along the
  requested path, so pass `section` (e.g. `Pimcore` or `Pimcore/Objects`) to
  drill in and reveal a topic's child pages.
- `get_documentation_page(path, docsVersion?)` fetches one page (by the `path`
  from the navigation, e.g. `Pimcore/Objects/Object_Classes`) and returns its
  body converted to markdown. Output is capped; `truncated` flags when the
  page was clipped and `url` links to the full page.

These tools make outbound HTTP to docs.pimcore.com (no DB required).

## Architecture

```
src/
  PimcoreMcpBundle.php            Bundle entry point
  DependencyInjection/            Loads config/services.yaml
  Command/McpServerCommand.php    `pimcore:mcp:serve` — builds & runs the stdio server
  Tool/                           MCP tools (#[McpTool] methods)
  Documentation/DocumentationFetcher  Fetches & parses docs.pimcore.com (version-aware)
  Container/ServiceCatalog        DB-free introspection of the debug service-container dump
  Repository/                     DB-free access to Pimcore definitions
  Extractor/DefinitionExtractor   Pimcore definitions -> compact DTOs
  Entity/Definitions/             The DTOs (ClassDefinition, ContainerDefinition, FieldDefinition)
```

Tools are discovered from their `#[McpTool]` attributes and resolved through a
PSR-11 service locator, so they are ordinary autowired Symfony services.
