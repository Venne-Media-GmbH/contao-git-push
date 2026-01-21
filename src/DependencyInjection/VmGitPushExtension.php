<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class VmGitPushExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));

        if (is_file(\dirname(__DIR__, 2) . '/config/services.yaml')) {
            $loader->load('services.yaml');
        }
    }
}
