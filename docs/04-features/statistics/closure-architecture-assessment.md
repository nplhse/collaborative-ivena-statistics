# Bestandsaufnahme: Schließungen

**Stand:** 5. Oktober 2026  
**Gegenstand:** Import, Speicherung, Gruppierung, statistische Auswertung und Volumenprojektion der IVENA-Schließungsliste.  
**Datengrundlage:** lokale PostgreSQL-Datenbank `app`. Es wurden nur lesende Abfragen ausgeführt. Ein vollständiger Neuaufbau wurde für diese Untersuchung nicht gestartet. Während der Untersuchung lief jedoch bereits `app:statistics:rebuild-closure-volume-projection`; er endete mit einem erfolgreichen Tausch der Seitentabelle. Die unten genannten Projektionszahlen sind der Zustand nach diesem Tausch, 14:27 Uhr MESZ.

Krankenhausnamen, Freitextbemerkungen und Personenfelder werden nicht wiedergegeben. Die drei Krankenhäuser heißen hier Klinik A, B und C, in aufsteigender interner `hospital_id`.

Begriffe:

- **Nachgewiesen:** im Code oder in den gelesenen Daten direkt beobachtet.
- **Begründete Vermutung:** aus Codepfad und Größenordnung abgeleitet, aber nicht mit einem Profiler über den ganzen Lauf belegt.
- **Offen:** fachlich oder messtechnisch nicht entschieden.

Die bestehende Produktdokumentation steht in [closure-import.md](../import/closure-import.md) und [closure-analytics.md](closure-analytics.md). Dieser Bericht prüft den damaligen Stand und die vorhandenen Daten dagegen. Die danach umgesetzten Entscheidungen stehen in [closure-architecture.md](closure-architecture.md). Der Text hier bleibt der Untersuchungsstand.

## 1. Zusammenfassung

Eine importierte Zeile ist ein Zeitintervall für genau eine Fachabteilung und genau eine Behandlungsdringlichkeit. SK1, SK2 und SK3 sind deshalb drei Zeilen, nicht ein Ereignis mit drei Kategorien. Eine eigene Entität „Schließungsgruppe“ gibt es nicht. Gruppen und Cluster entstehen erst in der Auswertungsabfrage.

In den vorliegenden Daten ist die parallele Dringlichkeit der Normalfall, nicht die Ausnahme. Von 15.536 verschiedenen Kombinationen aus Krankenhaus, Fachabteilung, Beginn und Ende tragen 12.396 mehr als eine Dringlichkeit. 5.580 davon sind exakt SK1, SK2 und SK3. Exakte Duplikate sind selten: 40 natürliche Schlüssel kommen je zweimal vor, jeweils innerhalb desselben Imports. Ein zweiter Import desselben Krankenhauses liegt nicht vor.

Dieselbe Kombination aus Krankenhaus, Fachabteilung und identischem Intervall ist in 11.345 von 12.396 Mehrfach-Slots auch bei Grund, Einrichtungstyp, Fachgebiet, Schließungseinheit und Gruppen-ID einheitlich. Dagegen sprechen vor allem abweichende Schließungseinheiten (982 Slots mit mehreren Einheiten, 64 mit Einheit und leerem Wert) und zeitlich nur teilweise deckungsgleiche Intervalle (5.167 überlappende, ungleiche Intervallpaare innerhalb derselben Fachabteilung). Eine Zusammenfassung von SK1–SK3 wäre für identische Intervalle mit übereinstimmenden Merkmalen verlustfrei. Sie wäre es nicht für abweichende Einheiten, abweichende Gründe oder ungleiche Zeitgrenzen.

Die Volumenprojektion speichert keine Gruppen. Sie speichert pro kanonischer Schließung, pro betroffener Dringlichkeit und pro Schicht (`base`, `resus`, `cathlab`) jede angebrochene Stunde von 12 Stunden davor bis 12 Stunden danach. Aus 35.949 kanonischen Schließungen wurden 3.554.670 Projektionszeilen, im Median 87 und im Mittel 98,9 Zeilen je Schließung. Die veröffentlichte Tabelle ist 1.171 MB groß.

Der beobachtete Neuaufbau war CPU-gebunden in PHP. Eine Stichprobe zeigte etwa 87 Prozent CPU und gleichzeitig keine laufende SQL-Anweisung. Die stündliche Zuweisungsaggregation eines Krankenhauses, die der Neuaufbau einmal je Krankenhaus ausführt, dauerte im `EXPLAIN ANALYZE` 11 Millisekunden und nutzt den vorhandenen Index `(hospital_id, created_at)`. Ein repräsentatives Paket von 80 Teilintervall-Abfragen dauerte 24 Millisekunden. Die Laufzeit erklärt sich damit nicht aus einem fehlenden Index oder einem einmaligen Scan der 2,3 Millionen Zuweisungen. Der teure Pfad ist die Referenzrechnung je Schließung: für jede Dringlichkeit, jede der drei Schichten und jede Tageszeitgruppe wird ein Kalender von bis zu acht Wochen in PHP erneut abgelaufen, einschließlich sechsfacher Wiederholung innerhalb derselben Tageszeitgruppe. Der in dieser Sitzung beobachtete Lauf startete um 13:54 Uhr und war um 14:27 Uhr abgeschlossen, also höchstens etwa 33 Minuten. Die zuvor genannte Dauer von knapp 50 Minuten wurde hier nicht nachgemessen; sie liegt in derselben Größenordnung und kann zusätzlich Warteschlange, einen vorherigen Zuweisungsaufbau oder einen zweiten vollen Lauf enthalten.

Abstände zwischen aufeinanderfolgenden Schließungen sind nicht als Spalte der Volumenprojektion gespeichert. Der Dauer-Reiter berechnet Pausen zwischen zusammengelegten Phasen eines Krankenhauses, sofern die Lücke vollständig in der geschätzten Importabdeckung liegt. Die Volumenansicht markiert Vorher- und Nachher-Stunden nur als beeinflusst, wenn eine andere Schließung derselben Population sie schneidet.

## 2. Architektur und Datenfluss

Es gibt keinen eigenen Bounded Context „Schließung“. Die Zeile liegt im Allocation-Kontext, der Import im Import-Kontext, die Auswertung im Statistics-Kontext.

```text
CSV (Typ vom Benutzer gewählt)
  -> SplCsvRowReader (gemeinsam mit Zuweisungen)
  -> ClosureRowMapper / ClosureImportFactory
  -> closure_interval, eine Zeile je Quellzeile
  -> ImportCompleted
       -> bei Schließungsimport: RebuildClosureVolumeProjection
       -> bei Zuweisungsimport: RebuildAllocationStatsProjection
            -> danach synchron derselbe volle Schließungsneuaufbau
  -> closure_volume_hour
  -> Closure Analytics (SQL-Gruppen/Cluster) und Volumenansicht
```

### 2.1 Datei, Parsing, Normalisierung

Der Importtyp wird im Upload-Formular gewählt (`ImportType::CLOSURE`), nicht aus dem Dateiinhalt erkannt. Nur `ROLE_CLOSURE_BETA` darf ihn starten. `ROLE_ADMIN` enthält diese Rolle nicht.

`app:import:start <IMPORT_ID>` bzw. der Start im UI dispatcht `ImportClosuresMessage` auf `async_priority_high`.

