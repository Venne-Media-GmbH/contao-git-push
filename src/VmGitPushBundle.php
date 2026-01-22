<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use VennMedia\VmGitPushBundle\DependencyInjection\VmGitPushExtension;

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
