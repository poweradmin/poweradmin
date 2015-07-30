# Baseline
Es wird davon ausgegangen dass die Version aus dem remote master
(65090660c4cea14d9bc9af83fe41af38a34d8a74) installiert ist. Davon ausgehend sind die
Änderungen im Logging entstanden.

# Datenbank
Es sind einige Datenbanktabellen hinzugekommen. Diese befinden sich im Unterordner
`sql`. Speziell sind das

```
    # Upgrade auf 2.1.7-noris
    sql/poweradmin-mysql-update-to-2.1.7-noris.sql

    # Downgrade auf 2.1.7
    sql/poweradmin-mysql-downgrade-to-2.1.7.sql
```

# Mails schicken
Um Mails schicken zu können muss `sendmail` auf dem Server funktionieren.
Anschließend kann man Änderungen per Mail verschicken. Das geht z.B. so:

```
php send_changes_mail.php \
    --dry-run \
    --changes-since "yr4-mo2-dy2 hr2:mi2:sc2" \
    --to recipient1@example.com recipient2@example.com \
    --subject "DNS changes" \
    --header "Hello! There were changes in your DNS configuration:"

###############################################################################

--dry-run
Optional.
Wenn gesetzt wird die Mail auf stdout gedruckt.

--changes-since
Optional.
Ein String zum Angeben der Zeit der letzten Änderung für die Ausgabe.
Muss im Format "yr4-mo2-dy2 hr2:mi2:sc2" vorliegen, also z.B.:
    2015-04-19 16:42:31
Default ist
    1970-01-01 00:00:00
eingestellt, zeigt also alle Änderungen.

--to
Benötigt.
Akzeptiert mehrere Argumente, z.B.

    --to foo@foo.com bar@bar.com test@test.example

--subject
Benötigt.
Ein String für den Betreff.

--from
Optional.
Ein String für den Absender.
Standardmäßig auf 'support@noris.de' gesetzt.

--header
Optional.
Eine Zeichenkette die der der HTML Tabelle vorangestellt wird.
Standardmäßig auf '' gesetzt.

--footer
Optional.
Eine Zeichenkette die der HTML Tabelle angehängt wird.
Standardmäßig auf '' gesetzt.
```