| Schritt | Klasse | Pfad |
|---|---|---|
| Dispatch | `ImportClosuresDispatcher::dispatch` | `src/Import/Application/Service/ImportClosuresDispatcher.php` |
| Nachricht | `ImportClosuresMessage` | `src/Import/Application/Message/ImportClosuresMessage.php` |
| Handler | `ImportClosuresMessageHandler::__invoke` | `src/Import/Application/MessageHandler/ImportClosuresMessageHandler.php` |
| Lesen | `SplCsvRowReader` über `RowReaderFactory` | `src/Import/Infrastructure/Adapter/SplCsvRowReader.php` |
| Spalten | `ClosureRowMapper::mapAssoc` | `src/Import/Infrastructure/Mapping/ClosureRowMapper.php` |
| Zusammenbau | `ClosureImportFactory::fromDto` | `src/Import/Infrastructure/Mapping/ClosureImportFactory.php` |
| Schreiben | `DoctrineClosureIntervalPersister` | `src/Import/Infrastructure/Adapter/DoctrineClosureIntervalPersister.php` |
| Schleife | `ClosureImporter::import` | `src/Import/Application/Service/ClosureImporter.php` |
| Zeile | `ClosureRowProcessor::process` | `src/Import/Application/Service/ClosureRowProcessor.php` |

Der CSV-Leser ist derselbe wie beim Zuweisungsimport. Er erkennt die Kodierung, wandelt nach UTF-8 und normalisiert Überschriften nach snake_case. Umlaute werden zu `ae`, `oe`, `ue`, `ss`. Deshalb sucht der Mapper `behandlungsdringlichkeit`, `schliessungs_dauer_minuten` und `gruppen_schliessungs_id`.

`ClosureIntervalClock::resolve` liest Beginn und Ende als Wanduhr in `Europe/Berlin` (`d.m.Y` und `H:i:s`). Das Ende muss nach dem Beginn liegen. Die Spalte „Schließungs-Dauer (Minuten)“ wird mit den verstrichenen Minuten verglichen, einschließlich der Sommerzeitumstellung, und nicht gespeichert. Ein Unterschied verwirft die Zeile mit `DURATION_MISMATCH`.

### 2.2 Krankenhaus, Fachabteilung, Dringlichkeit

Das Krankenhaus kommt ausschließlich vom Import, nicht aus der Datei. `ClosureHospitalGuard` liest `Krankenhaus-Kurzname` nur als Plausibilitätsprüfung: ein eindeutig fremdes Katalogkürzel verwirft die ganze Datei (`HOSPITAL_CONFLICT`), mehrere Kürzel behalten nur Zeilen, die exakt zum gewählten Krankenhaus passen (`HOSPITAL_MISMATCH`). Es gibt keine Aliasliste und keine externe Krankenhaus-ID.

Fachgebiet und Fachabteilung laufen über `SpecialityDepartmentReferenceStrategy::requirePair`, dieselbe Namens- und Aliasauflösung wie bei Zuweisungen. Unbekannte Namen verwerfen die Zeile (`REF_NOT_FOUND`).

Die Dringlichkeit ist ein Katalog, kein Zahlenfeld:

| CSV | `ClosureCareLevel` | Zuweisungs-SK in der Volumenprojektion |
|---|---|---|
| Notfallversorgung | `emergency` | nur SK1 (`urgency_code` 1) |
| Stationäre Versorgung | `inpatient` | nur SK2 |
| Ambulante Versorgung | `outpatient` | nur SK3 |
| Sonstige | `other` | SK1, SK2 und SK3 gemeinsam |

`other` wird nicht zu einer SK. In der Volumenpopulation öffnet es die ganze Fachabteilung für alle drei Dringlichkeiten (`ClosureVolumePopulation::urgenciesForCareLevel`).

Grund (`ClosureReasonCatalog`), Einrichtungstyp (`ClosureFacilityKindCatalog`, bisher `Klinik`) und die optionalen Texte Schließungseinheit, Gruppen-ID, Bemerkung und krankenhausinterne Bemerkung werden übernommen. `Eingetragen von` und `Geändert von` werden nicht gelesen. Abgeleitete Kalenderspalten der CSV werden nicht gespeichert.

### 2.3 Validierung, Rejects, wiederholte Importe

Ungültige Zeilen gehen in denselben Reject-Writer wie Zuweisungen und werden nicht gespeichert. Gültige Zeilen werden in Batches von 250 per `persist`/`flush` geschrieben. Es gibt beim Schreiben keine Deduplizierung und keinen Unique-Constraint auf dem fachlichen Schlüssel.

Ein erneuter Lauf **desselben** Import-Datensatzes löscht vorher nur die Daten dieses Imports (`ImportPreviousRunCleanupService` ruft `ImportRelatedDataCleanupService::removeAll` auf) und importiert die Datei neu. `removeAll` dispatcht dabei bereits `RebuildClosureVolumeProjection`, noch bevor die neuen Zeilen geschrieben sind. Der erfolgreiche Abschluss dispatcht die Nachricht ein zweites Mal. Ein Wiederholungslauf kann den vollen Neuaufbau also zweimal anstoßen.

Ein **zweiter** Import, also eine neue `import.id` für dasselbe Krankenhaus und denselben Zeitraum, fügt weitere Zeilen hinzu. Er ersetzt überlappende oder korrigierte Intervalle nicht. Das ist nachgewiesen im Code. In den Daten gibt es je Krankenhaus genau einen abgeschlossenen Schließungsimport, deshalb ist dieses Verhalten an echten Mehrfachimporten noch nicht beobachtet.

Die Zuweisungs-Deduplizierung (`ImportAllocationDeduplicationService`) läuft für Schließungen nicht. Die Zähler `rows_deduplicated*` der drei Schließungsimporte sind 0.

Löschen eines Imports entfernt seine `closure_interval`-Zeilen. Wenn Zuweisungen oder Schließungen gelöscht wurden, wird erneut ein voller Volumenneuaufbau beauftragt.

### 2.4 Was mit dem Zuweisungsimport geteilt wird

Geteilt: Dateiablage, CSV-Leser, Rejects, Importstatus, Berechtigung `HospitalPermission::Import`, Fachgebiets- und Fachabteilungsauflösung, Messenger, `ImportCompleted`, Aufräumen eines vorherigen Laufs.

Eigene Schließungslogik: Mapper, Kataloge, Uhr, Krankenhausprüfung, Factory, Persister, Importer, Handler. Die Zuweisungsspalte `department_was_closed` wird aus diesen Intervallen nicht abgeleitet. Die Notzuweisungsanalyse bleibt getrennt.

### 2.5 Gruppierung

Gruppen sind keine Tabelle. `ClosureTemporalSql::base` bildet sie bei jeder Abfrage:

1. Kanonische Zeile: `ROW_NUMBER()` über Krankenhaus, Fachgebiet, Fachabteilung, Beginn, Ende, Dringlichkeit, Grund, Einrichtungstyp, Schließungseinheit und Gruppen-ID. Es gewinnt die Zeile mit dem spätesten `source_changed_at`, dann der höheren `import_id`, dann der höheren `id`. Bemerkung und interne Bemerkung gehören nicht zum Schlüssel.
2. Hat die kanonische Zeile eine Gruppen-ID, ist der Ereignisschlüssel `group:{hospital_id}:{source_group_id}`.
3. Haben mindestens zwei kanonische Zeilen **ohne** Gruppen-ID im selben Krankenhaus exakt denselben Beginn und dasselbe Ende, bilden sie einen Cluster `cluster:{hospital_id}:{md5(beginn|ende)}`. Die Fachabteilung ist dabei kein Trennkriterium.
4. Sonst bleibt die Zeile ein Einzelereignis `interval:{id}`.

Die Cluster-Identität wird vor Perioden- und Dimensionsfiltern vergeben. Ein gefilterter Ausschnitt behält denselben Schlüssel.

### 2.6 Projektion und Anzeige

