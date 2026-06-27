# OakEngine Installer

Ein leichtgewichtiges PHP-Installationswerkzeug fuer Oak-Engine-Deployments. Die Projektinstallation laeuft ueber einen **Server-Endpunkt**, der Runner-, Plugin- und Data-Pakete bereitstellt. Nur das Self-Update des Installers nutzt weiterhin GitHub.

## Funktionen

- Installation von **Runner**-, **Plugin**- und **Data-Paketen** ueber einen Package-API-Endpunkt
- **Install-Manifest** – jede Installation speichert eine SHA1-basierte Liste aller geschriebenen Dateien; Updates entfernen obsolete Dateien und leere Ordner per Diff
- Versand einer persistenten **Install UUID** bei jeder Package-Anfrage
- Self-Update des Installers aus dem GitHub-Repository `oakengine/installer`
- Bearbeitung der `.env.local`, Umschalten von `APP_ENV`, Verwaltung von Datenbankeintraegen und Regeneration der Install UUID
- Anzeige des Migrationsstatus, Ausfuehren von Doctrine-Migrationen und Leeren des Anwendungscaches
- Schutz des Installers per Passwort
- Automatischer Schutz von Plugin- und Data-Ordnern bei Runner-Updates (separate Manifeste)

## Dokumentation

- Deutsche Uebersicht: dieses Dokument.
- Englische Uebersicht: [README.md](README.md).
- Architektur und Interna: [docs/architecture.md](docs/architecture.md).
- Format des Install-Manifests und Diff-Workflow: [docs/install-manifest.md](docs/install-manifest.md).
- Package-Endpoint-Vertrag im Detail: [docs/package-endpoint.md](docs/package-endpoint.md).
- Self-Update-Workflow ueber GitHub: [docs/self-update.md](docs/self-update.md).
- Entwicklung, Docker und Test-Workflow: [docs/development.md](docs/development.md).
- Sicherheitshinweise fuer den Produktivbetrieb: [docs/security.md](docs/security.md).

## Voraussetzungen

- PHP 8.2 oder hoeher
- PHP-Erweiterungen: `curl`, `zip`, `openssl`
- Schreibrechte auf das Zielverzeichnis
- `shell_exec`, falls Migrationen und Cache-Aktionen ueber die UI genutzt werden sollen

## Installation

1. Den kompletten Inhalt von `src/` in das Web-Verzeichnis des Installers auf dem Zielserver kopieren.
2. `config.example.php` in `config.php` umbenennen.
3. Mindestens `project_api_url` und `target_directory` setzen.
4. Im lokalen Docker-Setup ist der Default-Endpunkt `http://web-server/index.php` mit dem Dev-Token `oak-local-dev-token`.
5. Installer im Browser aufrufen.

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

Fuer Paketlisten darf der Endpunkt entweder ein nacktes Array oder ein Objekt mit einem `packages`-Array liefern. Bei jeder Package-API-Anfrage sendet der Installer die aktuelle Install UUID im Header `X-Install-UUID` und - falls konfiguriert - das API-Token als `Authorization: Bearer ...`. Details: [docs/package-endpoint.md](docs/package-endpoint.md).

## So funktionieren Updates

Fuer jede Installation (Runner, Plugin, Data) legt der Installer ein Manifest mit allen extrahierten Dateien und SHA1-Hash ab. Bei einem Update wird das alte Manifest gegen das neue diff-t und nur die Dateien geloescht, die nicht mehr vorkommen. Dadurch leer gewordene Ordner werden ebenfalls entfernt.

- Format und Beispiel: [docs/install-manifest.md](docs/install-manifest.md)
- Implementierung: [`src/app/InstallManifestManager.php`](src/app/InstallManifestManager.php)

## Sicherheitshinweise

- In `config.php` ein Passwort setzen.
- Den Installer-Pfad nach Moeglichkeit zusaetzlich per Webserver absichern.
- Den Installer entfernen, wenn er nicht mehr benoetigt wird.
- Details: [docs/security.md](docs/security.md)

## Lizenz

MIT. Siehe [LICENSE](LICENSE).
