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
    // depends on 'symfony/dependency-injection' and used installation method - from source (git; with tests presented)
    private const FIXTURE_DIR = __DIR__.'/../vendor/symfony/dependency-injection/Tests/Fixtures/xml/';

    private const SKIPPED = [
        'extension1/services.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'extension2/services.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'nested_service_without_id.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services5.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services6.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services10.xml' => 'Edge case, keys not in the same order',
        'namespaces.xml' => 'Uses custom namespaces not supported by the converter',
        'services_with_service_locator_argument.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services_with_invalid_enumeration.xml' => 'Invalid enumeration value make PHP loader to fail',
        'services9.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services21.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services_inline_not_candidate.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services_tsantos.xml' => 'Inline services are flattened in the PHP dumper, so the structure is different',
        'services4_bad_import_file_not_found.xml' => 'Imported file does not exist',
        'services4_bad_import.xml' => 'Imported file does not exist',
        'stack.xml' => 'Abstract definition in a stack is not supported by PHP-DSL',
    ];

    #[DataProvider('provideXmlFiles')]
    public function testConvert(SplFileInfo $xmlFile): void
    {
        $this->assertFileExists($xmlFile);

        if (isset(self::SKIPPED[$xmlFile->getRelativePathname()])) {
            self::markTestSkipped(self::SKIPPED[$xmlFile->getRelativePathname()]);
        }

        // Only try to load valid XML files
        try {
            $xmlContainer = new ContainerBuilder();
            $xmlLoader = new XmlFileLoader($xmlContainer, new FileLocator());
            $xmlLoader->load($xmlFile->getRealPath());
            $xmlContainer->getParameterBag()->remove('.container.known_envs');
        } catch (LoaderLoadException|InvalidArgumentException|LogicException $e) {
            self::markTestSkipped(sprintf('Skip XML file with error "%s": %s', $xmlFile->getFilename(), $e->getMessage()));
        }

        $converter = new XmlToPhpConfigConverter();
        try {
            $phpContent = $converter->convertFile($xmlFile->getPathname());
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Failed to convert XML file "%s": %s', $xmlFile->getFilename(), $e->getMessage()), 0, $e);
        }
        $phpFile = str_replace('.xml', '.php', $xmlFile->getRealPath());
        file_put_contents($phpFile, $phpContent);

        $phpContainer = new ContainerBuilder();
        $phpLoader = new PhpFileLoader($phpContainer, new FileLocator());
        $phpLoader->load($phpFile);
        $phpContainer->getParameterBag()->remove('.container.known_envs');

        $xmlDump = new XmlDumper($xmlContainer)->dump();
        $phpDump = new XmlDumper($phpContainer)->dump();

        // Fix file paths in the dumps to make them comparable
        $phpDump = preg_replace('/(<tag name="container\.excluded" source="in .*)\.php(&quot;"\/>)/', '\1.xml\2', $phpDump);

        self::assertEquals($xmlDump, $phpDump, sprintf('The XML and PHP dumps are not equal for file "%s"', $xmlFile));
    }

    public static function provideXmlFiles(): \Generator
    {
        $files = Finder::create()
            ->files()
            ->name('*.xml')
            ->in(self::FIXTURE_DIR)
            ->in(__DIR__.'/Fixtures/config/')
            ->sortByName()
        ;

        // Reverse order so that services30.xml is processed before services29.xml that imports it
        foreach (array_reverse(iterator_to_array($files)) as $file) {
            yield $file->getRelativePathname() => [$file];
        }
    }
}