Nach einem abgeschlossenen Schließungsimport dispatcht `ImportCompletedSubscriber` `RebuildClosureVolumeProjection` auf `async_priority_low`. Nach einem Zuweisungsimport macht `RebuildAllocationStatsProjectionHandler` zuerst den Zuweisungsaufbau und ruft danach `ClosureVolumeProjectionRebuild::rebuild()` **synchron im selben Handler** auf. Jeder Zuweisungsimport löst damit einen vollständigen Schließungsneuaufbau aus, auch wenn sich an den Schließungen nichts geändert hat.

Der Neuaufbau schreibt `closure_volume_hour_build` und benennt sie erst um, wenn jedes Krankenhaus geschrieben wurde. Bis dahin bleibt die bisherige Tabelle sichtbar. In dieser Untersuchung war die veröffentlichte Tabelle vor dem Tausch leer.

Die Oberfläche liest zwei verschiedene Dinge:

- Kennzahlen, Ereignisse, Zeitstrahl, Dauer und Pausen lesen `closure_interval` über die CTEs in `ClosureTemporalSql`. Sie lesen die Projektion nicht.
- Die erwartete Zuweisungsmenge liest `closure_volume_hour` über `ClosureVolumeProjectionQuery` und `ClosureVolumeReadModel`.

Befehl: `app:statistics:rebuild-closure-volume-projection` (`RebuildClosureVolumeProjectionCommand`). Das Schloss `closure-volume-projection` gilt zwei Stunden.

## 3. Datenmodell und fachliche Bedeutung

### 3.1 `closure_interval`

Eine Zeile ist eine importierte Quellzeile: ein Intervall, eine Fachabteilung, eine Dringlichkeit, ein Import. Sie ist kein bereits zusammengefasstes Ereignis und kein Analyseintervall.

Identität in der Datenbank ist nur `id`. Der fachliche Schlüssel, den die Auswertung als „dieselbe Schließung“ behandelt, ist die Partition der Kanonisierung. Zwei Zeilen mit diesem Schlüssel sind exakte Duplikate; die jüngere Änderung verdeckt die ältere in der Statistik, beide bleiben gespeichert. Zwei Zeilen, die sich nur in Bemerkung oder internem Kommentar unterscheiden, fallen unter denselben Schlüssel. Zwei Zeilen mit verschiedener Dringlichkeit, verschiedenem Grund, verschiedener Einheit oder verschiedener Gruppen-ID sind verschiedene Schließungen, auch wenn Beginn und Ende gleich sind.

```sql
CREATE TABLE closure_interval (
    id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
    starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    care_level VARCHAR(32) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    facility_kind VARCHAR(32) NOT NULL,
    closure_unit VARCHAR(255) DEFAULT NULL,
    source_group_id VARCHAR(32) DEFAULT NULL,
    remark TEXT DEFAULT NULL,
    internal_remark TEXT DEFAULT NULL,
    source_recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    source_changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    hospital_id INT NOT NULL,
    import_id INT NOT NULL,
    speciality_id INT NOT NULL,
    department_id INT NOT NULL,
    PRIMARY KEY (id)
);
```

Fremdschlüssel: `hospital`, `import`, `speciality`, `department`. Kein Check, dass `ends_at > starts_at` ist; das erzwingt nur der Import. Kein Unique-Index auf dem fachlichen Schlüssel.

Indizes:

- `(hospital_id, closure_unit)`
- `(source_group_id)`
- `(import_id)`
- `(speciality_id)`, `(department_id)`
- `(starts_at, ends_at)`
- `(hospital_id, starts_at, ends_at)`

Rückverfolgung zum Import: `import_id`, `source_recorded_at`, `source_changed_at`. Die CSV-Zeilennummer wird nicht an der Schließung gespeichert, nur an einem Reject. Der ursprüngliche Dateiname liegt am Import, nicht an der Zeile. Wer eine Schließung eingetragen hat, ist nicht gespeichert.

### 3.2 Gruppen, Cluster, Projektionszeilen

| Begriff | Wo er existiert | Was eine Einheit ist |
|---|---|---|
| Schließung | `closure_interval` | eine Quellzeile bzw. ihre kanonische Vertretung |
| Gruppe | nur in SQL, Schlüssel `group:{Krankenhaus}:{Gruppen-ID}` | alle kanonischen Zeilen eines Krankenhauses mit derselben IVENA-Gruppen-ID, auch über Fachabteilungen und Dringlichkeiten |
| Cluster | nur in SQL | alle ungruppierten kanonischen Zeilen eines Krankenhauses mit exakt demselben Beginn und Ende |
| Projektionszeile | `closure_volume_hour` | eine kanonische Schließung, eine SK, eine Schicht, eine angebrochene Stunde |

### 3.3 Zeitgrenzen

Gespeicherte Zeitstempel sind `TIMESTAMP WITHOUT TIME ZONE` und werden als Wanduhr `Europe/Berlin` gelesen. Dauern rechnen in absoluten Sekunden, damit ein Tag mit Zeitumstellung 23 oder 25 Stunden haben kann.

| Stelle | Grenzen |
|---|---|
| Import | Ende strikt nach Beginn. Offene Schließungen ohne Ende sind nicht darstellbar. |
| Kennzahlen | Periode halboffen `[from, toExclusive)`. Eine Schließung zählt, wenn `starts_at < toExclusive` und `ends_at > from`. Die Dauer wird auf die Periode beschnitten. |
| Aneinanderstoßende Phasen im Dauer-Reiter | Die nächste Phase schließt an, wenn ihr Beginn kleiner oder gleich dem aktuellen Ende ist. Berührung wird zusammengelegt. |
| Zuweisungsliste auf der Detailseite | inklusiv: `starts_at <= created_at <= ends_at`, nur gleiche Fachabteilung. Kein SK-Filter, `department_was_closed` wird nicht verwendet. |
| Volumen-Stundenstücke | halboffen `[bucket_start, bucket_end)`. |
| Referenzstunde | Eine Stunde, die eine Schließung derselben Population nur anschneidet, fällt für die Referenz ganz weg. |

Aneinanderstoßende, überlappende und verschachtelte Intervalle bleiben beim Import getrennte Zeilen. Nur die Anzeige legt sie für Dauern und für die Summierung der Zuweisungslast zusammen. Es gibt keine Korrekturlogik, die ein späteres Intervall als Ersatz eines früheren erkennt.

### 3.4 Drei Arten von „doppelt“

- **Exaktes Duplikat:** gleicher fachlicher Schlüssel, zweite gespeicherte Zeile. Die Statistik zeigt eine. Heute 40 solcher Paare, alle im selben Import, alle mit unterschiedlichem `source_changed_at`.
- **Parallele Dringlichkeit:** gleicher Ort und gleiches Intervall, andere `care_level`. Das sind verschiedene Zeilen und verschiedene kanonische Schließungen. Die Volumenprojektion rechnet sie getrennt. Die Ereignisliste fasst sie nur zusammen, wenn Gruppen-ID oder exakter Zeitstempel sie zu Gruppe oder Cluster machen.
- **Zeitliche Überschneidung:** gleiche Fachabteilung, ungleiche Grenzen, die sich schneiden, eine die andere enthält, oder sie stoßen aneinander. Das sind fachlich verschiedene Intervalle. Ob sie eine Verlängerung, eine Korrektur oder zwei Schließungen sind, steht in den Daten nicht.

### 3.5 `closure_volume_hour`

