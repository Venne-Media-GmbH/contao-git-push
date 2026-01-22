<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\Backend;

use Contao\BackendModule;
use Contao\System;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GitPushModule extends BackendModule
{
    protected $strTemplate = 'be_wildcard';

    public function generate(): string
    {
        $url = System::getContainer()->get('router')->generate('vm_git_push_index');

        return (new RedirectResponse($url))->send();
    }

    protected function compile(): void
    {
    }
}
