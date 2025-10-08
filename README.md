# Convert XML to PHP config files in Symfony Bundles

XML configuration format is removed from Symfony 8.0. Bundles must convert their XML config files to PHP.

**⚠️ This script is a best-effort tool and does not guarantee a perfect conversion. Manual review and adjustments may be necessary.**

## Usage

Install:

    composer require --dev gromnan/symfony-config-xml-to-php

Run the script for a directory:

    vendor/bin/convert src/Symfony/Bundle/Resources/config/


Or for a single file:

    vendor/bin/convert src/Symfony/Bundle/Resources/config/services.xml

## Contributing

Feel free to open issues or submit pull requests for improvements or bug fixes.

## License

MIT License. See the LICENSE file for details.
