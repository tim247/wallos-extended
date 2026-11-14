<?php
require_once 'includes/header.php';

// ponytail: help text is German only (the app's main language); add a translated copy when needed.
$sections = [
    ['Dashboard', 'index.php', [
        'Die Startseite zeigt die nächsten Zahlungen, die monatlichen Gesamtkosten aller Bereiche (Abos, Verträge, Versicherungen, Finanzierungen), die verbleibende Restschuld und anstehende Fristen.',
        'Ein Klick auf eine Kachel führt direkt in den jeweiligen Bereich.',
    ]],
    ['Abonnements', 'subscriptions.php', [
        'Mit „+“ legst du ein Abo an: Name, Preis, Währung, Zahlungsrhythmus (Tage, Wochen, Monate, Jahre oder einmalig), nächste Zahlung, Kategorie, Zahlungsmethode und Zahler.',
        'Das nächste Zahlungsdatum rückt nach jeder Fälligkeit automatisch weiter.',
        'Preisänderungen werden als Preisverlauf gespeichert. Gekündigte Abos kannst du archivieren statt löschen.',
        'Über Filter und Sortierung (oben) grenzt du die Liste nach Kategorie, Zahler, Zahlungsmethode oder Status ein.',
        'Ein Abo kann mehreren Kategorien und mehreren Zahlern zugeordnet werden; bei mehreren Zahlern wird jeder benachrichtigt.',
        'Listen- oder Rasteransicht wählst du mit den Schaltern oben rechts. Ordner (Einstellungen → Ordner) fassen Abos zusammen.',
        'Massenaktionen: Mit dem Häkchen-Symbol wählst du mehrere Abos aus und änderst für alle zugleich Kategorie, Zahlungsmethode, Benachrichtigung oder löschst sie.',
        'Abos mit manueller Verlängerung markierst du nach der Zahlung als „bezahlt“; überfällige, unbezahlte Abos zeigt das Dashboard an.',
    ]],
    ['Verträge', 'contracts.php', [
        'Für Verträge mit Laufzeit (Strom, Internet, Handy …): Anbieter, Vertragsnummer, Beginn, Mindestlaufzeit, Verlängerung und Kündigungsfrist.',
        'Wallos Extended berechnet den letzten Kündigungstag und erinnert dich rechtzeitig per Benachrichtigung.',
        'Kündigungsschreiben: Aus Anbieter, Vertragsnummer und Frist entsteht ein fertiges PDF. Danach setzt du den Status auf „Kündigung verschickt“ und später „bestätigt“. Die Vorlage bearbeitet der Admin unter Admin → Kündigungsvorlage (Platzhalter wie {{anbieter}}, {{vertragsnummer}}).',
    ]],
    ['Versicherungen', 'insurance.php', [
        'Versicherungen mit Beitrag, Zahlweise, Versicherungsnummer, Ablauf und Kündigungsfrist erfassen.',
        'Auch hier gibt es Kündigungserinnerungen und Kündigungsschreiben wie bei den Verträgen.',
    ]],
    ['Finanzierungen', 'financing.php', [
        'Annuitätendarlehen, endfällige Darlehen und Bausparverträge mit Zins, Tilgung, Laufzeit und Sondertilgungen. Der Tilgungsplan und das Diagramm (Restschuld über die Zeit) werden automatisch berechnet.',
        'Zinsbindungsende: Das Ende der Zinsbindung wird angezeigt und rechtzeitig gemeldet; mit der Szenario-Simulation spielst du einen Anschlusszins durch.',
        'Abgleich mit Kontoauszug: Stimmt die Berechnung nicht mit dem Jahreskontoauszug der Bank überein, trägst du unter „Abgleich mit Kontoauszug“ den Saldo zu einem Datum ein. Ab dort rechnet der Plan mit dem echten Wert weiter. Einzelne Raten kannst du ebenfalls überschreiben.',
        'Ablösung durch Bausparvertrag: Beim Darlehen wählst du den Bausparvertrag, der es ablöst. Bei Zuteilung wird das Darlehen getilgt und der Bausparvertrag läuft weiter; im Diagramm erscheint seine Tilgung in einer eigenen Farbe.',
    ]],
    ['Verbrauch', 'consumption.php', [
        'Zähler (Strom, Gas, Wasser, PV …) anlegen und Zählerstände eintragen; Diagramme zeigen den Verbrauch je Zeitraum, mit Datumsfilter.',
        'Abschlag vs. Verbrauch: Hinterlegst du Abschlag und Arbeitspreis, schätzt die App eine Nachzahlung oder Erstattung.',
        'Ablese-Erinnerung: Auf Wunsch wirst du regelmäßig an das Ablesen erinnert.',
        'Home Assistant: Unter Einstellungen → Home Assistant Adresse und Token eintragen und einem Zähler eine Entität zuordnen. Die Stände (Strom, PV) werden täglich um 23:55 Uhr automatisch übernommen.',
        'Tarifvergleich: Unter „Zählerstand erfassen“ eines Zählers Arbeitspreis, Grundgebühr und Bonus eines neuen Angebots eintragen – die App rechnet mit deinem Jahresverbrauch aus, was du sparst oder mehr zahlst.',
    ]],
    ['Kalender', 'calendar.php', [
        'Monatsansicht aller Zahlungen mit Betrag; Verträge, Versicherungen und Darlehensraten erscheinen mit eigenem Symbol. Ist ein eigener Abrechnungszeitraum eingestellt (z. B. von Gehalt zu Gehalt), zeigt der Kalender diesen Zeitraum statt des Kalendermonats.',
        'Über den iCal-Link (Kalender-Abo) bindest du Zahlungen, Fristen, Zinsbindungsenden und Ablesetermine aller Bereiche in Outlook, Google oder Apple Kalender ein.',
    ]],
    ['Jahresübersicht', 'yearly.php', [
        'Zwölf-Monats-Ansicht: In welchem Monat fallen jährliche oder quartalsweise Beiträge an (Versicherungen, Kfz-Steuer …)?',
        'Darunter steht ein Vorschlag für die monatliche Rücklage, damit große Beiträge nicht überraschen.',
    ]],
    ['Kostenbericht', 'report.php', [
        'Alle laufenden Kosten nach Bereich, die Aufteilung nach Person (Zahler) und die Preisänderungen der letzten 12 Monate auf einer Seite.',
        '„Drucken / PDF“ öffnet den Druckdialog; mit „Als PDF speichern“ entsteht ein PDF, z. B. für die Steuer oder die Haushaltsplanung.',
    ]],
    ['Kontoabgleich', 'bankimport.php', [
        'Kontoauszug als CSV hochladen (Sparkasse, DKB, ING, comdirect, Volksbank, N26 …). Die Abbuchungen werden mit deinen Abos, Verträgen, Versicherungen und Raten verglichen.',
        'Du siehst, was gefunden wurde und wo der Betrag abweicht (Preiserhöhung?), welche regelmäßigen Abbuchungen noch nicht erfasst sind und für welche Einträge keine Abbuchung gefunden wurde.',
        'Zugeordnet wird über den Namen bzw. Anbieter. Steht der Anbieter auf dem Kontoauszug anders, trage ihn beim Eintrag als Anbieter ein. Die Datei wird nicht gespeichert.',
    ]],
    ['Preiserhöhungen', 'index.php', [
        'Jede Preisänderung eines Abos, Vertrags oder einer Versicherung wird automatisch protokolliert. Erhöhungen der letzten 12 Monate zeigt das Dashboard mit Prozentangabe.',
        'Bei Verträgen und Versicherungen erinnert der Hinweis an das Sonderkündigungsrecht, das bei einer Preiserhöhung oft besteht.',
    ]],
    ['Statistiken', 'stats.php', [
        'Übersicht, Kostentrend und Prognose, Budget, Aufteilung nach Kategorie, Zahler, Zahlungsmethode, Zahlungsrhythmus und Währung sowie die Abo-Historie.',
        'Im Abschnitt „Haushalt“ stehen die monatliche Gesamtbelastung aller Bereiche, die Aufteilung nach Bereich und die Preisentwicklung. Der Kostentrend zählt alle Bereiche mit.',
        'Diagramme erscheinen automatisch, sobald genug Daten vorhanden sind; oben wählst du, ob die Statistik für alle oder nur für einen Zahler, eine Kategorie oder Zahlungsmethode gilt.',
    ]],
    ['Dokumente (Paperless-ngx)', 'settings.php', [
        'Unter Einstellungen → Paperless Adresse und API-Token deines Paperless-ngx eintragen.',
        'In Abos, Verträgen, Versicherungen und Finanzierungen kannst du dann Dokumente suchen und verknüpfen; passende Dokumente werden auch vorgeschlagen. Die Dateien bleiben in Paperless.',
        'Ohne Paperless: Bei Verträgen, Versicherungen und Finanzierungen lassen sich unter „Eigene Dokumente“ PDFs, Bilder und Office-Dateien (bis 20 MB) direkt hochladen. Sie liegen im Ordner db/files und sind in der Sicherung enthalten.',
    ]],
    ['Einstellungen', 'settings.php', [
        'Haushalt (Zahler), Kategorien, Ordner, Zahlungsmethoden, Währungen, Benachrichtigungen, Anzeige, Design, KI-Empfehlungen sowie Paperless und Home Assistant.',
        'Budget: ein monatliches Budget oder ein eigener Abrechnungszeitraum (z. B. vom 25. bis 24.), der auf Wunsch auch Statistik und Kalender bestimmt.',
        'Benachrichtigungen: E-Mail, Push-Benachrichtigungen im Browser bzw. auf dem Handy sowie Dienste wie Telegram, ntfy, Gotify, Pushover, Discord oder Webhooks.',
        'Die Standard-Kategorien werden in deiner Sprache angezeigt; eigene Kategorien legst du dort an.',
    ]],
    ['Profil', 'profile.php', [
        'Benutzername, E-Mail, Passwort, Avatar, Zwei-Faktor-Anmeldung (TOTP) und API-Schlüssel.',
    ]],
    ['Administration (nur Admin)', 'admin.php', [
        'Benutzer verwalten, Registrierung erlauben, Anmeldung per OIDC (Single Sign-on), SMTP für E-Mails, Sicherheitseinstellungen, Benachrichtigungsdienste für alle Benutzer und die Kündigungsvorlage.',
        'Backup und Wiederherstellung: „Sichern“ lädt eine .zip mit Datenbank, Logos und Dokumenten. „Wiederherstellen“ nimmt eine .zip oder eine einzelne wallos.db – auch aus dem Original-Wallos (alle Versionen). Die bisherige Datenbank bleibt als before-restore-….db im Ordner db, alte Datenbanken werden automatisch aktualisiert.',
        'Automatische Sicherung: Jede Nacht um 3:15 Uhr wird die Datenbank nach db/backups/ kopiert. Wie viele Sicherungen aufbewahrt werden, stellst du dort ein (0 = aus).',
    ]],
    ['Automatische Aufgaben', null, [
        'Im Hintergrund laufen täglich: nächste Zahlungstermine aktualisieren, Wechselkurse holen, Benachrichtigungen und Kündigungserinnerungen senden, der Home-Assistant-Import und die automatische Sicherung. In Docker und im LXC-Container ist das bereits eingerichtet.',
    ]],
];
?>

