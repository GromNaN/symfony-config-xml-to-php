<?php
// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Running comprehensive test for XML to PHP conversion\n";
echo "================================================\n\n";

// Step 1: Check if the XML file exists
$xmlFile = __DIR__ . '/tests/input/services.xml';

if (!file_exists($xmlFile)) {
    die("ERROR: Source XML file not found at $xmlFile\n");
}

echo "Source XML file found at: $xmlFile\n";

// Step 2: Run our converter
echo "\nRunning the converter...\n";
$convertCommand = "./bin/convert tests/input/services.xml";
echo "Command: $convertCommand\n";
$output = [];
$returnVar = 0;
exec($convertCommand, $output, $returnVar);
echo "Conversion completed with return code: $returnVar\n";

if ($returnVar !== 0) {
    echo "WARNING: Converter script exited with non-zero status.\n";
}

// Step 3: Check if the PHP file was generated
$phpFile = __DIR__ . '/tests/input/services.php';
if (!file_exists($phpFile)) {
    die("ERROR: Output PHP file was not generated at $phpFile\n");
}

echo "\nOutput PHP file found at: $phpFile\n";
echo "File size: " . filesize($phpFile) . " bytes\n";

// Step 4: Run PHP syntax check on the output file
echo "\nChecking PHP syntax...\n";
$lintCommand = "php -l " . escapeshellarg($phpFile);
$lintOutput = [];
$lintReturnVar = 0;
exec($lintCommand, $lintOutput, $lintReturnVar);

if ($lintReturnVar !== 0) {
    echo "PHP syntax check failed!\n";
    foreach ($lintOutput as $line) {
        echo $line . "\n";
    }
    die();
}

echo "PHP syntax check passed.\n";

// Step 5: Display the generated PHP file contents
echo "\nGenerated PHP file contents:\n";
echo "-------------------------\n";
$phpContent = file_get_contents($phpFile);
echo $phpContent . "\n";
echo "-------------------------\n\n";

echo "Test completed successfully.\n";
