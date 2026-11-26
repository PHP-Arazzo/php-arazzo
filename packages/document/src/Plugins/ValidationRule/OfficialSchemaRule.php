<?php

declare(strict_types=1);

namespace Alama\Arazzo\Document\Plugins\ValidationRule;

use Alama\Arazzo\Contracts\Attributes\AsValidationRulePlugin;
use Alama\Arazzo\Contracts\Interfaces\ValidationRuleInterface;
use Alama\Arazzo\Contracts\Spec\ArazzoDocument;
use Alama\Arazzo\Contracts\Validation\ErrorCollector;
use Alama\Arazzo\Contracts\Validation\SymbolTable;
use Alama\Arazzo\Contracts\Validation\ValidationPhase;
use Alama\Arazzo\Document\Validator\Schema\DefectRepairs;
use Alama\Arazzo\Document\Validator\Schema\RefHoister;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Exception\ValidationException;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator as JsonSchemaValidator;

/**
 * Structural validation of the raw document against the official Arazzo
 * JSON Schemas (draft 2020-12), layered *before* the semantic rules.
 *
 * Known limitation: workflow `inputs` schemas $ref the full 2020-12
 * metaschema over the network; structural checks for those subtrees are
 * skipped offline (the semantic rules still cover their shape).
 *
 * @internal stays out of the advertised contract; not part of the public API surface
 */
#[AsValidationRulePlugin(ValidationPhase::Schema)]
final class OfficialSchemaRule implements ValidationRuleInterface
{
    private const SCHEMA_FILES = [
        '1.0' => __DIR__.'/../../../resources/schemas/arazzo-1.0.schema.json',
        '1.1' => __DIR__.'/../../../resources/schemas/arazzo-1.1.schema.json',
        '1.2' => __DIR__.'/../../../resources/schemas/arazzo-1.2.schema.json',
    ];

    public function __construct() {}

    public function check(ArazzoDocument $doc, SymbolTable $symbols, ErrorCollector $errors): void
    {
        $raw = $doc->rawRoot;
        if (!is_array($raw)) {
            return; // parser-built documents (tests) carry no raw payload
        }

        $schema = self::loadSchema($doc->arazzo, $symbols->conformance);

        if ($schema !== null) {
            $data = json_decode(json_encode($raw) ?: '{}');
            if (!is_object($data) && !is_array($data)) {
                $errors->error('schema.invalid', 'Raw document is not a JSON object.', '/');

                return;
            }

            $validator = new JsonSchemaValidator();

            try {
                $validator->validate(
                    $data,
                    $schema,
                    Constraint::CHECK_MODE_APPLY_DEFAULTS | Constraint::CHECK_MODE_DISABLE_FORMAT,
                );
            } catch (ValidationException) {
                self::applyProseOverrides($raw, $doc->arazzo, $errors);

                return; // unsupported schema construct - defer to semantic rules
            }

            if (!$validator->isValid()) {
                self::reportErrors($validator, $errors);
            }
        }

        self::applyProseOverrides($raw, $doc->arazzo, $errors);
    }

    /**
     * Loads and prepares the official schema for the document's minor version,
     * returning null when the schema cannot be used offline.
     */
    private static function loadSchema(string $arazzo, bool $conformance = false): ?object
    {
        $minor = match (true) {
            str_starts_with($arazzo, '1.0') => '1.0',
            str_starts_with($arazzo, '1.2') => '1.2',
            default => '1.1',
        };
        $schemaFile = self::SCHEMA_FILES[$minor];
        if (!is_file($schemaFile)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($schemaFile), true);
        if (!is_array($decoded) || !is_string($decoded['$id'] ?? null)) {
            return null;
        }

        $schemaId = $decoded['$id'];

        $defs = $decoded['$defs'] ?? null;
        if (!is_array($defs) || !is_array($defs['schema'] ?? null)) {
            return null;
        }

        // Neutralize remote refs into the 2020-12 metaschema (workflow inputs)
        // so validation works fully offline.
        $defs['schema'] = ['type' => 'object'];
        $decoded['$defs'] = $defs;

        // Repair defects in the vendored schema where it disagrees with the
        // spec prose. Each repair names the section it enforces.
        $decoded = DefectRepairs::apply($decoded, $minor, $conformance);

        // The official schemas are written for draft 2020-12, where $ref may
        // appear alongside sibling keywords. Justinrainbow treats a $ref as
        // exclusive (draft-7 style), which silently skips "required" checks.
        // Hoist every sibling $ref into allOf to restore correct semantics.
        $decoded = RefHoister::hoist($decoded);

        $schemaData = json_decode((string) json_encode($decoded));
        if (!$schemaData instanceof \stdClass) {
            return null;
        }

        $storage = new SchemaStorage();
        $storage->addSchema($schemaId, $schemaData);

        $resolved = $storage->getSchema($schemaId);

        return is_object($resolved) ? $resolved : null;
    }

    private static function reportErrors(JsonSchemaValidator $validator, ErrorCollector $errors): void
    {
        /** @var array{property: string, message: string, pointer: string} $error */
        foreach (array_slice($validator->getErrors(), 0, 25) as $error) {
            $errors->error(
                'schema.invalid',
                sprintf('[%s] %s', $error['property'] !== '' ? $error['property'] : '(root)', $error['message']),
                '/'.$error['pointer'],
            );
        }
    }

    /**
     * Applies spec prose where it is stricter than the official schema.
     *
     * - Arazzo 1.0.1 types a Reusable Object `value` as a string; the vendored
     *   1.0 schema also accepts booleans, numbers, arrays, objects, and null.
     *   The 1.1 typing is contested by the conformance suite and left unscored.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function applyProseOverrides(array $raw, string $arazzo, ErrorCollector $errors): void
    {
        if (!str_starts_with($arazzo, '1.0')) {
            return;
        }

        self::walkRaw($raw, '', static function (array $node, string $path) use ($errors): void {
            self::checkReusableStringValue($node, $path, $errors);
        });
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function checkReusableStringValue(array $node, string $path, ErrorCollector $errors): void
    {
        if (!array_key_exists('reference', $node) || !array_key_exists('value', $node)) {
            return;
        }

        if (is_string($node['value'])) {
            return;
        }

        $errors->error(
            'schema.invalid',
            'Reusable Object value MUST be a string (Arazzo 1.0.1 Reusable Object).',
            $path.'/value',
        );
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  callable(array<array-key, mixed>, string): void  $visit
     */
    private static function walkRaw(array $node, string $path, callable $visit): void
    {
        $visit($node, $path);

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $segment = str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            self::walkRaw($value, $path.'/'.$segment, $visit);
        }
    }

    public function code(): string
    {
        return 'schema.invalid';
    }
}