```sql
CREATE TABLE closure_volume_hour (
    id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
    closure_interval_id INT NOT NULL,
    hospital_id INT NOT NULL,
    department_id INT NOT NULL,
    urgency_code SMALLINT NOT NULL,
    stratum VARCHAR(16) NOT NULL,
    bucket_start TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    bucket_end TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    in_closure BOOLEAN NOT NULL,
    observed_area NUMERIC(14, 4),
    expected_area NUMERIC(14, 4),
    observed_hospital NUMERIC(14, 4),
    expected_hospital NUMERIC(14, 4),
    evaluable_seconds INT NOT NULL,
    bucket_seconds INT NOT NULL,
    reference_slot_count INT NOT NULL,
    reference_assignment_count INT NOT NULL,
    reference_mode VARCHAR(32) NOT NULL,
    influenced BOOLEAN NOT NULL,
    quality VARCHAR(32) NOT NULL,
    reference_cutoff TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (id)
);
```

Eindeutig ist `(closure_interval_id, urgency_code, stratum, bucket_start)`. Weitere Indizes: `(hospital_id, department_id, urgency_code, stratum, bucket_start)` und `(closure_interval_id, stratum, in_closure, bucket_start)`. Es gibt keinen Fremdschlüssel auf `closure_interval`. Die Tabelle hat keine Methodenversion.

`stratum` ist `base` (alle Zuweisungen der Dringlichkeit), `resus` (`requires_resus`) oder `cathlab` (`requires_cathlab`). Die drei Schichten werden nicht addiert. `urgency_code` 1, 2 und 3 sind SK1, SK2 und SK3.

## 4. Beispiele und Mengen

Alle folgenden Zahlen sind am 5. Oktober 2026 aus `app` gelesen. Die Kanonisierung in den Abfragen ist dieselbe Partition wie im Code.

### 4.1 Größen

| Menge | Wert |
|---|---|
| Abgeschlossene Schließungsimporte | 3, je einer je Krankenhaus |
| Gemeldete CSV-Zeilen / gespeichert / verworfen | 36.195 / 35.989 / 206 |
| Beim Import dedupliziert | 0 |
| Kanonische Schließungen | 35.949 |
| Verdeckte exakte Dubletten | 40 |
| Krankenhäuser / Fachabteilungen / Fachgebiete | 3 / 103 / 19 |
| Frühester Beginn / spätestes Ende | 2026-01-01 00:10 / 2026-10-01 16:00 |
| Zeilen mit Gruppen-ID / ohne | 17.266 / 18.683 |
| IVENA-Gruppen | 882 |
| Analytische Cluster | 671, darin 18.664 Zeilen |
| Einzelereignisse | 19 |
| Projektionszeilen | 3.554.670 |
| Verhältnis kanonische Zeile zu Projektionszeile | 1 zu 98,9 |
| Zuweisungen in `allocation_stats_projection` | 2.288.532 |

Die 19 Einzelereignisse sind der Rest. Fast jede kanonische Zeile hängt entweder an einer IVENA-Gruppen-ID oder teilt sich Beginn und Ende mit mindestens einer anderen ungruppierten Zeile desselben Krankenhauses.

Dringlichkeit der kanonischen Zeilen:

| `care_level` | Zeilen | Anteil |
|---|---:|---:|
| `inpatient` (SK2) | 15.747 | 43,8 % |
| `outpatient` (SK3) | 10.304 | 28,7 % |
| `emergency` (SK1) | 9.664 | 26,9 % |
| `other` | 234 | 0,7 % |

Je Krankenhaus: Klinik A 13.124 kanonische Zeilen, Klinik B 7.718, Klinik C 15.107. Die größten Fachabteilungen über alle drei Kliniken sind Infektiologie (2.832), Innere Allgemein - Isolierung (2.619) und Allgemeine Innere Medizin (1.997).

Dauer der kanonischen Intervalle: Median 180 Minuten, Mittel etwa 390, Minimum 1, Maximum 1.440. Keine gespeicherte Schließung ist länger als 24 Stunden. 29.315 von 35.949 beginnen oder enden nicht auf einer vollen Stunde; alle liegen auf einer vollen Minute.

Rejects der drei Importe, nach Meldungstext zusammengefasst: 115 unbekannte Fachabteilung „Alterstraumatologie“, 40 `DURATION_MISMATCH` mit gemeldeten 1.500 Minuten gegen 1.440 verstrichene Minuten, weitere unbekannte Fachabteilungen (eCPR-Zuverlegung 19, Neuroradiologie 13, Kernspintomographie 7) und einzelne ungültige Felder. Die 1.500-gegen-1.440-Fälle sind nicht gespeichert. Ob das eine systematische Quellenabweichung oder eine Zeitumstellung ist, ist offen; der Import akzeptiert die Zeile in beiden Fällen nicht.

### 4.2 Identisches Intervall, mehrere Dringlichkeiten

15.536 verschiedene Slots `(Krankenhaus, Fachabteilung, Beginn, Ende)`:

| Dringlichkeiten im Slot | Slots | kanonische Zeilen |
|---|---:|---:|
| SK1 + SK2 + SK3 | 5.580 | 18.322 |
| nur SK2 + SK3 | 4.077 | 8.412 |
| nur SK1 + SK2 | 2.738 | 6.058 |
| nur SK2 | 2.247 | 2.254 |
| nur SK1 | 658 | 662 |
| nur `other` | 230 | 234 |
| nur SK3 | 5 | 5 |
| SK1 + SK3 | 1 | 2 |

12.396 Slots haben mehr als eine Dringlichkeit. Davon haben 12.387 denselben Grund, alle denselben Einrichtungstyp, 12.394 dasselbe Fachgebiet. 5.518 teilen eine Gruppen-ID, 6.791 sind vollständig ohne Gruppen-ID, 87 mischen mehrere Gruppen-IDs oder gruppierte und ungruppierte Zeilen.

Schließungseinheit innerhalb der Mehrfach-Slots:

| Muster | Slots |
|---|---:|
| alle leer | 2.050 |
| genau eine gemeinsame Einheit | 9.300 |
| eine Einheit und zusätzlich leer | 64 |
| mehrere verschiedene Einheiten | 982 |

11.345 Slots stimmen bei Grund, Einrichtungstyp, Fachgebiet, Einheit und Gruppenidentität überein. Das ist die Menge, in der eine Zusammenfassung auf einen Datensatz mit mehreren Dringlichkeiten die gespeicherten Merkmale nicht verwirft.

Beispiel, Allgemeine Innere Medizin, Klinik A, ohne Gruppen-ID, Grund `not_specified`:

| Dringlichkeit | Beginn | Ende |
|---|---|---|
| SK1 `emergency` | 2026-01-01 06:40 | 2026-01-01 11:00 |
| SK2 `inpatient` | 2026-01-01 06:40 | 2026-01-01 11:00 |
| SK3 `outpatient` | 2026-01-01 06:40 | 2026-01-01 11:00 |

Beispiel für nur zwei Kategorien, Klinik C, Grund `emergency_department_overload`: SK2 und SK3 von 2026-01-01 18:10 bis 21:10, ohne SK1. Eine Regel „immer alle drei SK“ würde hier eine Dringlichkeit erfinden.

Von den 882 Gruppen mit ID haben 876 mehrere Dringlichkeiten, 792 mehrere Fachabteilungen und nur 28 mehr als ein unterschiedliches Zeitintervall. Der Median liegt bei 13,5 Kindzeilen, das Maximum bei 272. Eine Gruppen-ID bündelt in diesen Daten vor allem dieselbe Uhrzeit über Fachabteilungen und Dringlichkeiten, nicht eine Kette aufeinanderfolgender Schließungen.

### 4.3 Nur teilweise gleiche Intervalle

Paare ungleicher Zeitgrenzen derselben Fachabteilung, die sich berühren oder schneiden:

| Beziehung | Paare |
|---|---:|
| eines enthält das andere | 2.637 |
| teilweise Überlappung | 2.359 |
| stoßen exakt aneinander | 1.681 |
| gleicher Beginn, eines endet früher | 171 |