<section class="contain">
    <section class="account-section">
        <header>
            <h2><?= translate('help', $i18n) ?></h2>
        </header>
        <p>Wallos Extended verwaltet alle wiederkehrenden Kosten deines Haushalts an einem Ort. Klicke auf einen Bereich, um ihn aufzuklappen.</p>
        <p><a href="https://github.com/tim247/wallos-extended/blob/main/docs/HILFE.md" target="_blank" rel="noreferrer"><i class="fa-solid fa-book"></i> Ausführliches Handbuch mit Anleitungen für E-Mail, Home Assistant und Paperless-ngx</a></p>
        <?php foreach ($sections as [$title, $link, $points]): ?>
            <details class="help-section">
                <summary><?= htmlspecialchars($title) ?></summary>
                <ul>
                    <?php foreach ($points as $point): ?>
                        <li><?= htmlspecialchars($point) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($link): ?>
                    <p><a href="<?= $link ?>">Zu „<?= htmlspecialchars($title) ?>“ <i class="fa-solid fa-arrow-right"></i></a></p>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </section>
</section>

<style>
    .help-section { border-bottom: 1px solid rgba(128,128,128,.25); padding: 12px 0; }
    .help-section summary { cursor: pointer; font-weight: 600; font-size: 1.05em; }
    .help-section ul { margin: 10px 0 6px 20px; line-height: 1.6; }
    .help-section li { margin-bottom: 6px; }
</style>

<?php
require_once 'includes/footer.php';
?>
