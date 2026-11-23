<?php

declare(strict_types=1);

namespace Alama\Arazzo\Contracts\Policy;

/**
 * Supported authentication credential kinds for a {@see SourcePolicy}.
 */
enum CredentialType: string
{
    case ApiKey = 'api_key';
    case Bearer = 'bearer';
    case Basic = 'basic';
    case OAuth2 = 'oauth2';
}
