# Convert XML to PHP config files in Symfony Bundles

XML configuration format is removed from Symfony 8.0. Bundles must convert their XML config files to PHP.

## Usage

Run the script:

    php convert_xml_to_php.php src/Symfony/Bundle/Resources/config/

Fix coding style (mainly array syntax):

    php-cs-fixer fix src/Symfony/Bundle/Resources/config/
