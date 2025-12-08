# Convert XML to PHP config files in Symfony Bundles

XML configuration format is removed from Symfony 8.0. Bundles must convert their XML config files to PHP.

**⚠️ This script is a best-effort tool and does not guarantee a perfect conversion. Manual review and adjustments may be necessary.**

## Installation

The best way to use this tool is as a standalone project in a separate directory.

    mkdir xml-to-php-converter
    cd xml-to-php-converter

    composer init --type=project --require='gromnan/symfony-config-xml-to-php:*' --no-interaction
    composer install

## Usage

Run the script for a directory:

    vendor/bin/convert ../src/Symfony/Bundle/Resources/config/

Or for a single file:

    vendor/bin/convert ../src/Symfony/Bundle/Resources/config/services.xml

## Contributing

Feel free to open issues or submit pull requests for improvements or bug fixes.

## License

MIT License. See the LICENSE file for details.
