# Stundenerfassung

Handy-Webseite für die Reinigungskräfte: Arbeitsstunden, Ferien, Krankheit, Unfall und andere Abwesenheiten pro Tag erfassen und am Monatsende als Excel-Datei teilen.

- Alles steckt in `index.html` – keine Server, keine Datenbank.
- Einträge werden nur auf dem Handy der Mitarbeiterin gespeichert (Browser-Speicher).
- Anmeldung mit Personalnummer und Passwort (mind. 12 Zeichen), keine Namen.
- Bankfeiertage Liechtenstein werden automatisch berechnet.

## Anpassen

Oben im `<script>` in `index.html`:

```js
const FIRMA = "Reinigung";
const EMPFAENGER = "";
const PERSONALNUMMERN = ["1001", "1002", "1003"];
```
