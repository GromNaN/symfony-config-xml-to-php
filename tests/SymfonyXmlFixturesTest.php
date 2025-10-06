<?php

namespace Tests;

use GromNaN\SymfonyConfigXmlToPhp\XmlToPhpConfigConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\XmlDumper;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[CoversClass(XmlToPhpConfigConverter::class)]
class SymfonyXmlFixturesTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__.'/../vendor/symfony/dependency-injection/Tests/Fixtures/xml/';

    #[DataProvider('provideXmlFiles')]
    public function testConvert(SplFileInfo $xmlFile): void
    {
        $this->assertFileExists($xmlFile);

        $converter = new XmlToPhpConfigConverter();
        try {
            $phpContent = $converter->convertFile($xmlFile->getPathname());
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Failed to convert XML file "%s": %s', $xmlFile->getFilename(), $e->getMessage()), 0, $e);
        }
        $phpFile = str_replace('.xml', '.php', $xmlFile->getRealPath());
        file_put_contents($phpFile, $phpContent);

        try {
            $xmlContainer = new ContainerBuilder();
            $xmlLoader = new XmlFileLoader($xmlContainer, new FileLocator());
            $xmlLoader->load($xmlFile->getRealPath());
        } catch (LoaderLoadException|InvalidArgumentException|LogicException $e) {
            self::markTestSkipped(sprintf('Skip XML file with error "%s": %s', $xmlFile->getFilename(), $e->getMessage()));
        }

        $phpContainer = new ContainerBuilder();
        $phpLoader = new PhpFileLoader($phpContainer, new FileLocator());
        $phpLoader->load($phpFile);

        $xmlDump = new XmlDumper($xmlContainer)->dump();
        $phpDump = new XmlDumper($phpContainer)->dump();

        self::assertEquals($xmlDump, $phpDump, sprintf('The XML and PHP dumps are not equal for file "%s"', $xmlFile));
    }

    public static function provideXmlFiles(): \Generator
    {
        $files = Finder::create()
            ->files()
            ->name('*.xml')
            ->in(self::FIXTURE_DIR)
            ->sortByName()
        ;

        foreach ($files as $file) {
            yield $file->getRelativePathname() => [$file];
        }
    }
}
