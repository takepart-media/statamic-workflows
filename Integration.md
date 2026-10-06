# Integration Guide

Wie du das `takepart-media/workflows`-Addon in ein Statamic-6-Projekt einbindest.

---

## Voraussetzungen

- Ein Statamic-6-Projekt (`statamic/cms: ^6.0`)
- PHP 8.3+
- Laravel 11+
- Composer 2.x
- Zugriff auf `github.com/takepart-media/workflows` (SSH-Key oder Personal Access Token)

Node/npm sind **nicht** nötig — das Addon liefert vorgebaute Frontend-Assets mit.

---

## Schritt 1 — Repository und Package eintragen

Öffne die `composer.json` deines Projekts und ergänze:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:takepart-media/workflows.git" }
  ],
  "require": {
    "takepart-media/workflows": "dev-main"
  }
}
```

Falls du bereits einen anderen `repositories`-Block hast, füg den VCS-Eintrag einfach dazu (Array-Element).

**Zur Version-Constraint:**

- `dev-main` — verfolgt die neueste Version des `main`-Branches. Gut für Dev, riskant für Prod.
- `^1.0` — nur wenn im Addon-Repo ein Tag `v1.0.0` (oder höher) existiert. Reproduzierbar. Wenn Composer meldet *„does not match the constraint"* und nur `dev-*`-Versionen listet, existiert der Tag noch nicht → nimm `dev-main`.

---

## Schritt 2 — Installieren

```bash
composer update takepart-media/workflows
```

Composer klont das Repo, packt es nach `vendor/takepart-media/workflows/` und registriert den Statamic-Addon-ServiceProvider automatisch.

---

## Schritt 3 — Bootstrap

```bash
php artisan workflows:install
```

Der Befehl führt dich interaktiv durch:

1. **Migrations laufen** — legt `database/workflow.sqlite` an mit vier Tabellen (`workflow_states`, `workflow_events`, `workflow_assignees`, `workflow_comments` + `workflow_comment_reads`).
2. **Assets publizieren** — kopiert JS/CSS des Addons nach `public/vendor/workflows/build/`.
3. **Workflow-Typ auswählen** — `postmanager` (redaktionelles Update mit Medical-Sign-off) oder `translation` (Multi-Site-Verteilung).
4. **Collections zuweisen** — Multi-Select-Prompt mit den echten Collections deines Projekts. Pfeiltasten navigieren, Space toggelt, Enter bestätigt.
5. **Backfill anbieten** — wenn du willst, werden bestehende Entries der ausgewählten Collections in den Initial- bzw. Finished-State des Workflows migriert.

Der Befehl ist idempotent: wenn `resources/workflows/<type>.yaml` schon existiert, bricht er ab statt zu überschreiben. Du kannst dann später `workflows:install` erneut ausführen um einen zweiten Workflow-Typ hinzuzufügen.

---

## Schritt 4 — Verifizieren

Login ins CP:

- Auf dem Dashboard sollte das Widget **Alle Beiträge** (`workflows_overview`, siehe [Dashboard-Widget](#dashboard-widget)) die Beiträge deines Workflows listen. Die Workflow-Spalte verlinkt auf den Manager (z.B. „Beitragsmanager" unter `/cp/workflows/post-manager`); einen eigenen Nav-Eintrag haben die Manager nicht.
- Als Super-Admin siehst du zusätzlich unter **Settings > Workflows** die Editor-UI für die YAML.
- Öffne einen Entry der gebackfillten Collection: im Editor-Header solltest du die Workflow-Aktionsbuttons sehen (statt oder neben Statamics Publish-Button).

Falls die Assets fehlen (leere CP-Seite, JS-Fehler in DevTools):

```bash
php artisan vendor:publish --tag=workflows --force
```

---

## Voraussetzungen im Projekt-Setup

Damit die Workflows greifen, müssen ein paar projekt-seitige Dinge stimmen:

**User-Groups.** Alle Group-Handles die in deiner Workflow-YAML unter `required_group`, `default_assignee_group`, `post_creator_groups` vorkommen, müssen in `resources/users/groups.yaml` existieren. Das Addon seedet keine Groups.

**Super-Admins.** Mindestens ein User mit `super: true` in `users/*.yaml` — Super-Admins umgehen alle Workflow-Gates und können die Settings-UI benutzen.

<a id="dashboard-widget"></a>**Dashboard-Widget.** Einstieg in die Workflows ist das Dashboard, nicht die Nav. Das Widget in `config/statamic/cp.php` eintragen:

```php
'widgets' => [
    // …
    ['type' => 'workflows_overview', 'width' => 100, 'per_page' => 25],
],
```

Es listet alle Beiträge aller aktiven Workflows in einer Tabelle (neue Workflows erscheinen automatisch), mit Workflow-, Status- und Veraltet-Filter und „Neuer Beitrag"-Buttons. Optional `workflows: [...]` zum Einschränken; `per_page` Default 15. Die übrigen Widgets: siehe DOCS.md § 9.

---

## Konfiguration

Optional. Für Feineinstellungen:

```bash
php artisan vendor:publish --tag=workflows-config
```

Kopiert `config/workflows.php` ins Projekt. Wichtigste Keys:

| Key                    | Default          | Zweck                                                                 |
| ---------------------- | ---------------- | --------------------------------------------------------------------- |
| `database`             | dedizierte SQLite| Workflow-State in deine Haupt-DB umleiten                             |
| `revisions`            | `true`           | Statamic-Revisions bei Transitions schreiben (braucht Statamic Pro)   |
| `privileged_groups`    | `['admins']`     | Groups, die alle Workflow-Gates umgehen                                |
| `post_creator_groups`  | `['admins']`     | Fallback wenn Workflow keinen eigenen setzt                            |
| `nav_section`          | `'Workflows'`    | **Deprecated, ungenutzt** — es gibt keine eigene Nav-Sektion mehr      |
| `sla_scheduler`        | `true`           | Stündlichen SLA-Alterungs-Tick registrieren                           |

> **Entfallen:** Die eigene Nav-Sektion mit einem Eintrag pro Workflow gibt es
> nicht mehr, `nav_section` (früher `nav_parent`) wird nicht mehr gelesen. Ein
> publiziertes `config/workflows.php`, das den Key noch setzt, lädt weiter ohne
> Fehler. Die Manager bleiben unter `/cp/workflows/<slug>` erreichbar; im CP
> gibt es nur noch „Settings > Workflows" für Super-Admins.

---

## Übersetzungen anpassen

Die CP-Labels des Addons kommen aus dem `workflows::messages`-Namespace. Um sie projektspezifisch zu überschreiben:

```bash
php artisan vendor:publish --tag=workflows-translations
```

Dateien landen unter `lang/vendor/workflows/{de,en}/messages.php`. Ergänze oder überschreibe Keys wie:

```php
return [
    'state_draft'    => 'Entwurf',
    'action_publish' => 'Freigeben',
    // ...
];
```

Für State- und Action-Labels folgt das Schema `state_<place_handle>` bzw. `action_<transition_handle>`. Fehlt ein Key, fällt das Addon auf den YAML-`label` und dann auf den humanisierten Handle zurück.

---

## Scheduler aktivieren (für SLA-Aging)

Wenn dein Workflow `metadata.sla`-Regeln nutzt (Alterung veröffentlichter Beiträge nach X Tagen), muss Laravels Scheduler laufen.

**Prod** — Crontab-Eintrag als PHP-User:

```
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

Managed Hosts (Forge, Ploi, Runcloud, ...) haben dafür ein UI — „Scheduled Jobs", „every minute".

**Lokal (Herd/Valet)** — kein Cron, entweder manuell:

```bash
php artisan schedule:run    # einmal ausführen
php artisan schedule:work   # Vordergrund-Worker, tickt jede Minute
```

Ohne Scheduler wird nur `php artisan workflows:transition-aged` bei Handaufruf aktiv (`--dry-run` zeigt was passieren würde).

---

## Updates einspielen

```bash
composer update takepart-media/workflows
php artisan vendor:publish --tag=workflows --force
```

Der `--force`-Publish ist wichtig, weil Statamic sonst schon existierende Assets nicht überschreibt — dein CP würde beim alten Bundle-Hash hängenbleiben.

Bei Prod-Setups: den `vendor:publish`-Aufruf ins Post-Deploy-Script hängen, sonst vergisst man's.

---

## Private-Repo-Authentifizierung

**SSH-Deploy-Key** (empfohlen für Server/CI):

1. Auf dem Server: `ssh-keygen -t ed25519 -C "deploy@myproject"` → Public-Key in GitHub-Repo-Settings > Deploy Keys eintragen (Read-Only reicht).
2. `git ls-remote git@github.com:takepart-media/workflows.git` testen — muss die Refs listen.
3. `composer update takepart-media/workflows` fährt jetzt durch.

**Composer-Auth-JSON** (Alternative, z.B. für Container-Builds):

```bash
composer config --auth github-oauth.github.com <PAT>
```

Legt einen Token in `~/.composer/auth.json` an. Für die App: als Env-Var `COMPOSER_AUTH` mit dem JSON-String bereitstellen.

---

## Alternative: Lokale Path-Repo-Einbindung (nur für Addon-Entwicklung)

Wenn du parallel am Addon selbst arbeitest und Änderungen sofort ohne `git push` sehen willst:

1. Adder-Repo lokal auschecken, z.B. nach `../workflows` relativ zum Projekt:
   ```bash
   git clone git@github.com:takepart-media/workflows.git ../workflows
   ```
2. In `composer.json` des Projekts:
   ```json
   {
     "repositories": [
       { "type": "path", "url": "../workflows", "options": { "symlink": true } }
     ],
     "require": {
       "takepart-media/workflows": "*"
     }
   }
   ```
3. `composer update takepart-media/workflows` — legt einen Symlink von `vendor/takepart-media/workflows` auf den lokalen Checkout an.
4. Optional für Live-Assets: auch `public/vendor/workflows/build/` als Symlink:
   ```bash
   rm -rf public/vendor/workflows/build
   ln -s ../../../../workflows/resources/dist/build public/vendor/workflows/build
   ```

Änderungen im Addon-Repo (`../workflows`) sind dann sofort im Projekt live — nach `npm run build` (bei JS/CSS-Edits) und einem Browser-Reload.

**Achtung:** `php artisan vendor:publish --tag=workflows --force` überschreibt den Public-Symlink mit einer echten Kopie. Nach solchen Kommandos ggf. den Symlink neu setzen.

---

## Deinstallieren

```bash
composer remove takepart-media/workflows
rm -rf public/vendor/workflows resources/workflows database/workflow.sqlite
```

Optional zusätzlich `lang/vendor/workflows/`, `config/workflows.php` falls publiziert. Das Addon touchiert keine Statamic-Core-Files, die Deinstallation ist sauber.

---

## Troubleshooting

**„does not match the constraint"** — nur `dev-*`-Versionen im Addon-Repo. Setze `dev-main` als Constraint (siehe [Schritt 1](#schritt-1--repository-und-package-eintragen)) oder erzeuge einen Tag im Addon-Repo.

**Assets werden nicht geladen / alter Bundle-Hash** — `php artisan vendor:publish --tag=workflows --force` ausführen und im Browser Cmd+Shift+R.

**Keine Workflows im CP / Workflow-Manager fehlt** — die Manager haben keinen Nav-Eintrag mehr. Prüfen, dass `workflows_overview` in `config/statamic/cp.php → widgets` steht (siehe [Dashboard-Widget](#dashboard-widget)) und die Workflow-YAML unter `resources/workflows/<name>.yaml` liegt und aktiv ist. Fehlt nur der Link auf die Manager-Seite (`/cp/workflows/<slug>`), ist `metadata.nav.slug` nicht gesetzt. `/cp/workflows` selbst leitet aufs Dashboard um.

**„group [foo] not found"** — der referenzierte Group-Handle steht in der Workflow-YAML aber nicht in `resources/users/groups.yaml`. Anlegen und Cache leeren (`php artisan cache:clear`).

**Publish-Button fehlt in Entry-Editor** — der Workflow für diese Collection ist eingesprungen und der Entry ist nicht in einem `allow_publish: true`-Place. Super-Admins sehen den Button trotzdem; alle anderen bewegen den Entry per Transition dahin.

---

## Weiterlesen

- [`README.md`](./README.md) — Feature-Übersicht, Command-Referenz, Konfigurations-Details
- [`DOCS.md`](./DOCS.md) — Architektur, Datenmodell, vollständiges YAML-Schema
