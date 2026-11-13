<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container) {
    $services = $container->services();
    $parameters = $container->parameters();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
        ->tag('app.default_tag');

    $services->instanceof(\App\Contract::class)
        ->autowire()
        ->tag('app.contract');

    $services->set('app.first_service', \App\FirstService::class);

    $services->set('app.second_service', \App\SecondService::class);
};
