<?php

declare(strict_types=1);

namespace VennMedia\VmGitPushBundle\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;
use VennMedia\VmGitPushBundle\VmGitPushBundle;

/**
 * Contao Manager Plugin
 * By venne-media.de
 */
final class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    /**
     * @return array<int, BundleConfig>
     */
    public function getBundles(ParserInterface $parser): array
    {
        return [
            BundleConfig::create(VmGitPushBundle::class)
                ->setLoadAfter(['Contao\CoreBundle\ContaoCoreBundle']),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): ?RouteCollection
    {
        $locator = new FileLocator(\dirname(__DIR__, 2) . '/config');
        $loader = new YamlFileLoader($locator);
        return $loader->load('routes.yaml');
    }
}
