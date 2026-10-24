---
paths:
  - "src/Signing/**"
  - "src/Http/**"
  - "src/Webhooks/**"
  - "tests/Signing/**"
  - "tests/Webhooks/**"
---

# Signing en callbackverificatie

- ES256 over P-256. De handtekening is altijd raw `r || s` (64 bytes, IEEE P1363), nooit DER. `openssl_sign` geeft DER terug: converteer met `EcdsaSignature`.
- Formaat `base64url(header)..base64url(signature)` (twee punten, RFC 7797) volgens de callback-gids en de Payment-spec. Verificatie tolereert ook één punt. De signing input is `base64url(header) . "." . base64url(body)`; dit is nog te bevestigen (BancontactPro.Net#1, #2).
- Sign en verifieer de body-bytes exact zoals verstuurd of ontvangen, nooit een herserialisatie.
- Het `path`-claim is het pad van de request-URI zonder querystring, in de verzonden (percent-encoded) vorm.
- Een onbekend of ontbrekend `crit`-claim betekent afwijzen. De verifier geeft `null` terug, de callback-verifier gooit `InvalidCallbackException`.
- JWKS: herladen bij onbekende `kid` (hoogstens één keer per lookup), cache maximaal 12 uur, één cache per omgeving.
- Nooit een sleutel, API-key of licentiesleutel loggen of in `var_dump`/`__debugInfo` tonen.
