# BancontactPro.PHP

Idiomatische PHP-client voor de Bancontact Pro-API's (Payment, Refund, Reconciliation). Geen officieel Bancontact-project.

## Bronnen in volgorde van gezag

1. Bancontact Pro-documentatie en OpenAPI-specs: https://docs.bancontactpro.com/ (Payment v3.6.5, Refund v3.0.3, Reconciliation v3.0.1, productgidsen, callback-gids). Verbatim kopieën staan in `tests/specs/`.
2. `../BancontactPro.Net` (en de reeds afgeronde `../BancontactPro.Python`) als vergelijking en bron van testvectoren. Dit is **geen directe port**. Bij conflict wint de documentatie.

Verzin geen Bancontact-gedrag. Elke gedragsbeslissing verwijst naar een documentatiepagina of spec-operatie (docblock of commentaar). Wat niet gedocumenteerd is, noteer je als open vraag in het issue. Afwijkingen met de .NET-code meld je in BancontactPro.Net met het label `spec-discrepancy`.

## Ontwerp

- Synchroon, PSR-18 (`ClientInterface`) + PSR-17; standaard via `php-http/discovery`. JWKS-cache optioneel via PSR-16 (nodig omdat PHP-FPM geen geheugen deelt tussen requests). Logging via PSR-3.
- `declare(strict_types=1)`, PHP >= 8.2, `readonly` klassen, backed enums, PHPStan level max, PSR-12/PER-CS via php-cs-fixer. Namespace `BancontactPro\`, `camelCase` eigenschappen.
- Pakketnaam `yvebrecht/bancontactpro` (nog niet gereserveerd op Packagist).
- Versie: major.minor loopt gelijk met alle taalversies (nu 0.2); patch en prerelease zijn vrij per taal. SemVer-formaat `0.2.0-preview.1`, enige bron `src/Version.php`; git-tags `v0.2.0-preview.1`. Geen `version` in composer.json (Packagist leest tags).
- Modellen zijn `final readonly` klassen; `Json\Hydrator` bouwt responses uit de constructor-signatuur (lijsten via `#[ListOf]`), `Json\Serializer` maakt request-JSON. Ongezette optionele velden worden weggelaten, nooit als `null` verstuurd. Onbekende enumwaarden crashen niet: het veld heeft type `Enum|string` en bevat de ruwe string als de waarde onbekend is.
- Credentials worden per aanroep gelezen (`ClientFactory`, provider = `callable(): Credentials`) en nooit gecachet; een client is gebonden aan één product.
- Payment-calls: alleen `Authorization: Bearer`. Refund en Reconciliation: ook `Signature`.
- `totalAmount` niet modelleren (verplicht maar niet gedefinieerd in de specs).
- Geen telemetrie en geen netwerkverkeer naar de aanbieder.

## Commando's

Lokaal is PHP niet geïnstalleerd; gebruik Docker (`composer:2` bevat PHP en Composer):

```bash
docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c "composer install && composer check"
```

Met een lokale PHP: `composer install && composer check` (php-cs-fixer dry-run, PHPStan, PHPUnit). Tests: PHPUnit met een eigen PSR-18 mock. Geen echte credentials of netwerk.

## Status en grenzen

- De repo staat onder Apache-2.0 (LICENSE en NOTICE, copyright Axel Dewaele). Het project is onafhankelijk en maakt geen aanspraak op de merken Bancontact of Bancontact Pro (zie NOTICE). **Er wordt niets gepubliceerd (Packagist, tags, releases) zonder uitdrukkelijk akkoord.**
- Open punten bij Bancontact: zie BancontactPro.Net#1, #2, #9 en #10 (`..` in de Signature, scheiding in de signing input, callback-retries, callback-headerclaims).
- Commit klein en regelmatig op een featurebranch, met het Gmail-adres (zie geheugen), nooit met het werkadres. Niet pushen of publiceren zonder verzoek.
