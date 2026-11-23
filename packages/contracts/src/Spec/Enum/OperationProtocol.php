<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Spec\Enum;

/**
 * Protocol category implemented by an OperationExecutorPluginInterface.
 *
 * Case declaration order defines canonical dispatch precedence when multiple
 * protocol executors are registered (specific protocols before HTTP fallback).
 */
enum OperationProtocol: string
{
    case SubWorkflow = 'sub-workflow';
    case Soap = 'soap';
    case Rpc = 'rpc';
    case AsyncApi = 'asyncapi';
    case Http = 'http';
}
