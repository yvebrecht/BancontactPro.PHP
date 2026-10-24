<?php

declare(strict_types=1);

namespace BancontactPro\Tests;

use BancontactPro\Enums\Environment as E;
use BancontactPro\Enums\QrCodeImageFormat as F;
use BancontactPro\Enums\QrCodeImageSize as S;
use BancontactPro\Qr\QrLinks;
use PHPUnit\Framework\TestCase;

/** Worked examples published verbatim on https://docs.bancontactpro.com/guides/general/payloadurlupdate */
final class QrLinksTest extends TestCase
{
    private const PPID = '5c18cbd1296e9a26d3278518';

    public function testFixedAmountPayloadMatchesPublishedExample(): void
    {
        $url = QrLinks::fixedAmountPaymentUrl(E::Production, self::PPID, 1000, 'Receipt Payment', 'sd89sd91?sd9');

        self::assertSame('https://pay.bancontact.net/t/1/' . self::PPID . '?D=Receipt%20Payment&A=1000&R=sd89sd91%3Fsd9', $url);
    }

    public function testQrImageUrlDoubleEncodesPayload(): void
    {
        $payload = QrLinks::fixedAmountPaymentUrl(E::Production, self::PPID, 1000, 'Receipt Payment', 'sd89sd91?sd9');

        self::assertSame(
            'https://qrcodegenerator.api.bancontact.net/qrcode?f=PNG&s=XL&c='
            . 'https%3A%2F%2Fpay.bancontact.net%2Ft%2F1%2F5c18cbd1296e9a26d3278518%3FD%3DReceipt%2520Payment'
            . '%26A%3D1000%26R%3Dsd89sd91%253Fsd9',
            QrLinks::qrCodeImageUrl(E::Production, $payload, F::Png, S::XL),
        );
    }

    public function testStaticQrMatchesPublishedExample(): void
    {
        $payload = QrLinks::staticQrLocationUrl(E::Production, '5bb37284e35e2b29e363df22', 'POS00001');

        self::assertSame('https://pay.bancontact.net/l/1/5bb37284e35e2b29e363df22/POS00001', $payload);
        self::assertStringEndsWith(
            'c=https%3A%2F%2Fpay.bancontact.net%2Fl%2F1%2F5bb37284e35e2b29e363df22%2FPOS00001',
            QrLinks::qrCodeImageUrl(E::Production, $payload, F::Png, S::XL),
        );
    }

    public function testSvgOmitsSizeAndNoSizeOmitsS(): void
    {
        self::assertStringNotContainsString('s=', QrLinks::qrCodeImageUrl(E::Production, 'https://x', F::Svg, S::L));
        self::assertStringNotContainsString('s=', QrLinks::qrCodeImageUrl(E::Production, 'https://x'));
    }

    public function testOptionalPartsAreOmitted(): void
    {
        self::assertSame('https://pay.bancontact.net/t/1/p', QrLinks::fixedAmountPaymentUrl(E::Production, 'p'));
    }

    public function testPreprodHosts(): void
    {
        self::assertStringStartsWith('https://pay.acc.bancontact.net/', QrLinks::staticQrLocationUrl(E::Preprod, 'p', 'POS1'));
        self::assertStringStartsWith('https://qrcodegenerator.api.preprod.bancontact.net/', QrLinks::qrCodeImageUrl(E::Preprod, 'https://x'));
    }
}
