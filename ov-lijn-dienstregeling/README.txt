OV Lijn Dienstregeling

Installatie
1. Plaats de map `ov-lijn-dienstregeling` in `wp-content/plugins/`.
2. Zorg dat `OV Halte Importer` actief is en dat de datasets zijn geimporteerd.
3. Activeer `OV Lijn Dienstregeling`.
4. Ga in wp-admin naar `Gereedschap > OV Lijn Dienstregeling` om lijnen voor de frontend te verbergen.

Shortcode
- `[ov_lijn_dienstregeling]`

Werking
- De plugin gebruikt de databasetabellen van OV Halte Importer.
- De frontend toont een dropdown voor de lijn, een dropdown voor de beschikbare dienstregelingvarianten en een knop `Zoeken`.
- Dienstregelingvarianten worden gegroepeerd naar `Maandag t/m vrijdag`, `Zaterdag` en `Zondag`, met de beschikbare datums erbij.
- De standaardrichting is `inbound`; met de knop `Tegenovergestelde richting` kan de andere richting worden gekozen.
- De dienstregeling blijft binnen de paginamarges; bij brede tabellen verschijnen pijltjes om horizontaal door de tijden te bladeren.
- De eerste-halte-tijd wordt niet als kolomkop getoond; de haltekolom blijft wel zichtbaar.
- Korte ritten blijven in dezelfde dienstregeling zichtbaar. Haltes die een rit niet aandoet blijven leeg in die kolom.
- Aankomsttijden op eindhaltes kunnen alleen volledig worden getoond nadat OV Halte Importer opnieuw is geimporteerd met de versie die ook uitstaphaltes opslaat.
- Haltes worden automatisch klikbaar als er een gepubliceerde pagina bestaat met een passende `[ov_halte]` shortcode voor dezelfde `user_stop` of `quay`.
- Verborgen lijnen blijven wel in de database staan, maar verschijnen niet in de frontend-dropdown. Deze keuze wordt opgeslagen in de WordPress-optie `ovld_hidden_lines` en blijft bij pluginupdates bewaard.
