<?php

declare(strict_types=1);

namespace Alama\Arazzo\Engine\Execution;

use Alama\Arazzo\Contracts\Exceptions\AuthoringException;
use Alama\Arazzo\Contracts\Exceptions\CriteriaException;
use Alama\Arazzo\Contracts\Exceptions\ProtocolException;
use Alama\Arazzo\Contracts\Exceptions\SchemaValidationException;
use Alama\Arazzo\Contracts\Exceptions\TransportException;
use Alama\Arazzo\Contracts\Spec\StepExecutionOutcome;
use Throwable;

/**
 * Single source of truth for classifying runtime errors into canonical failure categories
 * (transport, protocol, criteria, schema, authoring, execution) and normalizing
 * raw client exceptions into typed TransportException instances.
 */
final class ErrorClassifier
{
    public const string CATEGORY_TRANSPORT = 'transport';

    public const string CATEGORY_PROTOCOL = 'protocol';

    public const string CATEGORY_CRITERIA = 'criteria';

    public const string CATEGORY_SCHEMA = 'schema';

    public const string CATEGORY_AUTHORING = 'authoring';

    public const string CATEGORY_EXECUTION = 'execution';

    /**
     * @var array<class-string<Throwable>, string>
     */
    private const array EXCEPTION_TYPE_MAP = [
        TransportException::class => self::CATEGORY_TRANSPORT,
        ProtocolException::class => self::CATEGORY_PROTOCOL,
        CriteriaException::class => self::CATEGORY_CRITERIA,
        SchemaValidationException::class => self::CATEGORY_SCHEMA,
        AuthoringException::class => self::CATEGORY_AUTHORING,
    ];

    /**
     * @var string[]
     */
    private const array TRANSPORT_CLASS_PATTERNS = [
        'ConnectException',
        'NetworkException',
        'RequestException',
        'ClientException',
    ];

    /**
     * @var string[]
     */
    private const array TRANSPORT_MESSAGE_PATTERNS = [
        'connection refused',
        'timed out',
        'timeout',
        'curl error',
        'resolve host',
        'name or service not known',
        'network',
        'ssl handshake',
    ];

    public function classify(StepExecutionOutcome|Throwable $target): string
    {
        if ($target instanceof StepExecutionOutcome) {
            if ($target->failureCategory !== null) {
                return $target->failureCategory;
            }

            if ($target->exception !== null) {
                return $this->classify($target->exception);
            }

            return self::CATEGORY_TRANSPORT;
        }

        foreach (self::EXCEPTION_TYPE_MAP as $class => $category) {
            if ($target instanceof $class) {
                return $category;
            }
        }

        if ($this->isTransportFailure($target)) {
            return self::CATEGORY_TRANSPORT;
        }

        return self::CATEGORY_EXECUTION;
    }

    public function toTransportException(
        StepExecutionOutcome|Throwable $target,
        string $targetUrl = '',
        string $requestMethod = '',
    ): ?TransportException {
        if ($target instanceof StepExecutionOutcome) {
            if ($target->exception === null) {
                return null;
            }

            return TransportException::fromThrowable(
                $target->exception,
                $target->targetUrl(),
                $target->requestMethod(),
            );
        }

        return TransportException::fromThrowable($target, $targetUrl, $requestMethod);
    }

    private function isTransportFailure(Throwable $exception): bool
    {
        if (method_exists($exception, 'getErrorCode') && $exception->getErrorCode() === 'transport.rate_limited') {
            return true;
        }

        if ($this->matchesPattern($exception::class, self::TRANSPORT_CLASS_PATTERNS)) {
            return true;
        }

        return $this->matchesPattern(strtolower($exception->getMessage()), self::TRANSPORT_MESSAGE_PATTERNS);
    }

    /**
     * @param  string[]  $patterns
     */
    private function matchesPattern(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($subject, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
