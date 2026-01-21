<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Backend;

use Contao\BackendModule;
use Contao\System;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Backend Module fuer Git Push
 * By venne-media.de
 */
class GitPushModule extends BackendModule
{
    protected $strTemplate = 'be_wildcard';

    public function generate(): string
    {
        // Redirect zum Controller
        $router = System::getContainer()->get('router');
        $url = $router->generate('vm_git_push_index');

        return (new RedirectResponse($url))->send();
    }

    protected function compile(): void
    {
        // Nicht benötigt da wir redirecten
    }
}
