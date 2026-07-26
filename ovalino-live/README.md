# Ovalino Live

Een Python-service voor actuele NDOV realtimevertragingen.

## Doel

Ovalino Live is een aparte module die:

- continu verbinding maakt met NDOV ZeroMQ-datastromen
- specifieke envelopes voor KV78Turbo (bussen) en InfoPlus (treinen) abonnementeert
- ritmutaties, vertragingen en vervallen ritten ontleedt
- actuele data beschikbaar maakt via een REST API
- WordPress/Ovalino de meest recente data laat opvragen zonder dat de shared hosting zelf ZeroMQ hoeft te draaien

> Let op: voor actuele busvertrektijden gebruiken we de KV78Turbo feed op `7817`. Dit is de juiste stream voor de Qbuzz/EBS-gegevens; de oude BISON/KV17-feed op `7658` is hier niet de juiste bron.
> De service ondersteunt hiervoor nu CTX-berichten met `\G`, `\T` en `\L` en kan ook tijden verwerken zoals `24:14:03` die na middernacht voorkomen.

## Architectuur

1. Python-service draait als `ovalino-live` op een VPS
2. Service maakt twee ZeroMQ-verbindingen:
   - `tcp://pubsub.besteffort.ndovloket.nl:7817` voor KV78Turbo
   - `tcp://pubsub.besteffort.ndovloket.nl:7664` voor InfoPlus
3. Service cachet actuele vertragingen en ritstatussen in geheugen (en optioneel snapshot op schijf)
4. WordPress vraagt de actuele data op via REST:
   - `GET /api/delays`
   - `GET /api/health`
5. WordPress gebruikt deze data om `wp_ovhi_realtime_delays` te vullen of direct te renderen

## Installatie

1. Maak een VPS met Python 3.11+.
2. Clone of kopieer `ovalino-live` naar de VPS.
3. Maak een virtuele omgeving:
   ```bash
   python -m venv /opt/ovalino-live/venv
   source /opt/ovalino-live/venv/bin/activate
   ```
4. Installeer dependencies:
   ```bash
   pip install -r requirements.txt
   ```
5. Kopieer config:
   ```bash
   cp config.example.yaml config.yaml
   ```
6. Pas `config.yaml` aan met je eigen URL's en abonnementen.
7. Start de service voor tests:
   ```bash
   uvicorn ovalino_live:app --host 0.0.0.0 --port 8000
   ```

## Systemd-service

Gebruik `ovalino-live.service` als voorbeeld om de service als daemon te draaien.

## WordPress-integratie

Voor WordPress zijn er twee routes:

- Pull: WP vraagt live data op bij `Ovalino Live`.
- Push: `Ovalino Live` post updates naar WordPress.

De eenvoudigste eerste stap is pull.

### Hoe dit koppelt met de bestaande plugins

De bestaande plugins gebruiken de tabel `wp_ovhi_realtime_delays` om actuele vertragingen en vervallen ritten weer te geven.

- `OV Lijn Dienstregeling` leest `delay_seconds` en `is_cancelled` uit deze tabel en toont daardoor:
  - geplande vertrektijd met een rood `+2` achter de tijd
  - doorgestreepte ritten bij `is_cancelled`
- `OV Trein Dienstregeling` gebruikt hetzelfde model en toont vertraagde vertrek- en aankomsttijden in de dienstregeling
- `Ovalino Map` maakt ook een JOIN op `ovhi_realtime_delays` en kan pop-ups bijwerken met de actuele vertraging

Omdat de WordPress-kant al werkt met deze tabel, is de snelste integratie:

1. Laat de Python-service op de VPS de NDOV-gegevens ophalen en normaliseren.
2. Haal via `/api/delays` de vertragingen op in het JSON-formaat dat de plugin verwacht.
3. Schrijf die records in `wp_ovhi_realtime_delays` via de bestaande `OV Halte Importer` realtime-import of een kleine WP-sync.

### Voorbeeldbestand voor import in OV Halte Importer

```json
[
  {
    "journey_ref": "NL:Q:12345678",
    "stop_code": "NL:Q:1001",
    "delay_seconds": 120,
    "is_cancelled": false
  },
  {
    "journey_ref": "NL:Q:12345679",
    "stop_code": "NL:Q:1001",
    "delay_seconds": 0,
    "is_cancelled": true
  }
]
```

### Handige stap-voor-stap installatie

