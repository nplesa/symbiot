# Symbiot – integrare sosiri/plecări aeroporturi

Acest pachet este un overlay pentru versiunea din arhiva `symbiot.local.zip`.
Nu înlocuiește infrastructura existentă de transport și nu schimbă designul panoului din dreapta.

## Fișiere

- `app/Services/FlightAwareProvider.php` – client FlightAware AeroAPI, cache 2 minute, fereastră de 24h.
- `app/Http/Controllers/AirportFlightsController.php` – endpoint JSON pentru tabla aeroportului.
- `config/services.php` și `.env.example` – configurare FlightAware.
- `routes/web.php` – endpoint protejat, cu rate limit.
- `app/Http/Controllers/TransportPoiController.php` – păstrează codurile IATA/ICAO în detaliile POI.
- `resources/js/pages/map.js` și `resources/js/pages/dashboard.js` – click-ul pe aeroport folosește același eveniment și același panou din dreapta folosit pentru stații.

## Activare

1. Copiază fișierele din acest overlay peste proiectul Symbiot.
2. Obține un API key FlightAware AeroAPI.
3. Adaugă în `.env`:

   `FLIGHTAWARE_API_KEY=cheia_ta`

   `FLIGHTAWARE_BASE_URL=https://aeroapi.flightaware.com/aeroapi`

4. Rulează `php artisan config:clear` (sau `php artisan config:cache` după configurare).
5. Recompilează frontend-ul: `npm run build`.

Dacă nu există cheie, panoul rămâne funcțional și afișează mesajul că providerul nu este configurat.

## Comportament

- La click pe aeroport se caută sosirile și plecările programate în următoarele 24 de ore, începând cu ora curentă.
- Se folosesc estimările când sunt disponibile și se afișează orele în fusul orar al aeroportului, dacă FlightAware îl returnează.
- Rezultatele sunt cache-uite 2 minute pentru a limita consumul API.
- Datele includ numărul zborului, origine/destinație, operator, terminal, poartă, bandă de bagaje și status, în măsura în care furnizorul le oferă.
- Codul ICAO este preferat, cu fallback la IATA.

## Verificări efectuate

- `php -l` pentru controller, service, config și routes.
- `node --check` pentru `map.js` și `dashboard.js`.

Nu s-a putut testa o cerere live către FlightAware deoarece cheia API nu este inclusă în arhivă.
