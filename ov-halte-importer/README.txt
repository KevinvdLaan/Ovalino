OV Halte Importer

Installatie
1. Plaats de map `ov-halte-importer` in `wp-content/plugins/`.
2. Activeer de plugin in WordPress.
3. Ga in wp-admin naar `Gereedschap > OV Halte Importer`.
4. Upload:
   - `ExportCHB_....xml.gz`
   - `PassengerStopAssignmentExportCHB_....xml.gz`
   - `NeTEx_....xml.gz`

Shortcodes
- `[ov_halte stopplace="NL:S:10006870"]`
- `[ov_halte quay="NL:Q:10006870"]`
- `[ov_halte user_stop="10006870"]`
- `[ov_halte user_stops="10006870,10006880"]`
- `[ov_halte quay="NL:Q:10006870,NL:Q:10006880"]`
- `[ov_halte stopplace="NL:S:10006870" departures_url="https://voorbeeld.nl/vertrektijden"]`
- `[ov_halte stopplace="NL:S:10006870" schedule_url="https://www.ovnieuwsuitgroningen.nl/dienstregeling/"]`

Opmerkingen
- De plugin verwijdert oude datasetbestanden volledig bij een nieuwe import.
- Lijnkleuren en tekstkleuren worden uit NeTEx gebruikt als die aanwezig zijn.
- Zonder lijnkleur gebruikt de plugin `#861121`.
- Meerdere codes in een shortcode worden samengevoegd en dubbele regels worden verwijderd.
- Elke lijn wordt op de frontend op een eigen regel getoond.
- Per lijnrichting wordt automatisch de ritgewogen hoofdbestemming van de actuele OV-dag getoond. De OV-dag loopt van 04:00 tot 03:59.
- Via-teksten in bestemmingen worden automatisch opgeschoond, bijvoorbeeld `Paddepoel via Hoofdstation` wordt `Paddepoel`.
- Als de NeTEx-data geldige dienstregelinginformatie bevat, toont de shortcode per lijnrichting de twee eerstvolgende geplande vertrektijden achter de bestemming, bijvoorbeeld `Hoofdstation: 12:34 - 12:49`.
- Het lijnnummer en de bestemming linken standaard naar `/dienstregeling/` met de juiste lijn, richting en actuele OV-dagdatum. Gebruik eventueel `schedule_url` als de dienstregelingpagina een andere URL heeft.
- Vertrektijden worden alleen getoond binnen de huidige OV-dag. Die loopt van 04:00 tot 03:59, zodat ritten pas over meerdere dagen niet alvast op de frontend verschijnen.
- Na deze update moeten de datasets opnieuw worden geimporteerd zodat de nieuwe bestemming- en dienstregelingstabellen worden gevuld.
- Vanaf versie 1.2.3 worden ook uitstaphaltes in de stop-offsets opgeslagen. Actuele vertrektijden blijven alleen instaphaltes gebruiken.
- Exacte vertrek-URL's worden niet uit de meegeleverde XML-bestanden gehaald; gebruik daarvoor eventueel `departures_url`.
