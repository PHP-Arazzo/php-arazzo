<?php

declare(strict_types=1);

use Alama\Arazzo\Sources\Exceptions\InvalidFetchPolicyException;
use Alama\Arazzo\Sources\Exceptions\InvalidOpenApiSourceException;
use Alama\Arazzo\Sources\Exceptions\InvalidSourceReferenceException;
use Alama\Arazzo\Sources\Exceptions\SourceFetchException;
use Alama\Arazzo\Sources\Exceptions\SourceParseException;
use Alama\Arazzo\Sources\Exceptions\SourceResolutionException;
use Alama\Arazzo\Sources\Exceptions\UnresolvableReferenceException;

test('sources domain exception factories construct typed exceptions', function (): void {
    $cause = new RuntimeException('network error');

    expect(SourceFetchException::schemeDisallowed('ftp')->getMessage())->toContain('ftp')
        ->and(SourceFetchException::resolutionFailed('api', $cause)->getPrevious())->toBe($cause)
        ->and(SourceFetchException::fileReadFailed('/missing.yaml')->getMessage())->toContain('/missing.yaml')
        ->and(SourceFetchException::httpConnectionFailed('https://example.test', $cause)->getPrevious())->toBe($cause)
        ->and(SourceFetchException::httpErrorStatus('https://example.test', 503)->getMessage())->toContain('503')
        ->and(SourceFetchException::responseTooLarge('https://example.test', 1024)->getMessage())->toContain('1024')
        ->and(SourceFetchException::relativeUrlRequiresHttpBase('spec.yaml')->getMessage())->toContain('spec.yaml')
        ->and(SourceFetchException::noFetcherForScheme('s3')->getMessage())->toContain('s3')
        ->and(SourceParseException::parseFailed('api', $cause)->getPrevious())->toBe($cause)
        ->and(SourceParseException::rootMustBeArray('api')->getMessage())->toContain('array')
        ->and(UnresolvableReferenceException::circularReference('api')->sourceName)->toBe('api')
        ->and(InvalidSourceReferenceException::missingOperationTarget('s1'))->toBeInstanceOf(SourceResolutionException::class)
        ->and(InvalidSourceReferenceException::invalidOperationPath('s1')->getMessage())->toContain('operationPath')
        ->and(InvalidSourceReferenceException::invalidOperationId('s1')->getMessage())->toContain('operationId')
        ->and(InvalidSourceReferenceException::ambiguousPlainOperationId('getPet', 's1')->getMessage())->toContain('getPet')
        ->and(InvalidOpenApiSourceException::invalidJson())->toBeInstanceOf(InvalidArgumentException::class)
        ->and(InvalidOpenApiSourceException::unsupportedVersion()->getMessage())->toContain('version')
        ->and(InvalidOpenApiSourceException::pathNotFound('/pets')->getMessage())->toContain('/pets')
        ->and(InvalidOpenApiSourceException::methodNotFound('post', '/pets')->getMessage())->toContain('post')
        ->and(InvalidFetchPolicyException::nonPositiveConnectTimeout())->toBeInstanceOf(InvalidArgumentException::class)
        ->and(InvalidFetchPolicyException::nonPositiveTimeout()->getMessage())->toContain('timeout')
        ->and(InvalidFetchPolicyException::nonPositiveMaxBytes()->getMessage())->toContain('maxBytes');
});