Ohne die reine Berührung bleiben 5.167 überlappende ungleiche Paare. 3.001 davon haben dieselbe Menge von Dringlichkeiten, 2.166 eine andere.

Zusätzlich, nur innerhalb derselben Dringlichkeit und gegen die zeitlich vorherige Zeile derselben Fachabteilung: 7.088 Überschneidungen, 2.582 exakte Anschlüsse, 3.840 Lücken von höchstens einer Stunde. Eine Lücke von höchstens einer Minute kam nicht vor.

Beispiel, Allgemeine Innere Medizin, Klinik C, gleicher Grund `emergency_department_overload`:

- 14:00–18:00 mit SK2 und SK3
- 16:20–18:20 mit SK1, SK2 und SK3

Die zweite Schließung beginnt während der ersten und endet später, und sie betrifft eine andere Dringlichkeitsmenge. Eine Vereinigung der Intervalle würde sowohl die Zeitgrenzen als auch die Tatsache verlieren, dass SK1 erst ab 16:20 gilt.

### 4.4 Mehrfachimporte

Nachgewiesen ist nur die Wiederholung innerhalb einer Datei. Alle 40 doppelten Schlüssel stammen aus genau einem Import und haben zwei verschiedene `source_changed_at`. Ein Beispiel, Klinik C, Allgemeine Augenheilkunde - Isolierung, SK1, 2026-03-16 08:50 bis 2026-03-17 08:50: zwei Zeilen, Änderungszeitpunkte 08:55:14 und 08:55:30, interne Bemerkung unterschiedlich, öffentliche Bemerkung gleich. Die Auswertung behält die spätere Zeile. Die frühere interne Bemerkung bleibt in der Tabelle, erscheint in der Statistik aber nicht.

Ein Import derselben Datei als neuer Import-Datensatz oder eine nachträgliche Korrektur mit verschobenem Ende ist in den Daten nicht vorhanden. Beides würde nach heutigem Code zusätzliche Zeilen erzeugen.

### 4.5 Diagnoseabfragen

Kanonische Menge:

```sql
CREATE TEMP TABLE canonical_closure AS
SELECT *
FROM (
    SELECT ci.*,
           ROW_NUMBER() OVER (
               PARTITION BY hospital_id, speciality_id, department_id, starts_at, ends_at,
                            care_level, reason, facility_kind,
                            COALESCE(closure_unit, ''), COALESCE(source_group_id, '')
               ORDER BY source_changed_at DESC, import_id DESC, id DESC
           ) AS canonical_rank
    FROM closure_interval ci
) ranked
WHERE canonical_rank = 1;
```

Identische Intervalle mit mehreren Dringlichkeiten:

```sql
SELECT array_agg(DISTINCT care_level ORDER BY care_level) AS levels,
       COUNT(*) AS slots
FROM canonical_closure
GROUP BY hospital_id, department_id, starts_at, ends_at
ORDER BY slots DESC;
```

Die äußere Gruppierung muss die Slot-Gruppe noch einmal zusammenfassen; die im Bericht verwendeten Kombinationen entstanden daraus mit `GROUP BY levels`.

Überlappende ungleiche Grenzen:

```sql
WITH bounds AS (
    SELECT DISTINCT hospital_id, department_id, starts_at, ends_at
    FROM canonical_closure
)
SELECT COUNT(*)
FROM bounds a
JOIN bounds b
  ON a.hospital_id = b.hospital_id
 AND a.department_id = b.department_id
 AND (a.starts_at, a.ends_at) < (b.starts_at, b.ends_at)
 AND a.starts_at < b.ends_at
 AND b.starts_at < a.ends_at;
```

## 5. Projektionsberechnung

### 5.1 Korn und Kennzahlen

Jede gespeicherte Zeile gehört zu einer kanonischen Schließung und beschreibt ein Stundenstück:

- `in_closure`: das Stück liegt im halboffenen Intervall der Schließung. Stücke davor und danach sind `false`.
- `observed_area`: Zuweisungen der Fachabteilung in diesem Stück, sonst `NULL`, wenn der Kalendertag für das Krankenhaus nicht abgedeckt ist.
- `expected_area`: Stundensatz der Referenz mal Sekunden des Stücks, sonst `NULL`, wenn die Referenz nicht ausreicht.
- `observed_hospital` und `expected_hospital`: dasselbe für das ganze Krankenhaus, unabhängig von der Fachabteilung.
- `evaluable_seconds`: Sekunden des Stücks, wenn der Tag abgedeckt ist, sonst 0.
- `reference_cutoff`: Beginn der Schließung. Die Referenz darf nur davor liegen.
- `influenced`: eine andere Schließung derselben Fachabteilung und derselben Dringlichkeit schneidet ein Vorher- oder Nachher-Stück. Stunden innerhalb der Schließung selbst sind nicht beeinflusst.
- `quality`: `reliable` (Wochentag und Stunde), `limited` (Wochentag und Tageszeitblock), `insufficient` (weniger als vier Referenzstunden), `incomplete` (Tag ohne Zuweisungsabdeckung). `not_applicable` und `ongoing` entstehen erst beim Lesen, nicht in der Tabelle.

Die drei Schichten sind im fertigen Bestand zeilengleich: je 328.328 Zeilen für SK1, 520.887 für SK2 und 335.675 für SK3, und das jeweils für `base`, `resus` und `cathlab`. SK2 hat mehr Zeilen, weil es mehr Schließungen dieser Dringlichkeit gibt, nicht weil die Stunde anders geschnitten würde.

Qualität des fertigen Bestands:

| Qualität | Referenzmodus | Zeilen |
|---|---|---:|
| `reliable` | `weekday_hour` | 2.184.312 |
| `incomplete` | `weekday_hour` | 693.624 |
| `limited` | `day_time_bucket` | 439.479 |
| `insufficient` | `insufficient` | 213.213 |
| `incomplete` | `day_time_bucket` | 24.042 |

`expected_area IS NULL` trifft genau die 213.213 unzureichenden Zeilen. `observed_area IS NULL` trifft genau die 717.666 unvollständigen Zeilen. Null Zuweisungen und fehlende Daten sind damit in der Tabelle unterscheidbar: ein abgedeckter Tag ohne Zuweisung der Fachabteilung speichert 0, ein Tag ohne jede Zuweisung des Krankenhauses speichert `NULL` und `evaluable_seconds = 0`. Ein Krankenhaus-Tag ganz ohne Zuweisungen ist von einem fehlenden Import nicht unterscheidbar. Das ist so implementiert und in der Produktdokumentation beschrieben.

### 5.2 Fenster

`ClosureVolumeReferenceConfig::CONTEXT_HOURS` ist 12. `WINDOW_HOURS` ist 1, 2, 3, 6 und 12. Die Konfiguration `app.closure_volume` setzt acht Referenzwochen und mindestens vier Vergleichsstunden.

Der Planer schneidet drei Bereiche, jeweils bis „jetzt“:

1. die 12 Stunden vor Beginn, `in_closure = false`
2. Beginn bis Ende, `in_closure = true`, bei noch laufender Schließung bis jetzt
3. die 12 Stunden nach dem Ende, nur wenn die Schließung bereits geendet hat

`ClosureVolumeClock::split` zerlegt diese Bereiche an den vollen Stunden von Europe/Berlin. Ein Stück, das um 10:40 beginnt, ist 10:40–11:00 und dann volle Stunden.

