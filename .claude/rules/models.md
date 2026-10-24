---
paths:
  - "src/Models/**"
  - "src/Enums/**"
  - "src/Clients/**"
---

# Modellen en clients

- Velden volgen de specs letterlijk, ook waar die onderling verschillen: `CreatePaymentResponse::$expiresAt` tegenover `Payment::$expireAt` is geen typfout.
- Optioneel is niet nullable: laat ongezette velden weg bij requests (`Serializer` filtert `null`).
- Bedragen zijn gehele getallen in cents (1 - 999.999.999.999).
- Datums worden tolerant geparsed (met of zonder microseconden, `Z` of `+00:00`); requests sturen `Y-m-d\TH:i:s.v\Z` in UTC.
- Refundstatus kent geen `PROCESSING` (verwijderd in Refund API v3.0.2).
- Reconciliation: `start-date` en `end-date` altijd samen, hoogstens 30 dagen; `size` is vast 10000 in de spec.
- Een time-out bij `create` heeft een onbekende uitkomst: nooit blind opnieuw proberen (zoek eerst op `reference`).
