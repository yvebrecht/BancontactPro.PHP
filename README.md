# BancontactPro.PHP

> **Dit is geen officieel project van Bancontact.** Deze bibliotheek is onafhankelijk ontwikkeld op basis van de openbare Bancontact Pro-documentatie en is niet gemaakt, goedgekeurd of ondersteund door Bancontact (of Payconiq). 'Bancontact' en 'Bancontact Pro' zijn merken van hun eigenaars en worden enkel beschrijvend gebruikt. Overzicht: [BancontactPro.SDK](https://github.com/yvebrecht/BancontactPro.SDK).

Een PHP-client voor de [Bancontact Pro](https://docs.bancontactpro.com/)-API's: Payment, Refund en Reconciliation, met request-signing, verificatie van callbacks via JWKS en QR-/deeplinkgeneratie.

> **Status: in ontwikkeling, nog niet gepubliceerd en nog niet getest tegen Bancontact zelf.** Alles is getest met gemockte HTTP. Het pakket staat nog niet op Packagist en de naam `dewall88/bancontactpro` is nog niet gereserveerd. De bibliotheek is gebouwd op de documentatie en specs van Bancontact, niet als port van een andere taalversie.

## Vereisten

PHP 8.2 of hoger met `ext-openssl` en `ext-json`. De HTTP-laag is een [PSR-18](https://www.php-fig.org/psr/psr-18/)-client: installeer er een (bijvoorbeeld Guzzle of Symfony HttpClient met `nyholm/psr7`) en de bibliotheek vindt die zelf via `php-http/discovery`, of geef er expliciet een mee. Optioneel: een [PSR-16](https://www.php-fig.org/psr/psr-16/)-cache voor de JWKS en een [PSR-3](https://www.php-fig.org/psr/psr-3/)-logger.

De API is synchroon. Stel de time-out in op de PSR-18-client zelf; ruim genoeg (rond de 100 seconden) voor `create`-aanroepen.

## Credentials

Bancontact geeft per producttype (On a Display, On a Receipt, Static QR, Top Up) een API-key en een PPID. Refund- en Reconciliation-aanvragen moeten bovendien ondertekend worden met jouw eigen ES256-sleutel (P-256); de publieke sleutel host je als JWKS en deel je met Bancontact. Voor de Payment-API volstaat de API-key.

```php
use BancontactPro\ClientFactory;
use BancontactPro\Credentials\Credentials;
use BancontactPro\Credentials\ProductCredentials;
use BancontactPro\Credentials\SigningKey;
use BancontactPro\Enums\Environment;
use BancontactPro\Enums\Product;

$credentials = new Credentials(
    environment: Environment::Preprod, // standaard; Production voor echte betalingen
    products: [Product::OnDisplay->value => new ProductCredentials(apiKey: '...', merchantProfileId: '...')],
    signingKey: new SigningKey(kid: 'mijn-kid', privateKeyPem: file_get_contents('key.pem')),
);
$factory = new ClientFactory($credentials);
```

In plaats van een vast object kun je een functie meegeven die de actuele credentials teruggeeft, bijvoorbeeld uit een kluis of database: `new ClientFactory(fn () => $vault->load())`. De bibliotheek roept ze bij elk gebruik opnieuw aan en cachet niets.

Een eigen sleutel genereren en de publieke JWK voor Bancontact exporteren:

```php
$private = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
echo \BancontactPro\Signing\Keys::toPublicJwkJson($private, 'mijn-kid');
```

## Een betaling aanmaken

```php
use BancontactPro\Models\CreatePaymentRequest;

$payments = $factory->payments(Product::OnDisplay);
$payment = $payments->create(new CreatePaymentRequest(amount: 1000, reference: 'order-42'));
echo $payment->links->qrcode->href, ' ', $payment->links->deeplink->href;

$status = $payments->get($payment->paymentId); // polling als terugval voor de callback
```

Bedragen zijn gehele getallen in cents. Statussen zijn enums (`MerchantPaymentStatus::Succeeded`); een waarde die deze versie niet kent blijft als ruwe string staan in plaats van een fout te geven. Bij een time-out op `create` is de uitkomst onbekend: zoek eerst op `reference` met `$payments->search(...)` voor je opnieuw probeert.

Ook beschikbaar: `cancel`, `search`, `acknowledge` (VOID-modus), `getDebtorRefundIban` en `createStaticQrPayment`.

## Een callback verifiëren

Geef de **ruwe** body mee, niet een opnieuw geserialiseerde JSON, samen met de `Signature`-header en het pad van het verzoek (zonder querystring).

```php
use BancontactPro\Exception\InvalidCallbackException;

$verifier = $factory->callbackVerifier();

try {
    $callback = $verifier->verifyAndParse(
        $_SERVER['HTTP_SIGNATURE'] ?? '',
        file_get_contents('php://input'),
        parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    );
} catch (InvalidCallbackException) {
    http_response_code(400); // niet vertrouwen
    exit;
}

handle($callback->payload->status, $callback->requestId); // requestId (jti) is uniek per bericht
http_response_code(200);
```

Antwoord pas met `200` na verificatie en verwerking. Bancontact herhaalt callbacks met identieke inhoud, dus verwerk idempotent (zie ook BancontactPro.Net#9).

PHP-FPM deelt geen geheugen tussen requests. Geef daarom een PSR-16-cache mee (`new ClientFactory($credentials, cache: $cache)`), anders wordt de JWKS bij elke callback opnieuw opgehaald. De sleutels worden hoogstens 12 uur bewaard en opnieuw opgehaald bij een onbekende `kid`.

## Terugbetalingen en reconciliatie

```php
use BancontactPro\Models\CreateRefundRequest;

$refunds = $factory->refunds(Product::OnDisplay);
$refund = $refunds->create($paymentId, new CreateRefundRequest(amount: 500, currency: 'EUR'), idempotencyKey: 'refund-order-42');

$reconciliation = $factory->reconciliation(Product::OnDisplay);
$payouts = $reconciliation->getPayouts();
```

Geef bij `refunds->create` altijd zelf een `idempotencyKey` mee als je de aanvraag kunt herhalen. Hergebruik van dezelfde sleutel met andere parameters geeft een `RefundIdempotencyConflictException`.

## QR-links (On a Receipt, Top Up, Static QR)

```php
use BancontactPro\Qr\QrLinks;

$payload = QrLinks::fixedAmountPaymentUrl(Environment::Production, $ppid, amountCents: 1000, description: 'Kassabon');
$imageUrl = QrLinks::qrCodeImageUrl(Environment::Production, $payload);
```

Dit doet geen HTTP-aanroep. Gebruik voor payload en afbeelding dezelfde omgeving.

## Fouten

Een niet-succesvol antwoord geeft een `ApiException` met `statusCode`, `errorCode` en, alleen bij de Payment-API, `traceId` en `spanId` voor een supportticket. Een niet-geconfigureerd product (`ProductNotConfiguredException`) of een ontbrekende signing-sleutel (`SigningKeyNotConfiguredException`) faalt bij het aanmaken van de client. Netwerkfouten van de PSR-18-client komen ongewijzigd door. Alle eigen uitzonderingen implementeren `BancontactException`.

## Licentie

Apache License 2.0, zie [LICENSE](LICENSE) en [NOTICE](NOTICE). Copyright 2026 Axel Dewaele.

Dit is een onafhankelijk project, niet verbonden aan, goedgekeurd of ondersteund door Bancontact. "Bancontact" en "Bancontact Pro" zijn merken van hun eigenaar. De namen worden alleen gebruikt om aan te geven waarmee de bibliotheek werkt. De licentie geeft geen rechten op die merken en de rechthebbende van de code maakt er geen aanspraak op.

## Versies

SemVer in hetzelfde formaat als alle andere taalversies, bijvoorbeeld `0.2.0-preview.1`. De regels (major en minor gelijk over alle talen) staan in de [README van BancontactPro.SDK](https://github.com/yvebrecht/BancontactPro.SDK#versioning). De versie staat in `src/Version.php`; Packagist leest de git-tag.

## Ontwikkelen

PHP en Composer zijn niet vereist op de machine; Docker volstaat:

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c "composer install && composer check"
```

Met een lokale PHP: `composer install && composer check` (php-cs-fixer, PHPStan level max, PHPUnit).
