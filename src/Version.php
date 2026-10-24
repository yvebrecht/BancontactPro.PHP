<?php

declare(strict_types=1);

namespace BancontactPro;

/**
 * Single source of the package version, in the same SemVer format as BancontactPro.Net (MAJOR.MINOR.PATCH-preview.N).
 *
 * MAJOR.MINOR is kept equal across all language editions; PATCH and the preview number are free per language.
 * The git tag keeps this spelling with a `v` prefix, v0.2.0-preview.1; composer.json deliberately has no version.
 */
final class Version
{
    public const VALUE = '0.2.0-preview.1';
}
