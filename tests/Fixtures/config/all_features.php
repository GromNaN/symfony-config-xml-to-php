<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container) {
    $services = $container->services();
    $parameters = $container->parameters();
    // Import section
    $container->import('imported_file.php');
    $container->import('optional_file.php', null, true);

    // Parameters section
    $parameters->set('database_host', 'localhost');
    $parameters->set('database_port', 3306);
    $parameters->set('database_name', 'symfony');
    $parameters->set('database_user', 'root');
    $parameters->set('database_password', 'password');
    $parameters->set('mailer.enabled', 'true');
    $parameters->set('env_param', '%env(APP_ENV)%');
    $parameters->set('const_param', \PHP_VERSION);
    $parameters->set('binary_content', base64_decode('SGVsbG8gd29ybGQh'));
    $parameters->set('locales', ['en', 'fr', 'de']);
    $parameters->set('doctrine.connections', ['default' => ['driver' => 'pdo_mysql', 'charset' => 'utf8mb4'], 'backup' => ['driver' => 'pdo_sqlite', 'memory' => true]]);
    // Services section

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
        ->tag('app.tagged_by_default')
        ->bind('$defaultParam', 'default value');

    $services->set('app.abstract_service', 'App\Service\AbstractService')
        ->abstract()
        ->tag('app.abstract')
        ->call('setLogger', [service('logger')]);

    $services->set('app.mailer', 'App\Service\Mailer')
        ->args([
            '%mailer.transport%',
            service('mailer.transport'),
            ['host' => '%mailer.host%', 'port' => '%mailer.port%', 'encryption' => '%mailer.encryption%'],
            \DATE_RFC2822,
            base64_decode('SGVsbG8gd29ybGQh'),
        ]);

    $services->set('app.indexed_service', 'App\Service\IndexedService')
        ->args([
            'first argument',
            '2' => 'third argument',
            '1' => 'second argument',
        ]);

    $services->set('app.indexed_service_no_key', 'App\Service\IndexedService')
        ->args([
            'first argument',
            'second argument',
            'third argument',
        ]);

    $services->set('app.newsletter_manager', 'App\Service\NewsletterManager')
        ->property('mailer', service('app.mailer'))
        ->property('enabled', true)
        ->property('sender', 'sender@example.com');

    $services->set('app.mail_logger', 'App\Logger\MailLogger')
        ->parent('app.abstract_service')
        ->tag('monolog.logger', ['channel' => 'mail'])
        ->tag('app.important_service', ['priority' => 20])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 50]);

    $services->set('app.newsletter_manager_factory', 'App\Factory\NewsletterManagerFactory');

    $services->set('app.newsletter_manager_from_factory', 'App\Service\NewsletterManager')
        ->args(['%app.default_sender%'])
        ->factory([service('app.newsletter_manager_factory'), 'createNewsletterManager']);

    $services->set('app.logger_from_static', 'App\Logger\Logger')
        ->args(['app'])
        ->factory(['App\Factory\LoggerFactory', 'createLogger']);

    $services->set('app.expression_factory', 'App\Service\DynamicService')
        ->args(['dynamic-argument'])
        ->factory(expr('service(\'app.factory_provider\').getSpecificFactory(\'dynamic\')'));

    $services->set('app.processor', 'App\Service\Processor')
        ->call('setLogger', [service('logger')])
        ->call('addPlugin', ['plugin1'])
        ->call('configure', [['option1' => 'value1', 'option2' => 'value2']])
        ->call('setCloner', returnsClone: true);

    $services->set('app.controller', 'App\Controller\MainController')
        ->autowire()
        ->autoconfigure();

    $services->set('app.heavy_service', 'App\Service\HeavyService')
        ->lazy();

    $services->set('app.request')
        ->public()
        ->synthetic();

    $services->alias('app.mailer_alias', 'app.mailer')
        ->public();

    $services->set('app.decorator', 'App\Decorator\ServiceDecorator')
        ->decorate('app.mailer', 'app.original_mailer', 5, \Symfony\Component\DependencyInjection\ContainerInterface::NULL_ON_INVALID_REFERENCE)
        ->args([service('app.original_mailer')]);

    $services->set('app.plugin_manager', 'App\Service\PluginManager')
        ->args([tagged_iterator('app.plugin', indexAttribute: 'key', defaultIndexMethod: 'getPluginName', defaultPriorityMethod: 'getPriority')]);

    $services->set('app.handler_resolver', 'App\Service\HandlerResolver')
        ->args([tagged_locator('app.handler', indexAttribute: 'type')]);

    $services->set('app.service_locator', \stdClass::class)
        ->args([service_locator([
            'mailer' => service('app.mailer'),
            'logger' => service('logger'),
        ])]);

    $services->set('app.command', 'App\Command\ImportCommand')
        ->bind('$dsn', '%app.database_dsn%')
        ->bind('$logger', service('logger'))
        ->bind('$importers', tagged_iterator('app.importer'))
        ->bind('$environment', '%kernel.environment%');

    $services->instanceof('App\Interface\LoggableInterface')
        ->tag('app.loggable')
        ->call('setLogger', [service('logger')]);

    $services->instanceof('App\Interface\CacheableInterface')
        ->tag('app.cacheable')
        ->call('setCache', [service('cache.app')]);

    $services->set('app.event_subscriber', 'App\EventSubscriber\AppSubscriber')
        ->autoconfigure();

    $services->load('Tests\\Fixtures\\', '../')
        ->exclude([
            './',
        ])
        ->tag('controller.service_arguments')
        ->call('setContainer', [service('service_container')]);

    $services->load('Tests\\Fixtures\\', '../')
        ->exclude([
            './',
        ])
        ->tag('console.command')
        ->tag('monolog.logger', ['channel' => 'command']);

    $services->set('app.deprecated_mailer', 'App\Service\LegacyMailer')
        ->deprecate('app/mailer', '2.0', 'The "%service_id%" service is deprecated, use "app.mailer" instead.');

    $services->set('app.config_provider', 'App\Service\ConfigProvider')
        ->file('%kernel.project_dir%/config/legacy_config.php');
    // Environment specific configuration

    // Configuration for environment: dev
    if ($container->env() === 'dev') {

        $services->set('app.dev_logger', 'App\Logger\DevLogger')
            ->public()
            ->tag('monolog.logger', ['channel' => 'dev']);
    }

    // Configuration for environment: test
    if ($container->env() === 'test') {
        $parameters->set('database_host', 'localhost');
        $parameters->set('database_name', 'symfony_test');
    }
};
