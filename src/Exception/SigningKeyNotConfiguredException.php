<?php

declare(strict_types=1);

namespace BancontactPro\Exception;

use LogicException;

final class SigningKeyNotConfiguredException extends LogicException implements BancontactException
{
    public function __construct(string $detail = '')
    {
        parent::__construct(
            $detail !== '' ? $detail
            : 'The Refund and Reconciliation APIs require a signed request. Configure a signing key (kid and '
            . 'privateKeyPem) and host the public key as a JWKS whose URL is shared with Bancontact. '
            . "The Payment API works with a product's API key alone.",
        );
    }
}