Die Detailansicht summiert daraus die Fenster 1, 2, 3, 6 und 12 Stunden vor und nach der Schließung sowie die Schließung selbst. Nachher-Fenster einer noch laufenden Schließung werden als `ongoing` ausgeblendet. Für den Vergleich vor/nach wird das längste Fenster genommen, das vollständig, nicht beeinflusst, berechenbar und mindestens `limited` ist. Beeinflusste, unvollständige oder nicht berechenbare Fenster bleiben sichtbar und fallen aus dem Vergleich.

Die Übersicht auf der Übersichtsseite summiert nur die Stunden innerhalb der Schließung, beschnitten auf die gewählte Periode und auf jetzt. Überlappende Schließungen derselben Population (Krankenhaus, Fachabteilung, Dringlichkeit, Schicht, Stundenbeginn) werden vor der Summe auf eine Zeile reduziert. Gewonnen hat die Zeile mit dem früheren `reference_cutoff`, also die früher beginnende Schließung. Ereignissummen sind deshalb nicht die Summe der Kinder.

### 5.3 Referenz

Für eine Schließung endet die Referenz an ihrem Beginn. Verglichen werden bis zu acht vorhergehende Wochen derselben Wochentagsstunde. Eine Stunde zählt nur, wenn sie vollständig vor dem Beginn liegt, der Kalendertag abgedeckt ist und keine Schließung derselben Population diese Uhrstunde schneidet. Eine teilweise geschlossene Referenzstunde entfällt ganz. Reichen vier solcher Stunden nicht, wird der Sechsstundenblock derselben Tageszeit verwendet (Nacht 0–5, Morgen 6–11, Nachmittag 12–17, Abend 18–23). Darunter fehlt die Erwartung, sie wird nicht als Null gespeichert.

Der Stundensatz mal den echten Sekunden des Stücks ergibt die Erwartung. Zwölf Stunden Schließung sind damit nicht die Hälfte eines durchschnittlichen Tages.

Zuweisungen werden über `allocation_stats_projection.created_at` gezählt, gefiltert nach Krankenhaus und, für die Fachabteilungsreihe, nach `department_id`. Die Dringlichkeit ist `urgency_code`. Das Fachgebiet ist nur ein Anzeigename. Schockraum und Herzkatheter sind die booleschen Spalten `requires_resus` und `requires_cathlab`.

### 5.4 Weitere Schließungen und Abstände

`influenced` prüft Nachbarschließungen derselben Fachabteilung, deren Dringlichkeitsmenge die Stunde schneidet. Die Nachbarn werden je Fachabteilung im Speicher gehalten. 1.228.356 Projektionszeilen sind beeinflusst. Das sind Vorher- und Nachher-Stunden; die 780.618 Stunden innerhalb von Schließungen tragen das Flag nicht. Ein großer Teil der Kontextfenster ist damit von einer anderen Schließung derselben Population überlagert und fällt aus dem Vorher-nachher-Vergleich.

Der Abstand zur vorherigen oder nächsten Schließung wird in der Projektion nicht gespeichert. `ClosureDurationLoadCalculator` legt Intervalle je Krankenhaus zu Phasen zusammen und bildet eine Pause nur aus der Lücke zwischen zwei Phasen, wenn diese Lücke vollständig in der geschätzten Importabdeckung liegt. Das ist eine Krankenhaus-Phase, nicht der Abstand zweier Fachabteilungsschließungen, und es ist eine Kennzahl der Daueransicht, keine Projektionsspalte.

### 5.5 Verhalten bei Importänderungen

Es gibt keinen inkrementellen Abgleich. Jeder der folgenden Anlässe baut alle Krankenhäuser neu:

- abgeschlossener Schließungsimport
- abgeschlossener Zuweisungsimport, nach dem Zuweisungsaufbau, im selben Worker
- Löschen eines Imports, der Zuweisungen oder Schließungen entfernt hat
- der Console-Befehl
- der Aufräumschritt eines Wiederholungslaufs, zusätzlich zum Abschluss

Der Neuaufbau liest die kanonischen Schließungen je Krankenhaus, aggregiert die Zuweisungen des relevanten Zeitraums einmal je Krankenhaus auf die Stunde, fragt Teilintervalle in Paketen von 80 ab und schreibt Projektionszeilen in Paketen von 200. Die Indizes der Zieltabelle werden vor dem Laden angelegt. Erst wenn alle Krankenhäuser geschrieben sind, werden die Tabellen in einer Transaktion getauscht. Ein Abbruch davor lässt die bisher veröffentlichte Tabelle stehen und verwirft die Seitentabelle im `catch`. Ein hartes Prozessende kann die Seitentabelle liegen lassen; das wurde in diesem Lauf nicht beobachtet, weil der Lauf mit dem Tausch endete.

### 5.6 Repräsentative Implementierung

Kanonische Auswahl im Neuaufbau:

```sql
SELECT id, hospital_id, department_id, care_level, starts_at, ends_at
FROM (
    SELECT ci.*,
           ROW_NUMBER() OVER (
               PARTITION BY hospital_id, speciality_id, department_id, starts_at, ends_at,
                            care_level, reason, facility_kind, COALESCE(closure_unit, ''),
                            COALESCE(source_group_id, '')
               ORDER BY source_changed_at DESC, import_id DESC, id DESC
           ) AS canonical_rank
    FROM closure_interval ci
    WHERE ci.hospital_id = :hospital
) ranked
WHERE canonical_rank = 1
```

Die eine Zuweisungsaggregation je Krankenhaus:

```sql
SELECT department_id, urgency_code,
       to_char(date_trunc('hour', created_at), 'YYYY-MM-DD HH24:00:00') AS hour_start,
       COUNT(*)::int AS base_count,
       COUNT(*) FILTER (WHERE requires_resus IS TRUE)::int AS resus_count,
       COUNT(*) FILTER (WHERE requires_cathlab IS TRUE)::int AS cathlab_count
FROM allocation_stats_projection
WHERE hospital_id = :hospital
  AND created_at >= :from
  AND created_at < :to
GROUP BY department_id, urgency_code, date_trunc('hour', created_at)
```

Die Referenz wird danach nicht in SQL gezählt. `ClosureVolumeReferenceCalendar::tally` läuft in PHP über bis zu acht Wochen und die sechs Stunden des Tageszeitblocks. `referenceRates` ruft `tally` für jede dieser sechs Stunden einzeln auf, obwohl die Blocksumme für alle sechs identisch ist. Der Cache-Schlüssel enthält Beginn, Fachabteilung, Dringlichkeit, Schicht, Wochentag und Stunde. Parallele SK-Zeilen teilen ihn nicht, weil die Dringlichkeit verschieden ist. Innerhalb einer Fachabteilung teilen ihn nur Schließungen mit demselben Beginn. In Klinik A haben 13.124 kanonische Zeilen 11.495 verschiedene Paare aus Fachabteilung, Dringlichkeit und Beginn. Der Cache trifft also nur einen kleinen Teil.

Eine weitere Eigenschaft des Planers ist für die Zahlen relevant, nicht nur für die Laufzeit. `observed()` merkt sich eine Uhrstunde, sobald ein Stück sie gesehen hat, und gibt für ein späteres Stück derselben Uhrstunde 0 zurück, nicht `NULL`. Vorher-Bereich und Schließung teilen sich die Randstunde, wenn die Schließung nicht zur vollen Stunde beginnt. Die Zuweisungen dieser Stunde landen dann vollständig im früheren Stück, das spätere Stück wird eine echte Null. Die Erwartung bleibt anteilig nach Sekunden. Das ist im Code so geschrieben. Ob die Randstunde dadurch die Abweichung an Beginn und Ende verzerrt, ist eine begründete Vermutung; sie wurde an den Produktivzahlen nicht nachgerechnet.

## 6. Performance

### 6.1 Was gemessen wurde

