<?php

namespace GromNaN\SymfonyConfigXmlToPhp;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Config\Exception\LoaderLoadException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\XmlDumper;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

final class ConvertCommand extends Command
{
    protected function configure()
    {
        $this->setName('convert')
            ->setDescription('Converts XML service definitions to PHP DSL format')
            ->addArgument('source', InputArgument::REQUIRED, 'Source directory containing XML files')
            ->addArgument('target', InputArgument::OPTIONAL, 'Target directory for converted PHP files')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');
        $converter = new XmlToPhpConfigConverter();

        // Process files
        if (is_dir($source)) {
            $files = Finder::create()
                ->files()
                ->in($source)
                ->notPath(['/routing/', '/doctrine/'])
                ->name('*.xml');

            foreach ($files as $file) {
                $this->processFile($io, $file, $target, $converter);
            }
        } else if (is_file($source) && pathinfo($source, PATHINFO_EXTENSION) === 'xml') {
            $file = new SplFileInfo($source, dirname($source), $source);
            $this->processFile($io, $file, $target, $converter);
        } else {
            $output->writeln('Error: Source must be an XML file or a directory containing XML files.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Process a single XML file and convert it to PHP.
     *
     * @return string The PHP file path
     */
    private function processFile(SymfonyStyle $io, SplFileInfo $file, ?string $targetDir, XmlToPhpConfigConverter $converter): void
    {
        try {
            $io->writeln('Converting: '.$file->getPathname());

            // Generate PHP content
            $phpContent = $converter->convertFile($file->getPathname());

            // Determine the output path
            $phpFilename = $file->getBasename('.xml') . '.php';

            if ($targetDir !== null) {
                // Ensure target directory exists
                $outputDir = $targetDir . $file->getRelativePath();
                if (!is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }

                $phpPath = $outputDir . '/' . $phpFilename;
            } else {
                // Use the same directory as the source file
                $phpPath = $file->getPath() . '/' . $phpFilename;
            }

            // Write the output file
            file_put_contents($phpPath, $phpContent);
            $io->writeln( 'Generated: '.$phpPath);

            $this->validateFile($io, $file->getPathname(), $phpPath);
        } catch (\Throwable $e) {
            $io->error(sprintf('Error converting "%s": %s', $file->getRelativePathname(), $e->getMessage()));
            if ($io->isVeryVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
        }
    }

    private function validateFile(SymfonyStyle $io, string $xmlFile, string $phpFile): bool
    {

        try {
            $xmlContainer = new ContainerBuilder();
            $xmlLoader = new XmlFileLoader($xmlContainer, new FileLocator());
            $xmlLoader->load(realpath($xmlFile));
        } catch (LoaderLoadException|InvalidArgumentException $e) {
            $io->writeln(sprintf('Error loading XML file "%s": %s', $xmlFile, $e->getMessage()));

            return false;
        }

        $phpContainer = new ContainerBuilder();
        $phpLoader = new PhpFileLoader($phpContainer, new FileLocator());
        $phpLoader->load(realpath($phpFile));

        $xmlDump = new XmlDumper($xmlContainer)->dump();
        $phpDump = new XmlDumper($phpContainer)->dump();

        if ($xmlDump === $phpDump) {
            return true;
        }


        $differ = new Differ(new UnifiedDiffOutputBuilder());
        $diff = $differ->diff($xmlDump, $phpDump);
        $io->error('Validation failed: '.$phpFile);

        $replace = [
            '~^([\-\w]*?)$~m' => '<fg=reg>$1</>',
            '~^([\+\w]*?)$~m' => '<fg=green>$1</>',
        ];

        $io->writeln(preg_replace(array_keys($replace), array_values($replace), $diff));

        return false;
    }
}