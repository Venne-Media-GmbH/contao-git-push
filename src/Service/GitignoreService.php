<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class GitignoreService
{
    private string $projectRoot;

    /**
     * Verzeichnisse/Dateien die IMMER ignoriert werden sollten (nicht konfigurierbar).
     */
    private const ALWAYS_IGNORE = [
        '/var/',
        '/vendor/',
        '.env.local',
        '.env.*.local',
        '.DS_Store',
        'Thumbs.db',
        '*.log',
        '*.swp',
        '*.swo',
        '/node_modules/',
    ];

    /**
     * Bekannte Contao-Verzeichnisse mit Beschreibung und empfohlener Einstellung.
     * ignore_default = true → wird standardmäßig ignoriert
     */
    private const KNOWN_PATHS = [
        '/files/' => [
            'label' => 'files/ (Medien & Uploads)',
            'description' => 'Hochgeladene Bilder, PDFs, etc. aus der Dateiverwaltung',
            'ignore_default' => true,
        ],
        '/assets/' => [
            'label' => 'assets/ (Kompilierte Assets)',
            'description' => 'Automatisch generierte CSS/JS Dateien',
            'ignore_default' => true,
        ],
        '/system/tmp/' => [
            'label' => 'system/tmp/ (Temporäre Dateien)',
            'description' => 'Cache und temporäre Dateien',
            'ignore_default' => true,
        ],
        '/system/config/localconfig.php' => [
            'label' => 'system/config/localconfig.php',
            'description' => 'Lokale Konfiguration (Datenbank-Passwörter etc.)',
            'ignore_default' => true,
        ],
        '/contao-manager/' => [
            'label' => 'contao-manager/ (Contao Manager)',
            'description' => 'Contao Manager Dateien',
            'ignore_default' => true,
        ],
        '/public/bundles/' => [
            'label' => 'public/bundles/ (Symfony Bundles)',
            'description' => 'Wird automatisch generiert durch bin/console assets:install',
            'ignore_default' => true,
        ],
        '/public/assets/' => [
            'label' => 'public/assets/ (Öffentliche Assets)',
            'description' => 'Kompilierte öffentliche Assets',
            'ignore_default' => true,
        ],
        '/public/share/' => [
            'label' => 'public/share/',
            'description' => 'Share-Dateien',
            'ignore_default' => true,
        ],
        '/public/system/' => [
            'label' => 'public/system/',
            'description' => 'System-Dateien im öffentlichen Verzeichnis',
            'ignore_default' => true,
        ],
        '/web/bundles/' => [
            'label' => 'web/bundles/ (Legacy)',
            'description' => 'Legacy Symfony Bundles (Contao < 5)',
            'ignore_default' => true,
        ],
        '/web/assets/' => [
            'label' => 'web/assets/ (Legacy)',
            'description' => 'Legacy öffentliche Assets',
            'ignore_default' => true,
        ],
        '/web/share/' => [
            'label' => 'web/share/ (Legacy)',
            'description' => 'Legacy Share-Dateien',
            'ignore_default' => true,
        ],
        '/web/system/' => [
            'label' => 'web/system/ (Legacy)',
            'description' => 'Legacy System-Dateien',
            'ignore_default' => true,
        ],
        '/templates/' => [
            'label' => 'templates/ (Contao Templates)',
            'description' => 'Angepasste Contao Templates - NICHT ignorieren wenn Templates im Repo sein sollen',
            'ignore_default' => false,
        ],
        '/config/' => [
            'label' => 'config/ (Symfony Konfiguration)',
            'description' => 'Symfony/Contao Konfigurationsdateien',
            'ignore_default' => false,
        ],
        '/src/' => [
            'label' => 'src/ (Eigener PHP Code)',
            'description' => 'Eigene PHP Klassen, EventListener, etc.',
            'ignore_default' => false,
        ],
        '/contao/' => [
            'label' => 'contao/ (Contao Konfiguration)',
            'description' => 'DCA, Sprachen, etc.',
            'ignore_default' => false,
        ],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->projectRoot = $projectDir;
    }

    /**
     * Scannt das Projektverzeichnis und gibt konfigurierbare Pfade zurück.
     *
     * @return array<string, array{label: string, description: string, ignore_default: bool, exists: bool}>
     */
    public function getConfigurablePaths(): array
    {
        $result = [];

        foreach (self::KNOWN_PATHS as $path => $info) {
            $fullPath = $this->projectRoot . $path;
            $exists = file_exists(rtrim($fullPath, '/'));

            $result[$path] = [
                'label' => $info['label'],
                'description' => $info['description'],
                'ignore_default' => $info['ignore_default'],
                'exists' => $exists,
            ];
        }

        // Auch nicht-bekannte Verzeichnisse im Root auflisten
        $rootDirs = $this->scanRootDirectories();
        foreach ($rootDirs as $dir) {
            $path = '/' . $dir . '/';
            if (!isset($result[$path]) && !$this->isAlwaysIgnored($path)) {
                $result[$path] = [
                    'label' => $dir . '/',
                    'description' => 'Verzeichnis im Projektordner',
                    'ignore_default' => false,
                    'exists' => true,
                ];
            }
        }

        // Sortieren: existierende zuerst, dann alphabetisch
        uasort($result, function ($a, $b) {
            if ($a['exists'] !== $b['exists']) {
                return $b['exists'] <=> $a['exists'];
            }

            return strcmp($a['label'], $b['label']);
        });

        return $result;
    }

    /**
     * Erstellt eine .gitignore mit den gewählten Pfaden.
     *
     * @param string[] $ignorePaths Pfade die ignoriert werden sollen (z.B. ['/files/', '/assets/'])
     */
    public function createGitignore(array $ignorePaths): void
    {
        $gitignorePath = $this->projectRoot . '/.gitignore';

        $sections = [];

        // Immer ignorierte Pfade
        $sections[] = "# System (immer ignoriert)";
        foreach (self::ALWAYS_IGNORE as $path) {
            $sections[] = $path;
        }

        // Benutzer-gewählte Pfade
        if (!empty($ignorePaths)) {
            $sections[] = "";
            $sections[] = "# Projekt-spezifisch";
            foreach ($ignorePaths as $path) {
                $path = trim($path);
                if (!empty($path) && !in_array($path, self::ALWAYS_IGNORE, true)) {
                    $sections[] = $path;
                }
            }
        }

        file_put_contents($gitignorePath, implode("\n", $sections) . "\n");
    }

    /**
     * Gibt die Standard-.gitignore Pfade zurück (für automatisches Setup ohne UI).
     *
     * @return string[]
     */
    public function getDefaultIgnorePaths(): array
    {
        $paths = [];
        foreach (self::KNOWN_PATHS as $path => $info) {
            if ($info['ignore_default']) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Prüft ob eine bestehende .gitignore vorhanden ist.
     */
    public function hasGitignore(): bool
    {
        return file_exists($this->projectRoot . '/.gitignore');
    }

    /**
     * Liest den Inhalt der aktuellen .gitignore.
     */
    public function getGitignoreContent(): string
    {
        $path = $this->projectRoot . '/.gitignore';
        if (!file_exists($path)) {
            return '';
        }

        return file_get_contents($path);
    }

    /**
     * Speichert den Inhalt der .gitignore direkt (Freitext-Editor).
     */
    public function saveGitignoreContent(string $content): void
    {
        $path = $this->projectRoot . '/.gitignore';
        file_put_contents($path, $content);
    }

    /**
     * Parst die aktuelle .gitignore und gibt die aktiven Pfade zurück,
     * zusammen mit dem konfigurierbaren Status (Checkbox-Darstellung).
     *
     * @return array<string, array{label: string, description: string, ignored: bool, exists: bool}>
     */
    public function getCurrentIgnoreState(): array
    {
        $currentContent = $this->getGitignoreContent();
        $currentLines = array_map('trim', explode("\n", $currentContent));

        $result = [];

        // Bekannte Pfade mit aktuellem Status
        foreach (self::KNOWN_PATHS as $path => $info) {
            $fullPath = $this->projectRoot . $path;
            $exists = file_exists(rtrim($fullPath, '/'));
            $isIgnored = in_array($path, $currentLines, true)
                || in_array(ltrim($path, '/'), $currentLines, true);

            $result[$path] = [
                'label' => $info['label'],
                'description' => $info['description'],
                'ignored' => $isIgnored,
                'exists' => $exists,
            ];
        }

        // Unbekannte Verzeichnisse im Root
        $rootDirs = $this->scanRootDirectories();
        foreach ($rootDirs as $dir) {
            $path = '/' . $dir . '/';
            if (!isset($result[$path]) && !$this->isAlwaysIgnored($path)) {
                $isIgnored = in_array($path, $currentLines, true)
                    || in_array($dir . '/', $currentLines, true)
                    || in_array('/' . $dir . '/', $currentLines, true);

                $result[$path] = [
                    'label' => $dir . '/',
                    'description' => 'Verzeichnis im Projektordner',
                    'ignored' => $isIgnored,
                    'exists' => true,
                ];
            }
        }

        // Sortieren: existierende zuerst
        uasort($result, function ($a, $b) {
            if ($a['exists'] !== $b['exists']) {
                return $b['exists'] <=> $a['exists'];
            }
            return strcmp($a['label'], $b['label']);
        });

        return $result;
    }

    private function scanRootDirectories(): array
    {
        $dirs = [];
        $entries = scandir($this->projectRoot);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') {
                continue;
            }

            if (is_dir($this->projectRoot . '/' . $entry)) {
                $dirs[] = $entry;
            }
        }

        return $dirs;
    }

    private function isAlwaysIgnored(string $path): bool
    {
        foreach (self::ALWAYS_IGNORE as $ignored) {
            if ($path === $ignored || trim($path, '/') === trim($ignored, '/')) {
                return true;
            }
        }

        return false;
    }
}
