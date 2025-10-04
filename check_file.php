<?php
// Check if services.php exists
$phpFile = __DIR__ . '/tests/input/services.php';

if (file_exists($phpFile)) {
    echo "File exists. Content of $phpFile:\n\n";
    echo file_get_contents($phpFile);
} else {
    echo "File does not exist: $phpFile\n";

    // Check XML file
    $xmlFile = __DIR__ . '/tests/input/services.xml';
    if (file_exists($xmlFile)) {
        echo "But XML file exists: $xmlFile\n";
    } else {
        echo "XML file also missing: $xmlFile\n";
    }

    // List files in tests/input
    echo "\nFiles in tests/input directory:\n";
    $files = scandir(__DIR__ . '/tests/input');
    if ($files) {
        foreach ($files as $file) {
            echo "- $file\n";
        }
    } else {
        echo "Could not list files or directory is empty.\n";
    }
}
