<?php

declare(strict_types=1);

namespace BancontactPro\Qr;

use BancontactPro\Enums\Environment;
use BancontactPro\Enums\QrCodeImageFormat;
use BancontactPro\Enums\QrCodeImageSize;

/**
 * Client-templated QR/deeplink URLs (On a Receipt, Top Up, Static QR). No HTTP call is made.
 *
 * Two encoding passes matter: `D`/`A`/`R` are percent-encoded once into the payload URL, then the whole payload URL is
 * percent-encoded again as the `c=` parameter of the QR image URL. Both must use the same environment.
 * Worked examples: https://docs.bancontactpro.com/guides/general/payloadurlupdate
 */
final class QrLinks
{
    public static function fixedAmountPaymentUrl(
        Environment $environment,
        string $productProfileId,
        ?int $amountCents = null,
        ?string $description = null,
        ?string $reference = null,
    ): string {
        $query = [];
        if ($description !== null) {
            $query[] = 'D=' . rawurlencode($description);
        }
        if ($amountCents !== null) {
            $query[] = 'A=' . $amountCents;
        }
        if ($reference !== null) {
            $query[] = 'R=' . rawurlencode($reference);
        }
        $url = self::payHost($environment) . '/t/1/' . rawurlencode($productProfileId);

        return $query === [] ? $url : $url . '?' . implode('&', $query);
    }

    public static function staticQrLocationUrl(Environment $environment, string $productProfileId, string $posId): string
    {
        return self::payHost($environment) . '/l/1/' . rawurlencode($productProfileId) . '/' . rawurlencode($posId);
    }

    public static function qrCodeImageUrl(
        Environment $environment,
        string $payloadUrl,
        QrCodeImageFormat $format = QrCodeImageFormat::Png,
        ?QrCodeImageSize $size = null,
    ): string {
        $query = ['f=' . $format->value];
        if ($format === QrCodeImageFormat::Png && $size !== null) {
            $query[] = 's=' . $size->value;
        }
        $query[] = 'c=' . rawurlencode($payloadUrl);

        return self::qrHost($environment) . '/qrcode?' . implode('&', $query);
    }

    private static function payHost(Environment $environment): string
    {
        return match ($environment) {
            Environment::Production => 'https://pay.bancontact.net',
            Environment::Preprod => 'https://pay.acc.bancontact.net',
        };
    }

    private static function qrHost(Environment $environment): string
    {
        return match ($environment) {
            Environment::Production => 'https://qrcodegenerator.api.bancontact.net',
            Environment::Preprod => 'https://qrcodegenerator.api.preprod.bancontact.net',
        };
    }
}
