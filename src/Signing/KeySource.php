<?php

declare(strict_types=1);

namespace BancontactPro\Signing;

use OpenSSLAsymmetricKey;

/** Resolves a Bancontact public key by `kid`; returns null when it does not exist. */
interface KeySource
{
    public function getKey(string $kid): ?OpenSSLAsymmetricKey;
}
