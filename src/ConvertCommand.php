<?php

namespace GromNaN\SymfonyConfigXmlToPhp;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        $source = $input->getArgument('source');
        $target = $input->getArgument('target');
        $converter = new XmlToPhpConfigConverter();

        // Process files
        if (is_dir($source)) {
            // Directory mode - convert all XML files in the directory
            $sourceDir = rtrim($source, '/') . '/';
            $files = glob($sourceDir . '*.xml');

            foreach ($files as $xmlPath) {
                $this->processFile($output, $xmlPath, $sourceDir, $target, $converter);
            }
        } else if (is_file($source) && pathinfo($source, PATHINFO_EXTENSION) === 'xml') {
            // Single file mode
            $sourceDir = dirname($source) . '/';
            $this->processFile($output, $source, $sourceDir, $target, $converter);
        } else {
            $output->writeln('Error: Source must be an XML file or a directory containing XML files.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function processFile(OutputInterface $output, $xmlPath, $sourceDir, $targetDir, $converter) {
        try {
            $output->writeln('Converting: '.$xmlPath);

            // Generate PHP content
            $phpContent = $converter->convertFile($xmlPath);

            // Determine output path
            $relativeFilePath = str_replace($sourceDir, '', $xmlPath);
            $phpFilename = pathinfo($relativeFilePath, PATHINFO_FILENAME) . '.php';

            if ($targetDir !== null) {
                // Ensure target directory exists
                $outputDir = $targetDir . dirname($relativeFilePath);
                if (!is_dir($outputDir)) {
                    mkdir($outputDir, 0755, true);
                }

                $phpPath = $outputDir . '/' . $phpFilename;
            } else {
                // Use the same directory as the source file
                $phpPath = dirname($xmlPath) . '/' . $phpFilename;
            }

            // Write the output file
            file_put_contents($phpPath, $phpContent);
            $output->writeln( 'Generated: '.$phpPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Error converting "%s": %s', $xmlPath, $e->getMessage()), 0, $e);
        }
    }

}