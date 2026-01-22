# venne-media/contao-git-push

Backend-Modul zur Verwaltung von Git-Repositories in Contao 5.3.

## Features

- Git-Operationen direkt aus dem Contao Backend (Push, Pull, Commit)
- SSH-Key-Verwaltung mit automatischer Generierung
- Branch-Verwaltung (Erstellen, Wechseln, Umbenennen, Löschen)
- Commit-Historie mit Wiederherstellungsfunktion
- Sync-Status zwischen lokalem und Remote-Repository

## Systemanforderungen

- Contao ^5.3
- PHP >= 8.2
- Git auf dem Server installiert
- SSH-Zugang zum Remote-Repository (bei Nutzung von SSH-URLs)

## Installation

```bash
composer require venne-media/contao-git-push
```

## Konfiguration

Nach der Installation erscheint im Backend unter "System" der Menüpunkt "Git".

Beim ersten Aufruf:
1. Repository klonen (empfohlen) oder neu initialisieren
2. Git-Benutzer konfigurieren (Name und E-Mail)
3. SSH-Key generieren und in GitHub/GitLab als Deploy Key hinterlegen

## Verwendung

Das Modul bietet folgende Funktionen:
- **Commit & Push**: Lokale Änderungen committen und zum Remote pushen
- **Pull**: Änderungen vom Remote holen
- **Branch-Verwaltung**: Zwischen Branches wechseln oder neue erstellen
- **Commit-Historie**: Ältere Versionen wiederherstellen

## Hinweise zum Contao Manager

Das Bundle registriert sich automatisch. Nach der Installation Cache leeren.

## Upgrade-Hinweise

Keine besonderen Hinweise für v1.0.0.

## Lizenz

MIT