1. Zorg dat je VPS Python 3.11+ heeft en netwerktoegang naar `pubsub.besteffort.ndovloket.nl`.
2. Plaats de `ovalino-live` map op je VPS, bijvoorbeeld `/opt/ovalino-live`.
3. Maak en activeer een virtuele omgeving:

```bash
python -m venv /opt/ovalino-live/venv
source /opt/ovalino-live/venv/bin/activate
```

4. Installeer dependencies:

```bash
pip install -r requirements.txt
```

5. Kopieer de config en pas deze aan:

```bash
cp config.example.yaml config.yaml
```

6. Pas in `config.yaml` de ZeroMQ-URL's en abonnementen aan. Voor jouw use case zijn dit de belangrijkste waarden:

```yaml
general:
  listen_host: '0.0.0.0'
  listen_port: 8000
  data_retention_seconds: 7200
  snapshot_path: './ovalino_live_snapshot.json'
zero_mq:
  # Voor busdata gebruiken we KV78Turbo op poort 7817.
  bison_url: 'tcp://pubsub.besteffort.ndovloket.nl:7817'
  info_plus_url: 'tcp://pubsub.besteffort.ndovloket.nl:7664'
  bison_subscriptions:
    - '/QBUZZ/'
    - '/EBS/'
  infoplus_subscriptions:
    - '/RIG/InfoPlusDASInterface4'
    - '/RIG/InfoPlusDVSInterface4'
    - '/RIG/InfoPlusRITInterface5'
wordpress:
  enabled: false
  push_url: ''
  push_token: ''
  timeout_seconds: 10
```

> `0.0.0.0` zegt tegen de service: luister op alle netwerkinterfaces van de VPS. Het is niet het echte IP-adres dat je in de browser of op WordPress gebruikt. Je bereikt de service dan via het echte VPS-IP of een domein dat aan de VPS is gekoppeld.

7. Start de service tijdens tests:

```bash
source /opt/ovalino-live/venv/bin/activate
uvicorn ovalino_live:app --host 0.0.0.0 --port 8000
```

8. Controleer dat de service reageert:

```bash
curl http://127.0.0.1:8000/api/health
curl http://127.0.0.1:8000/api/delays?limit=10
```

9. Voor productie gebruik je een systemd-service of een reverse proxy zoals Nginx. Het voorbeeldbestand `ovalino-live.service` is daarvoor bedoeld.

### Koppeling met WordPress

- Je hoeft geen Python op de Strato shared hosting te draaien.
- De WordPress-site hoeft alleen de vertragingen te ontvangen of in te lezen.
- De simpele oplossing is om de JSON van `/api/delays` om te zetten naar het importformaat van `OV Halte Importer` en die import te gebruiken.
- Voor een automatische oplossing kun je de nieuwe automatische synchronisatie van `OV Halte Importer` gebruiken:
  - stel het `Ovalino Live endpoint` in in de plugininstellingen
  - schakel de live synchronisatie in
  - WordPress controleert dan op elke frontendpagina (maximaal eenmaal per ingestelde interval) of er nieuwe vertragingen zijn en vult `wp_ovhi_realtime_delays`
- Met deze methode worden de bestaande `OV Lijn Dienstregeling`, `OV Trein Dienstregeling` en `Ovalino Map` meteen bijgewerkt wanneer een pagina wordt geopend.

### Belangrijk om te weten

- De service draait extern op een VPS omdat ZeroMQ op een Strato shared server meestal te onbetrouwbaar is.
- De Python-service is alleen nodig op de VPS; WordPress zelf blijft PHP.
- De belangrijkste sleutel voor goede matching is dat de NDOV `journey_ref` en `stop_code` overeenkomen met de waarden die in je WP-datasets staan.
- De huidige implementatie houdt vertragingen maximaal 2 uur in WordPress aan voordat oude records automatisch worden opgeruimd.
- In de huidige blueprint is er een REST API voor pull; een push-integratie naar WordPress is in de config voorbereid, maar nog niet actief in de plugin.
- Voor de huidige WordPress-configuratie hebt u geen `push_url` nodig; stel alleen het live endpoint in in de plugin.

## Parser

Deze service ondersteunt nu KV78Turbo CTX-berichten voor busdata en XML/InfoPlus-berichten voor treindata. In productie moet je de parser nog afstemmen op de daadwerkelijke NDOV-berichten en zorgen dat de juiste velden worden gebruikt voor `journey_ref`, `stop_code`, `delay_seconds` en `is_cancelled`.
