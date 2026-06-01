# OakEngine Installer

Ein leichtgewichtiges PHP-Installationswerkzeug fuer Oak-Engine-Deployments. Die Projektinstallation laeuft jetzt ueber einen **Server-Endpunkt**, der Runner-, Plugin- und Data-Pakete bereitstellt. Nur das Self-Update des Installers nutzt weiterhin GitHub.

## Funktionen

- Installation von **Runner**-, **Plugin**- und **Data**-Paketen ueber einen Package-API-Endpunkt
- Versand einer persistenten **Install UUID** bei jeder Package-Anfrage
- Self-Update des Installers aus dem GitHub-Repository `oakengine/installer`
- Bearbeitung der `.env.local`, Umschalten von `APP_ENV`, Verwaltung von Datenbankeintraegen und Regeneration der Install UUID
- Anzeige des Migrationsstatus, Ausfuehren von Doctrine-Migrationen und Leeren des Anwendungscaches
- Schutz des Installers per Passwort
- Erhalt konfigurierter Dateien und Ordner bei Runner-Updates

## Voraussetzungen

- PHP 8.2 oder hoeher
- PHP-Erweiterungen: `curl`, `zip`, `openssl`
- Schreibrechte auf das Zielverzeichnis
- `shell_exec`, falls Migrationen und Cache-Aktionen ueber die UI genutzt werden sollen

## Installation

1. Den kompletten Inhalt von `src/` in das Web-Verzeichnis des Installers auf dem Zielserver kopieren.
2. `config.example.php` in `config.php` umbenennen.
3. Mindestens `project_api_url` und `target_directory` setzen.
4. Installer im Browser aufrufen.

## Konfiguration

`config.php` liefert ein normales PHP-Array. Die wichtigsten Optionen:

| Schluessel | Beschreibung |
| --- | --- |
| `project_api_url` | Basis-URL des Package-Server-Endpunkts fuer Projektpakete. |
| `project_api_token` | Optionales Bearer-Token fuer die Package-API. |
| `target_directory` | Verzeichnis, in das das gewaehlte Runner-Paket installiert wird. |
| `installer_repository` | GitHub-Repository fuer Self-Updates des Installers. |
| `installer_version` | Aktuelle Installer-Version; wird nach Self-Updates automatisch gepflegt. |
| `github_token` | Optionales Token fuer GitHub-Self-Update-Anfragen. |
| `password` | Optionales UI-Passwort als Klartext oder Hash. |
| `updater_source_path` | Quellpfad innerhalb des Installer-Repositories fuer Self-Updates. |
| `exclude_folders` / `exclude_files` | Dateien und Ordner, die nie ins Zielverzeichnis extrahiert werden duerfen. |
| `whitelist_folders` / `whitelist_files` | Dateien und Ordner, die bei einer Runner-Installation erhalten bleiben muessen. |
| `default_language` | Fallback-Sprache der Oberflaeche. |

## Erwartetes Endpoint-Format

Der Installer akzeptiert Listen- und Detailantworten mit Paket-Metadaten in dieser Form:

```json
{
  "package_type": "runner",
  "package_id": "oak-runner",
  "version": "1.2.3",
  "channel": "stable",
  "package_name": "oak/runner",
  "archive_size": 123456,
  "archive_sha256": "…",
  "download_url": "https://packages.example.test/downloads/oak-runner-1.2.3.tar.gz",
  "composer": {
    "name": "oak/runner"
  }
}
```

Fuer Paketlisten darf der Endpunkt entweder ein nacktes Array oder ein Objekt mit einem `packages`-Array liefern. Bei jeder Package-API-Anfrage sendet der Installer die aktuelle Install UUID im Header `X-Install-UUID` und - falls konfiguriert - das API-Token als `Authorization: Bearer ...`.

## Sicherheitshinweise

- In `config.php` ein Passwort setzen.
- Den Installer-Pfad nach Moeglichkeit zusaetzlich per Webserver absichern.
- Den Installer entfernen, wenn er nicht mehr benoetigt wird.

## Lizenz

MIT. Siehe `LICENSE`.
