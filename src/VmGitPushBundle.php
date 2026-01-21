<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use VennMedia\VmGitPushBundle\DependencyInjection\VmGitPushExtension;

/**
 * Git Push Bundle fuer Contao 5.3
 * Verwalten Sie Git Repositories direkt aus dem Contao Backend.
 *
 * @author Venne Media GmbH <https://venne-media.de>
 */
final class VmGitPushBundle extends Bundle
{
    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return new VmGitPushExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
    }
}
