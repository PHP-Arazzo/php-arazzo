<?php

declare(strict_types=1);

namespace Alama\Arazzo\Cli\Console\Config;

use Alama\Arazzo\Contracts\Validation\Severity;
use Alama\Arazzo\Contracts\Validation\ValidatorPolicy;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class ValidateConfigLoader
{
    private const string PHP_CONFIG = 'arazzo.validate.php';

    private const string JSON_CONFIG = 'arazzo.json';

    public function load(?string $explicitPath = null, ?string $workingDirectory = null): ValidatorPolicy
    {
        if ($explicitPath !== null) {
            return $this->loadFromFile($explicitPath);
        }

        $dir = $workingDirectory ?? (string) getcwd();

        $phpPath = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.self::PHP_CONFIG;
        if (is_file($phpPath)) {
            return $this->loadFromFile($phpPath);
        }

        $jsonPath = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.self::JSON_CONFIG;
        if (is_file($jsonPath)) {
            return $this->loadFromFile($jsonPath);
        }

        return ValidatorPolicy::default();
    }

    private function loadFromFile(string $path): ValidatorPolicy
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Configuration file "%s" not found.', $path));
        }

        if (str_ends_with($path, '.php')) {
            $result = require $path;
            if ($result instanceof ValidatorPolicy) {
                return $result;
            }

            if (is_array($result)) {
                return $this->buildFromArray($result);
            }

            throw new UnexpectedValueException(sprintf('Configuration file "%s" must return a ValidatorPolicy instance or an array.', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new UnexpectedValueException(sprintf('Unable to read configuration file "%s".', $path));
        }

        /** @var mixed $data */
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new UnexpectedValueException(sprintf('Configuration file "%s" contains invalid JSON.', $path));
        }

        /** @var array<string, mixed> $section */
        $section = isset($data['validator']) && is_array($data['validator']) ? $data['validator'] : $data;

        return $this->buildFromArray($section);
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public function buildFromArray(array $data): ValidatorPolicy
    {
        $policy = ValidatorPolicy::default();
        $policy = $this->applySeverityOverrides($policy, $data['severity'] ?? null);
        $policy = $this->applyDisabledRules($policy, $data['disabled'] ?? null);

        if (isset($data['strict']) && is_bool($data['strict'])) {
            $policy = $policy->withStrict($data['strict']);
        }

        return $policy;
    }

    private function applySeverityOverrides(ValidatorPolicy $policy, mixed $severities): ValidatorPolicy
    {
        if (!is_array($severities)) {
            return $policy;
        }

        foreach ($severities as $code => $level) {
            if (is_string($code) && is_string($level)) {
                $severity = $this->parseSeverityLevel($level);
                if ($severity !== null) {
                    $policy = $policy->withSeverity($code, $severity);
                }
            }
        }

        return $policy;
    }

    private function applyDisabledRules(ValidatorPolicy $policy, mixed $disabled): ValidatorPolicy
    {
        if (!is_array($disabled)) {
            return $policy;
        }

        /** @var list<string> $ruleCodes */
        $ruleCodes = array_values(array_filter($disabled, is_string(...)));

        return $policy->withDisabled(...$ruleCodes);
    }

    public function parseSeverityLevel(string $level): ?Severity
    {
        return match (strtolower(trim($level))) {
            'warn', 'warning' => Severity::Warning,
            'err', 'error' => Severity::Error,
            default => null,
        };
    }
}
