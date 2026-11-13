<?php

namespace Tests;

use GromNaN\SymfonyConfigXmlToPhp\XmlToPhpConfigConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlToPhpConfigConverter::class)]
class XmlToPhpConfigConverterTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/Fixtures/detection/config';

    #[DataProvider('provideConfigurations')]
    public function testSupports(string $file, bool $expected): void
    {
        self::assertSame($expected, (new XmlToPhpConfigConverter())->supports((string) file_get_contents($file)));
    }

    public function testDoesNotSupportInvalidXml(): void
    {
        $converter = new XmlToPhpConfigConverter();

        self::assertFalse($converter->supports(''));
        self::assertFalse($converter->supports('<container>'));
    }

    public function testConvertsConfigurationWithoutSchemaLocation(): void
    {
        $php = (new XmlToPhpConfigConverter())->convert(<<<'XML'
            <?xml version="1.0" encoding="UTF-8" ?>
            <container xmlns="http://symfony.com/schema/dic/services">
                <services>
                    <service id="app.service" class="App\Service" />
                </services>
            </container>
            XML);

        self::assertStringContainsString("'app.service'", $php);
    }

    public static function provideConfigurations(): \Generator
    {
        yield 'service configuration' => [self::FIXTURE_DIR.'/doctrine/doctrine_services.xml', true];
        yield 'services in when' => [self::FIXTURE_DIR.'/when_services.xml', true];
        yield 'doctrine extension configuration' => [self::FIXTURE_DIR.'/packages/doctrine.xml', false];
        yield 'extension configuration in when' => [self::FIXTURE_DIR.'/when_extension.xml', false];
        yield 'routing configuration' => [self::FIXTURE_DIR.'/routes.xml', false];
    }
}
