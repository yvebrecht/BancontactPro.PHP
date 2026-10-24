<?php

declare(strict_types=1);

namespace BancontactPro;

use BancontactPro\Enums\Environment;

final class Endpoints
{
    /** The base address of the Payment, Refund and Reconciliation APIs. */
    public static function merchantApiBaseUrl(Environment $environment): string
    {
        return match ($environment) {
            Environment::Preprod => 'https://merchant.api.preprod.bancontact.net/',
            Environment::Production => 'https://merchant.api.bancontact.net/',
        };
    }

    /** The address of Bancontact's JWKS (the public keys that sign its callbacks). */
    public static function jwksUrl(Environment $environment): string
    {
        return match ($environment) {
            Environment::Preprod => 'https://jwks.preprod.bancontact.net/',
            Environment::Production => 'https://jwks.bancontact.net/',
        };
    }
}
