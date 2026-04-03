# venne-media/contao-git-push

> **Vibe Coded** - Dieses Package wurde mit Claude Code (AI) entwickelt.

Backend-Modul zur Verwaltung von Git-Repositories in Contao 5.3. Ermoeglicht es, Code-Aenderungen von Entwicklern und Content-Aenderungen aus dem Contao-Backend zusammenzufuehren - direkt aus dem CMS heraus.

## Features

- Git-Operationen direkt aus dem Contao Backend (Push, Pull, Commit)
- SSH-Key-Verwaltung mit automatischer Generierung
- Branch-Verwaltung (Erstellen, Wechseln, Umbenennen, Loeschen)
- Commit-Historie mit Wiederherstellungsfunktion
- Sync-Status zwischen lokalem und Remote-Repository
- Input-Validation auf allen Eingaben (URLs, Branch-Namen, E-Mail)
- File-basiertes Locking gegen konkurrierende Git-Operationen
- Auto-Stash bei Pull und Branch-Wechsel
- Schutz fuer protected Branches (main, master, prod)
- 114 Tests, 196 Assertions

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

Nach der Installation erscheint im Backend unter "System" der Menuepunkt **"GIT Connect"**.

Beim ersten Aufruf:
1. Repository klonen (empfohlen) oder neu initialisieren
2. Git-Benutzer konfigurieren (Name und E-Mail)
3. SSH-Key generieren und in GitHub/GitLab als Deploy Key hinterlegen

## Verwendung

Das Modul bietet folgende Funktionen:
- **Commit & Push**: Lokale Aenderungen committen und zum Remote pushen
- **Pull**: Aenderungen vom Remote holen (mit Auto-Stash bei lokalen Aenderungen)
- **Branch-Verwaltung**: Zwischen Branches wechseln oder neue erstellen
- **Commit-Historie**: Aeltere Versionen wiederherstellen

## Upgrade von v1.x auf v2.0

v2.0 ist ein komplettes Refactoring mit Breaking Changes:
- Force Push ist standardmaessig **deaktiviert**
- Force Push auf protected Branches (main/master/prod) ist blockiert
- Konflikte beim Pull werden automatisch abgebrochen statt den Code zu beschaedigen
- Alle Eingaben werden validiert

## Hinweise zum Contao Manager

Das Bundle registriert sich automatisch. Nach der Installation Cache leeren.

## Lizenz

MIT