Während der Untersuchung lief der Neuaufbau bereits. Prozessstart laut Prozessliste: 13:54 Uhr MESZ. Um 14:27 Uhr war `closure_volume_hour_build` verschwunden und `closure_volume_hour` enthielt 3.554.670 Zeilen für alle 35.949 kanonischen Schließungen. Die Wandzeit dieses Laufs ist damit höchstens etwa 33 Minuten. Die genaue Endsekunde wurde nicht protokolliert.

Eine Stichprobe während Klinik C (14.849 von später 15.107 Schließungen schon geschrieben) zeigte den PHP-Prozess bei etwa 87 Prozent CPU und 27 Minuten verbrauchter CPU-Zeit. `pg_stat_activity` hatte in dieser Stichprobe keine aktive Abfrage. Der Prozess rechnete zu diesem Zeitpunkt in PHP, nicht in einer langen SQL-Anweisung.

`EXPLAIN (ANALYZE, BUFFERS)` der Stundenaggregation für Klinik A, Zeitraum 1. November 2025 bis 5. Oktober 2026:

- Bitmap Index Scan auf `idx_asp_hospital_created (hospital_id, created_at)`
- 18.999 Zuweisungszeilen, 17.373 Stundengruppen
- Ausführungszeit 11,4 ms, 427 Buffer-Reads

Dieselbe Abfrageform führt der Neuaufbau einmal je Krankenhaus aus. Klinik A hat insgesamt 216.933 Zuweisungen; im für die Referenz relevanten Fenster sind es die genannten 18.999. Die drei Kliniken haben 216.933, 191.424 und 137.119 Zuweisungen.

Ein Paket von 80 Teilintervallen, analog zu `queryPartialChunk`, lief als Nested Loop über denselben Index in 24 ms (151 Treffer). Der Planer schätzte 1,9 Millionen Zeilen; die Ausführung war trotzdem kurz. JIT-Kompilierung kostete in diesem einen Plan etwa 12 ms.

Ein isolierter Mikrobenchmark von `DateTimeImmutable::createFromFormat` auf diesem Rechner: 2.000.000 Aufrufe in 3,25 Sekunden, etwa 615.000 Aufrufe je Sekunde. Das ist nicht der Neuaufbau, nur die Kosten einer Operation, die `tally` je Vergleichsstunde ausführt.

Nicht gemessen: ein Profiler über den ganzen Lauf, die reine Insert-Zeit der 3,55 Millionen Zeilen, und die früher beobachteten knapp 50 Minuten. Es gibt dafür in diesem Workspace kein Laufprotokoll.

### 6.2 Einordnung

| Ursache | Evidenz | Erwartete Bedeutung für einen vollen Lauf |
|---|---|---|
| PHP-Referenzschleife je Schließung, Dringlichkeit, Schicht, Tageszeitblock und Stunde, mit erneutem Wochenkalender und sechsfacher Blockrechnung | Codepfad plus CPU-Stichprobe ohne aktive SQL-Abfrage. Grobe Ableitung aus Aufrufstruktur und DateTime-Kosten: viele Minuten, nicht der einzige Block. | Hoch |
| Dreifache Speicherung `base` / `resus` / `cathlab` und getrennte Kalenderläufe dafür | Zeilenzahlen sind exakt dreifach. Die Schleife iteriert die drei Schichten getrennt. | Hoch für Zeilenzahl und einen festen Faktor der CPU-Zeit |
| 12 Stunden Kontext vor und nach jeder Schließung, auch wenn viele Fenster später als beeinflusst gelten | 2,77 Millionen Kontextzeilen gegen 0,78 Millionen Stunden innerhalb der Schließung | Hoch für Zeilenzahl und Insert-Volumen |
| Indizes werden vor dem Laden angelegt, Inserts zu 200 Zeilen | Code. Insert-Anteil wurde nicht isoliert gestoppt. Die CPU-Stichprobe lag nicht in einem Insert-Wait. | Mittel |
| Jeder Zuweisungsimport und ein Wiederholungslauf stoßen einen oder zwei volle Läufe an | Code. In dieser Sitzung ein Lauf. | Hoch für die beobachtete Wartezeit in der Anwendung, nicht für die Dauer eines einzelnen Laufs |
| N+1-ORM auf Zuweisungen | Nicht vorhanden. Der Neuaufbau liest per DBAL in eine PHP-Map. | Keine |
| Nachbarschleife je Fachabteilung | Vorhanden, eine Fachabteilung hat bis zu 1.535 Schließungen. Das ist quadratisch, aber klein gegen die Datumsrechnung. | Gering |
| Fehlender Index auf der Zuweisungsaggregation | Der passende Index existiert und wird benutzt. 11 ms je Krankenhaus. | Keine für den Stundenscan |
| Teilintervall-Abfragen | 24 ms je 80er-Paket, Index wird benutzt. Bei vielen eindeutigen Grenzen mehrere Sekunden, nicht eine Dreiviertelstunde. | Gering bis mittel |
| Die bloße Zahl von 36.000 Schließungen oder 2,3 Millionen Zuweisungen | Die Zuweisungen werden je Krankenhaus einmal aggregiert. Die Schließungszahl erklärt die Laufzeit erst zusammen mit der Arbeit je Schließung. | Nicht als alleinige Ursache |

ORM-Hydrierung der Schließungsentitäten findet im Neuaufbau nicht statt. Transaktionsgrenzen: die Insert-Batches sind einzelne autocommit-Anweisungen; der Tabellentausch ist eine Transaktion. Der Neuaufbau selbst ist nicht eine einzige lange Transaktion über alle Krankenhäuser.

## 7. Bewertung möglicher Veränderungen

Keine dieser Optionen ist umgesetzt. Projektionen dürfen in der Beta vollständig neu entstehen. Eine Versionsspalte ist dafür nicht nötig.

### 7.1 Modell behalten, Projektion billiger rechnen

Die Quellzeile bleibt eine Fachabteilung und eine Dringlichkeit. Geändert wird nur der Neuaufbau: Referenz je Tageszeitblock einmal rechnen, die drei Schichten aus derselben Kalenderwanderung ableiten, Indizes nach dem Laden bauen, nur betroffene Krankenhäuser neu schreiben, den Neuaufbau nicht zweimal je Wiederholungslauf und nicht synchron in jedem Zuweisungsimport auslösen.

| | |
|---|---|
| Integrität | unverändert |
| Rückverfolgung | unverändert |
| Import und Deduplizierung | unverändert |
| Statistik | dieselben Definitionen, sofern die Randstunden-Zählung bewusst gleich bleibt |
| Performance | adressiert die gemessene CPU-Schleife und die mehrfachen Vollläufe |
| Migration | kein Datenumbau der Schließungen; eine neue Projektion genügt |

Das beseitigt nicht die doppelte fachliche Bedeutung paralleler SK-Zeilen und nicht die 5.167 ungleichen Überlappungen.

### 7.2 Ein Schließungsereignis mit mehreren Dringlichkeiten

Eine Analysezeile je `(Krankenhaus, Fachabteilung, Beginn, Ende)` trägt eine Menge von Dringlichkeiten.

Verlustfrei, nach den heutigen Spalten, sind die 11.345 Mehrfach-Slots mit gleichem Grund, Einrichtungstyp, Fachgebiet, gleicher Einheit und gleicher Gruppenidentität. Das deckt die 5.580 vollständigen SK1–SK3-Slots nur soweit sie diese Merkmale teilen, und es deckt auch Paare wie SK2+SK3 ab. Die Menge der Dringlichkeiten muss erhalten bleiben. „Immer alle drei“ wäre bereits bei 4.077 Slots nur mit SK2 und SK3 falsch.

