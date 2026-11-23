<?php

namespace App\Tests\Support;

final class TestKernel extends \App\Kernel
{
    protected function configureContainer(\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.health_workers', StudioHealthFixture::workers());
    }

    public function getCacheDir(): string
    {
        return APP_TEST_ROOT.'/cache';
    }

    public function getLogDir(): string
    {
        return APP_TEST_ROOT.'/logs';
    }
}
