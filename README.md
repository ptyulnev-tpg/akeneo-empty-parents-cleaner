# Akeneo Empty Parents Cleaner

Akeneo-PIM-5-Bundle (CE 5.0), das **leere Product Models** findet und löscht: Root Product Models, unter denen
weder direkt noch unter einem ihrer Sub-Models ein Varianten-Produkt hängt (TAPS-835, Folge von TAPS-804).
Leere Sub-Models werden mitgelöscht. **Einfache Produkte ohne Parent werden nie angefasst.**

Die Regel entspricht `EmptyProductModelScanner::findDeadRoots()` aus tpg-pegasus-services und steht im Bundle nur
an einer Stelle: `Service/EmptyProductModelCleaner::DEAD_ROOT_CONDITION`. Zählen, Auflisten, Blättern und Löschen
sehen dadurch immer dieselbe Menge.

## Funktionen

- **CLI** `bin/console acme:product-model:delete-empty`
  - ohne Optionen: Dry-Run, zählt je Familie, löscht nichts
  - `--family=<code>`: nur eine Familie · `--list`: Codes ausgeben · `--batch-size=100`
  - `--force`: löschen (mit Rückfrage; `-n` löscht ohne Rückfrage, z. B. für Cron)
- **Dashboard-Widget** (Activity → Dashboard): Anzahl leerer Product Models je Familie
- **Seite** Activity → „Empty product models“: Liste mit Familienfilter und Paging, Klick öffnet das Product Model
- **Löschen über die UI**: „Alle (aus Familie X) löschen“ startet nach Bestätigung den Hintergrund-Job
  `acme_delete_empty_product_models`; Fortschritt und Ergebnis im Process Tracker. Vor jedem Batch wird erneut
  geprüft, ob die Models noch leer sind.
- **Rechte**: Ansehen mit `pim_enrich_product_index`, Löschen mit `pim_enrich_product_model_remove`

## Installation (Akeneo PIM CE 5.0)

1. Code nach `src/Acme/Bundle/ProductCleanupBundle` legen (oder per Composer, siehe `composer.json`).
2. In `config/bundles.php` registrieren:
   ```php
   Acme\Bundle\ProductCleanupBundle\AcmeProductCleanupBundle::class => ['all' => true],
   ```
3. Routen einbinden, z. B. `config/routes/acme_product_cleanup.yml`:
   ```yaml
   acme_product_cleanup:
       resource: "@AcmeProductCleanupBundle/Resources/config/routing.yml"
   ```
4. Job-Instanz einmalig anlegen:
   ```bash
   bin/console akeneo:batch:create-job "Acme Product Cleanup" acme_delete_empty_product_models mass_delete acme_delete_empty_product_models '{"family":null}' "Delete empty product models"
   ```
5. Cache leeren und Frontend bauen – Reihenfolge beachten (`pim:installer:assets --clean` löscht `public/css`):
   ```bash
   rm -rf var/cache/prod
   bin/console cache:clear
   bin/console --env=prod pim:installer:assets --symlink --clean
   yarn run less
   yarn run update-extensions && yarn run webpack
   ```
6. Damit der Lösch-Job läuft, muss der Job-Consumer laufen:
   ```bash
   bin/console akeneo:batch:job-queue-consumer-daemon
   ```

## Empfehlung für Produktivsysteme

Vorher immer einen Dry-Run machen und familienweise löschen (`--family` bzw. Familienfilter in der UI), große
Mengen mit dem PIM-Team abstimmen und ein Datenbank-Backup ziehen. Gelöschte Product Models lassen sich nicht
wiederherstellen.