Nicht verlustfrei:

- 982 Slots mit mehreren Schließungseinheiten und 64 mit Einheit und leerem Wert
- 9 Slots mit verschiedenem Grund, 2 mit verschiedenem Fachgebiet, 87 mit gemischter Gruppen-ID
- jede ungleiche Zeitgrenze, also die 5.167 überlappenden Paare
- die 234 `other`-Zeilen, die fachlich keine SK sind und in der Projektion heute alle drei Dringlichkeiten öffnen

| | |
|---|---|
| Integrität | gut, wenn die Quellzeilen bleiben und nur die Analysezeile zusammenfasst; schlecht, wenn die drei Quellzeilen ersetzt werden und Einheit oder Bemerkung kollidieren |
| Rückverfolgung | bleibt, solange `import_id` und die Quell-IDs an der Analysezeile hängen |
| Import | kann weiter eine Zeile je CSV-Zeile schreiben |
| Statistik | Ereigniszahlen fallen, wenn drei SK nicht mehr drei Schließungen sind. Summierte Dauer zählt Parallelen heute mehrfach, die vereinigte Dauer nicht. Die Definition muss neu festgelegt werden. Die Volumenprojektion würde je Population statt je Quellzeile entstehen und deutlich weniger Zeilen schreiben. |
| Performance | weniger Projektionszeilen, weil SK1–SK3 nicht dreimal dieselben Stunden planen. Die drei Dringlichkeitsreihen bleiben nötig, wenn die Ansichten getrennt bleiben. |
| Migration | Ableitung aus dem Bestand ist möglich. Die Ausnahmen brauchen eine sichtbare Regel, kein stilles Verwerfen. |

### 7.3 Importzeilen und Analyseintervalle trennen

`closure_interval` bleibt das unveränderte Importprotokoll. Eine zweite Struktur hält das normalisierte Analyseintervall: kanonisch, mit Dringlichkeitsmenge, nur dort zusammengefasst, wo die Merkmale übereinstimmen. Konflikte und ungleiche Überlappungen bleiben eigene Zeilen oder eine Ausnahmeklasse.

| | |
|---|---|
| Integrität | Quelldaten und Auswertung können sich nicht mehr gegenseitig „wegdeduplizieren“. Die heutige Leselogik, die eine jüngere Dublette nur verdeckt, wird explizit. |
| Rückverfolgung | stark, weil Bemerkung, interne Bemerkung und jeder Import erhalten bleiben |
| Import | unverändert roh; Deduplizierung wird ein nachgelagerter Schritt mit nachvollziehbarer Regel |
| Statistik | liest die Analyseintervalle. Die Projektion hängt daran statt an jeder Rohzeile. |
| Performance | die Projektion wird kleiner, sobald parallele SK nicht mehr jede für sich 12+12 Stunden Kontext schreiben |
| Migration | neue Tabelle oder Sicht, einmalig aus dem Bestand füllbar, Projektion neu. Die Rohdaten bleiben. |

Das ist der kleinste Schritt, der die Rückverfolgung nicht mit der statistischen Stückelung vermischt.

### 7.4 Überlappende Intervalle zusammenlegen oder zerschneiden

Zusammenlegen würde aus den beiden Allgemeinen-Innere-Intervalle 14:00–18:00 und 16:20–18:20 ein Intervall 14:00–18:20 machen. SK1 gälte dann fälschlich schon ab 14:00. Zerschneiden in 14:00–16:20, 16:20–18:00 und 18:00–18:20 erhielte die Dringlichkeitsmenge je Stück, erzeugt aber Intervalle, die so nicht importiert wurden, und zerreißt Grund, Einheit und Gruppen-ID, sobald die Stücke sich darin unterscheiden.

Aneinanderstoßende Intervalle (1.681 Paare, zusätzlich 2.582 Anschlüsse innerhalb derselben Dringlichkeit) wären nur dann eine Schließung, wenn fachlich feststeht, dass eine Berührung keine neue Meldung ist. Das ist offen. Die Daueransicht legt berührende Phasen bereits für die Pausenrechnung zusammen, die Ereignisliste tut das nicht.

| | |
|---|---|
| Integrität | hoch nur als abgeleitete Sicht; als Ersatz der Quellzeile geht die ursprüngliche Meldung verloren |
| Statistik | Vereinigung verändert Dauern und Abstände. Zerschneiden verändert Anzahlen und macht Vorher/Nachher-Fenster an künstlichen Grenzen. |
| Performance | weniger oder mehr Projektionsanker, je nach Richtung. Nicht der erste Hebel. |
| Migration | regelabhängig und ohne fachliche Entscheidung nicht eindeutig |

## 8. Offene Entscheidungen und Empfehlung

Offen, und durch die Daten nicht ersetzbar:

1. Ist ein identisches Intervall in SK1, SK2 und SK3 eine Schließung mit drei Dringlichkeiten oder drei Schließungen zur selben Uhrzeit? Die Datei liefert drei Zeilen. Grund, Fachgebiet und meist auch Gruppen-ID oder deren Fehlen sprechen für eine gemeinsame Meldung. 982 Slots mit verschiedenen Einheiten sprechen dagegen, das für alle Fälle zu behaupten.
2. Ist eine abweichende Schließungseinheit eine andere organisatorische Schließung oder nur ein abweichend gepflegtes Etikett?
3. Sind ungleiche Überlappungen und Verschachtelungen Korrekturen, Verlängerungen oder parallele Meldungen? Der Import behandelt sie als zusätzliche Zeilen. Eine jüngere `source_changed_at` gewinnt nur bei exakt gleichem Schlüssel.
4. Soll ein Cluster weiterhin alle Fachabteilungen eines Krankenhauses mit demselben Zeitstempel zu einem Ereignis machen? Heute ja. 671 Cluster enthalten 18.664 Zeilen; das ist mehr als drei SK einer Fachabteilung.
5. Soll ein späterer Import mit verschobenem Ende den älteren Zeitraum ersetzen? Heute nicht. Ein solcher Fall liegt in den Daten nicht vor.
6. Ist das beobachtete Maximum von 24 Stunden eine Eigenschaft der Schließungen oder der exportierten Liste? Die 40 verworfenen Zeilen mit 1.500 gegen 1.440 Minuten sind ein Hinweis auf eine Quellenabweichung an genau dieser Grenze, kein Beweis.

Empfehlung für die nächste Diskussion: zuerst Importprotokoll und Analyseintervall trennen, und die Zusammenfassung auf identische Intervalle beschränken, deren Grund, Einrichtungstyp, Fachgebiet, Schließungseinheit und Gruppenidentität übereinstimmen. Die Dringlichkeiten werden eine Menge, einschließlich der unvollständigen Paare SK2+SK3 und SK1+SK2. Slots mit Konflikten und alle ungleichen Überlappungen bleiben getrennt und werden als Ausnahme gezählt, nicht still vereinigt. `other` bleibt außerhalb der SK-Menge.

Unabhängig davon kann der Neuaufbau billiger werden, ohne diese fachliche Entscheidung: die Referenz je Block einmal rechnen, Indizes nach dem Laden erzeugen, nicht jedes Krankenhaus bei jedem Zuweisungsimport neu schreiben und einen Wiederholungslauf nicht zweimal anstoßen. Die Beta erlaubt danach einen vollständigen Neuaufbau. Eine Versionierung der Projektion ist dafür nicht erforderlich.

Nicht als erstes tun: überlappende Intervalle zu einem Zeitraum verschmelzen oder an den Schnittpunkten zerschneiden. Dafür reichen die Quelldaten nicht als Beweis, und das Beispiel der Allgemeinen Inneren Medizin zeigt einen konkreten Informationsverlust.
