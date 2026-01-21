<?php

declare(strict_types=1);

/**
 * Git Push Bundle fuer Contao 5.3
 * By venne-media.de
 */

// Backend-Modul im System-Bereich registrieren
$GLOBALS['BE_MOD']['system']['vm_git'] = [
    'callback' => \VennMedia\VmGitPushBundle\Backend\GitPushModule::class
];
