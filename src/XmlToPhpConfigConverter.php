<?php

namespace GromNaN\SymfonyConfigXmlToPhp;

use Symfony\Component\Config\Util\XmlUtils;

class XmlToPhpConfigConverter
{
    private int $indentLevel;

    /**
     * Convert an XML configuration file to PHP configuration
     */
    public function convertFile(string $xmlPath): string
    {
        if (!file_exists($xmlPath)) {
            throw new \RuntimeException(sprintf('File not found: %s', $xmlPath));
        }

        if (!str_ends_with($xmlPath, '.xml')) {
            throw new \RuntimeException('The file must have a .xml extension.');
        }

        // Load the XML content with preserving whitespace for comment detection
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->load($xmlPath);

        $this->validateNamespace($dom);

        $this->indentLevel = 0;
        $output = '<?php';

        // Start the PHP output with the necessary namespace and function
        $output .= $this->nl(0);
        $output .= $this->nl().'namespace Symfony\Component\DependencyInjection\Loader\Configurator;';
        $output .= $this->nl(0);
        $output .= $this->nl().'return static function(ContainerConfigurator $container) {';

        // Process the root container element and its children
        $this->indentLevel++;
        $output .= $this->nl().'$services = $container->services();';
        $output .= $this->nl().'$parameters = $container->parameters();';
        $output .= $this->processChildNodes($dom->documentElement);
        $this->indentLevel--;

        // Close the function
        $output .= $this->nl().'};';
        $output .= $this->nl(0);

        return $output;
    }

    private function validateNamespace(\DOMDocument $document): void
    {
        foreach ($document->documentElement->attributes as $attr) {
            if ($attr->name === 'schemaLocation' && str_contains($attr->value, 'http://symfony.com/schema/dic/services')) {
                return; // Valid namespace found
            }
        }

        throw new \RuntimeException('Invalid or missing XML namespace. Expected "http://symfony.com/schema/dic/services".');
    }

    /**
     * Process the child nodes of an element
     */
    private function processChildNodes(\DOMNode $node): string
    {
        $childNodes = $node->childNodes;
        $output = '';
        foreach ($childNodes as $childNode) {
            // Process comments
            if ($childNode instanceof \DOMComment) {
                $output .= $this->addComment($childNode->nodeValue);
                continue;
            }

            // Skip text nodes (whitespace)
            if ($childNode instanceof \DOMText) {
                continue;
            }

            // Process element nodes
            if ($childNode instanceof \DOMElement) {
                $output .= $this->processElement($childNode);
            }
        }

        return $output;
    }

    /**
     * Process an XML element and convert it to PHP
     */
    private function processElement(\DOMElement $element): string
    {
        return match ($element->nodeName) {
            'imports' => $this->processImports($element),
            'parameters' => $this->processParameters($element),
            'services' => $this->processServices($element),
            'when' => $this->processWhen($element),
        };
    }

    /**
     * Process imports section
     */
    private function processImports(\DOMElement $imports): string
    {
        $output = '';
        foreach ($imports->getElementsByTagName('import') as $import) {
            $resource = $import->getAttribute('resource');
            $ignoreErrors = $import->getAttribute('ignore-errors');
            $type = $import->getAttribute('type');

            $output .= sprintf('%s$container->import(%s',
                $this->nl(),
                $this->formatString($resource)
            );

            if ($type) {
                $output .= sprintf(', %s', $this->formatString($type));
            }

            if ($ignoreErrors === 'not_found') {
                $output .= ', true';
            }

            $output .= ');';
        }

        return $output . $this->nl();
    }

    /**
     * Process parameters section
     */
    private function processParameters(\DOMElement $parameters): string
    {
        $parametersKey = $parameters->getAttribute('key');
        $output = '';
        if ($parametersKey) {
            // For collection parameters with key
            $output .= sprintf('%s$parameters->set(%s, []);',
                $this->nl(),
                $this->formatString($parametersKey)
            );
        }

        foreach ($parameters->childNodes as $parameter) {
            if (!$parameter instanceof \DOMElement || $parameter->nodeName !== 'parameter') {
                continue;
            }

            $output .= $this->nl().'$parameters->set(';
            $output .= $this->formatString($parameter->getAttribute('key')).', ';
            $output .= $this->formatParameter($parameter);
            $output .= ');';
        }

        return $output;
    }

    /**
     * Process a single parameter element
     */
    private function formatParameter(\DOMElement $parameter): string
    {
        if ($parameter->tagName !== 'parameter') {
            throw new \LogicException('Expected a <parameter> element.');
        }

        $type = $parameter->getAttribute('type');
        $value = $parameter->nodeValue;


        if ($type === 'collection') {
            $items = [];
            foreach ($parameter->childNodes as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }

                $itemKey = $item->getAttribute('key');
                if ($itemKey) {
                    $items[] = $this->formatString($itemKey) . ' => ' . $this->formatParameter($item);
                } else {
                    $items[] = $this->formatParameter($item);
                }
            }

            return '[' . implode(', ', $items) . ']';
        }

        return match ($type) {
            'string' => $this->formatString($value),
            'constant' => 'constant('.$this->formatString($value).')',
            'binary' => 'base64_decode('.$this->formatString($value).')',
            default => $this->formatValue($value),
        };
    }

    /**
     * Process services section
     */
    private function processServices(\DOMElement $services): string
    {
        $output = '';
        foreach ($services->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement) {
                $output .= $this->nl(0);
                $output .= match ($childNode->nodeName) {
                    'defaults' => $this->processDefaults($childNode),
                    'service' => $this->processService($childNode),
                    'prototype' => $this->processPrototype($childNode),
                    'instanceof' => $this->processInstanceof($childNode),
                    'stack' => $this->processStack($childNode),
                };
            }
        }

        return $output;
    }

    /**
     * Process service defaults
     */
    private function processDefaults(\DOMElement $defaults): string
    {
        $this->addComment('Defaults');
        $output = $this->nl() . '$services->defaults()';

        $this->indentLevel++;
        // Process attributes
        if ($defaults->hasAttribute('public')) {
            $output .= $this->formatBooleanAttribute($defaults, 'public', $this->nl() . '->public()');
        }

        if ($defaults->hasAttribute('autowire')) {
            $output .= $this->formatBooleanAttribute($defaults, 'autowire', $this->nl() . '->autowire()');
        }

        if ($defaults->hasAttribute('autoconfigure')) {
            $output .= $this->formatBooleanAttribute($defaults, 'autoconfigure', $this->nl() . '->autoconfigure()', $this->nl() . '->autoconfigure(false)');
        }

        // Process tags
        foreach ($defaults->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            $output .= match ($childNode->nodeName) {
                'tag' => $this->nl() . $this->processTag($childNode),
                'resource-tag' => $this->nl() . $this->processTag($childNode, true),
                'bind' => $this->nl() . $this->processBind($childNode),
                default => '',
            };
        }

        $this->indentLevel--;

        return $output . ';';
    }

    /**
     * Process a service definition
     */
    private function processService(\DOMElement $service): string
    {
        $id = $service->getAttribute('id');
        $class = $service->getAttribute('class');
        $output = $this->nl() . '$services';

        // Check if this is an alias
        if ($service->hasAttribute('alias')) {
            $alias = $service->getAttribute('alias');
            $output .= '->alias('. $this->formatString($id) . ', '. $this->formatString($alias).')';
        } else {
            // Regular service definition
            $output .= '->set('.$this->formatString($id);
            if ($class) {
                $output .= ', '.$this->formatString($class);
            }
            $output .= ')';
        }

        $this->indentLevel++;
        $output .= $this->processServiceConfiguration($service);
        $this->indentLevel--;

        return $output . ';';
    }

    private function processInlineService(\DOMElement $service): string
    {
        $class = $service->getAttribute('class') ?? throw new \LogicException('Inline service must have a class attribute.');
        $output = sprintf('inline_service(%s)', $this->formatString($class));

        // Process service configuration
        $this->indentLevel++;
        $output .= $this->processServiceConfiguration($service);
        $this->indentLevel--;

        return $output;
    }

    private function processServiceConfiguration(\DOMElement $service): string
    {
        $output = '';
        // Service attributes
        if ($service->hasAttribute('shared')) {
            $output .= $this->formatBooleanAttribute($service, 'shared', $this->nl() . '->share()', $this->nl() . '->share(false)');
        }

        if ($service->hasAttribute('public')) {
            $output .= $this->formatBooleanAttribute($service, 'public', $this->nl() . '->public()');
        }

        if ($service->hasAttribute('synthetic')) {
            $output .= $this->formatBooleanAttribute($service, 'synthetic', $this->nl() . '->synthetic()');
        }

        if ($service->hasAttribute('abstract')) {
            $output .= $this->formatBooleanAttribute($service, 'abstract', $this->nl() . '->abstract()');
        }

        if ($service->hasAttribute('lazy')) {
            $lazy = $service->getAttribute('lazy');
            if ($lazy === 'true' || $lazy === '1') {
                $output .= $this->nl().'->lazy()';
            } else {
                $output .= $this->nl().'->lazy(' . $this->formatString($lazy) . ')';
            }
        }

        if ($service->hasAttribute('parent')) {
            $output .= $this->nl().'->parent(' . $this->formatString($service->getAttribute('parent')) . ')';
        }

        if ($service->hasAttribute('decorates')) {
            $decorates = $service->getAttribute('decorates');
            $decorationInnerName = $service->hasAttribute('decoration-inner-name') ?
                $service->getAttribute('decoration-inner-name') : null;
            $decorationPriority = $service->hasAttribute('decoration-priority') ?
                (int)$service->getAttribute('decoration-priority') : 0;
            $decorationOnInvalid = $service->hasAttribute('decoration-on-invalid') ?
                $service->getAttribute('decoration-on-invalid') : null;

            $output .= $this->nl().'->decorate('.$this->formatString($decorates);

            if ($decorationInnerName !== null || $decorationPriority !== 0 || $decorationOnInvalid !== null) {
                $output .= ', ' . ($decorationInnerName ? $this->formatString($decorationInnerName) : 'null');
            }

            if ($decorationPriority !== 0 || $decorationOnInvalid !== null) {
                $output .= ', ' . $decorationPriority;
            }

            if ($decorationOnInvalid !== null) {
                $output .= ', ' . $this->formatString($decorationOnInvalid);
            }

            $output .= ')';
        }

        if ($service->hasAttribute('autowire')) {
            $output .= $this->formatBooleanAttribute($service, 'autowire', '->autowire()');
        }

        if ($service->hasAttribute('autoconfigure')) {
            $output .= $this->formatBooleanAttribute($service, 'autoconfigure', $this->nl() . '->autoconfigure()', $this->nl() . '->autoconfigure(false)');
        }

        if ($service->hasAttribute('constructor')) {
            $output .= $this->nl().'->constructor(' . $this->formatString($service->getAttribute('constructor')) . ')';
        }

        // Handle arguments separately for better formatting
        $output .= $this->processArguments($service);

        // Process child elements
        foreach ($service->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            if ($childNode->nodeName === 'argument') {
                // Arguments are handled separately for better formatting
                continue;
            }

            $output .= $this->nl() . match ($childNode->nodeName) {
                'file' => '->file(' . $this->formatString(trim($childNode->nodeValue)) . ')',
                'factory' => $this->processFactory($childNode),
                'from-callable' => $this->processCallable($childNode, 'from_callable'),
                'configurator' => $this->processCallable($childNode, 'configurator'),
                'call' => $this->processCall($childNode),
                'tag' => $this->processTag($childNode),
                'resource-tag' => $this->processTag($childNode, true),
                'property' => $this->processProperty($childNode),
                'bind' => $this->processBind($childNode),
                'deprecated' => $this->processDeprecated($childNode),
            };
        }

        return $output;
    }

    private function processServiceReference(\DOMElement $element, string $type): string
    {
        $id = $element->getAttribute('id');
        $output = $type.'('.$this->formatString($id).')';

        $onInvalid = $element->getAttribute('on-invalid') ?: 'exception';
        $output .= match ($onInvalid) {
            'ignore' => '->ignoreOnInvalid()',
            'null' => '->nullOnInvalid()',
            'ignore_uninitialized' => '->ignoreOnUninitialized()',
            'exception' => '',
        };

        return $output;
    }

    /**
     * Process a service prototype
     */
    private function processPrototype(\DOMElement $prototype): string
    {
        $namespace = $prototype->getAttribute('namespace');
        $resource = $prototype->getAttribute('resource');
        $exclude = $prototype->getAttribute('exclude');

        $output = $this->nl().'$services->load('.$this->formatString($namespace).', '.$this->formatString($resource).')';

        $this->indentLevel++;

        // Merge all exclude attribute and tags into a single exclude call

        $this->indentLevel++;
        $excludes = [];
        if ($exclude) {
            $excludes[] = $this->nl().$this->formatString($exclude).',';
        }
        foreach ($prototype->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->nodeName === 'exclude') {
                $excludes[] = $this->nl().$this->formatString($childNode->nodeValue).',';
            }
        }
        $this->indentLevel--;
        if ($excludes) {
            $output .= $this->nl().'->exclude([' . implode('', $excludes) . $this->nl(). '])';
        }


        // Process attributes (same as for regular services)
        if ($prototype->hasAttribute('share')) {
            $output .= $this->formatBooleanAttribute($prototype, 'shared', $this->nl() . '->share()', $this->nl() . '->share(false)');
        }

        if ($prototype->hasAttribute('public')) {
            $output .= $this->formatBooleanAttribute($prototype, 'public', $this->nl() . '->public()');
        }

        if ($prototype->hasAttribute('abstract')) {
            $output .= $this->formatBooleanAttribute($prototype, 'abstract', $this->nl() . '->abstract()');
        }

        if ($prototype->hasAttribute('parent')) {
            $output .= $this->nl().'->parent(' . $this->formatString($prototype->getAttribute('parent')) . ')';
        }

        if ($prototype->hasAttribute('autowire')) {
            $output .= $this->formatBooleanAttribute($prototype, 'autowire', $this->nl() . '->autowire()');
        }

        if ($prototype->hasAttribute('autoconfigure')) {
            $output .= $this->formatBooleanAttribute($prototype, 'autoconfigure', $this->nl() . '->autoconfigure()', $this->nl() . '->autoconfigure(false)');
        }

        // Handle arguments separately
        $output .= $this->processArguments($prototype);

        // Process child elements (similar to service)
        foreach ($prototype->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            if (in_array($childNode->nodeName, ['argument', 'exclude'], true)) {
                // Arguments and exclude are handled separately
                continue;
            }

            $output .= $this->nl() . match ($childNode->nodeName) {
                'factory' => $this->processFactory($childNode),
                'configurator' => $this->processCallable($childNode, 'configurator'),
                'call' => $this->processCall($childNode),
                'tag' => $this->processTag($childNode),
                'resource-tag' => $this->processTag($childNode, true),
                'property' => $this->processProperty($childNode),
                'bind' => $this->processBind($childNode),
                'deprecated' => $this->processDeprecated($childNode),
            };
        }

        $this->indentLevel--;

        return $output . ';';
    }

    /**
     * Process instanceof definition
     */
    private function processInstanceof(\DOMElement $instanceof): string
    {
        $id = $instanceof->getAttribute('id');

        $output = $this->nl().'$services->instanceof('.$this->formatString($id).')';

        $this->indentLevel++;
        // Process attributes (subset of service attributes)
        if ($instanceof->hasAttribute('shared')) {
            $output .= $this->formatBooleanAttribute($instanceof, 'shared', $this->nl() . '->share()', $this->nl() . '->share(false)');
        }

        if ($instanceof->hasAttribute('public')) {
            $output .= $this->formatBooleanAttribute($instanceof, 'public', $this->nl() . '->public()');
        }

        if ($instanceof->hasAttribute('autowire')) {
            $output .= $this->formatBooleanAttribute($instanceof, 'autowire', $this->nl() . '->autowire()');
        }

        if ($instanceof->hasAttribute('autoconfigure')) {
            $output .= $this->formatBooleanAttribute($instanceof, 'autoconfigure', $this->nl() . '->autoconfigure()', $this->nl() . '->autoconfigure(false)');
        }

        // Process child elements
        foreach ($instanceof->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            $output .= $this->nl().match($childNode->nodeName) {
                'configurator' => $this->processCallable($childNode, 'configurator'),
                'call' => $this->processCall($childNode),
                'tag' => $this->processTag($childNode),
                'property' => $this->processProperty($childNode),
                'bind' => $this->processBind($childNode),
            };
        }

        $this->indentLevel--;

        return $output . ';';
    }

    /**
     * Process stack definition
     */
    private function processStack(\DOMElement $stack): string
    {
        $id = $stack->getAttribute('id');

        $output = $this->nl().'$services->stack('.$this->formatString($id).', [';

        // Process stack services
        $services = [];
        $this->indentLevel++;
        foreach ($stack->childNodes as $service) {
            if (!($service instanceof \DOMElement) || $service->nodeName !== 'service') {
                continue;
            }
            $serviceId = $service->getAttribute('id');
            if ($serviceId) {
                $services[] = $this->nl().$this->processServiceReference($service, 'service').',';
            } else {
                $services[] = $this->nl().$this->processInlineService($service).',';
            }
        }

        $this->indentLevel--;

        if (empty($services)) {
            $output .= '])';
        } else {
            $output .= implode('', $services).$this->nl() . '])';
        }

        $this->indentLevel++;

        if ($stack->hasAttribute('public')) {
            $output .= $this->formatBooleanAttribute($stack, 'public', '->public()');
        }

        // Process deprecated if present
        foreach ($stack->childNodes as $deprecated) {
            if ($deprecated instanceof \DOMElement && $deprecated->nodeName === 'deprecated') {
                $output .= $this->processDeprecated($deprecated);
            }
        }

        $this->indentLevel--;

        return $output . ';';
    }

    /**
     * Process arguments of a service or prototype
     */
    private function processArguments(\DOMElement $element): string
    {
        $arguments = $this->formatArguments($element);

        if ($arguments === null) {
            return '';
        }

        return $this->nl() . '->args(' . $arguments . ')';
    }

    /**
     * Format a list of arguments, return null empty string if none
     */
    private function formatArguments(\DOMElement $element): ?string
    {
        /** @var \DOMElement[] $arguments */
        $arguments = array_filter(iterator_to_array($element->childNodes), fn(\DOMNode $node) => $node instanceof \DOMElement && $node->nodeName === 'argument');

        if (count($arguments) === 0) {
            return null;
        }

        // If there's only one argument, use ->args([...])
        if (count($arguments) === 1 && !current($arguments)->getAttribute('key')) {
            foreach( $arguments as $arg) {
                return '[' . $this->formatArgument($arg) . ']';
            }
        }

        $output = $this->nl() . '[';
        $this->indentLevel++;
        foreach ($arguments as $arg) {
            if (!$arg instanceof \DOMElement) {
                continue;
            }

            if ($key = $arg->getAttribute('key')) {
                $output .= $this->nl() . $this->formatString($key) . ' => ' . $this->formatArgument($arg) . ',';
            } else {
                $output .= $this->nl() . $this->formatArgument($arg) . ',';
            }
        }
        $this->indentLevel--;

        return $output . $this->nl().']';
    }

    /**
     * Format a single argument
     */
    private function formatArgument(\DOMElement $argument): string
    {
        $type = $argument->getAttribute('type') ?: null;
        $value = $argument->nodeValue;

        // Handle nested arguments (collection)
        if (in_array($type, ['collection', null], true)) {
            $items = [];
            foreach ($argument->childNodes as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                if ($item->nodeName !== $argument->nodeName) {
                    continue;
                }

                $itemKey = $item->getAttribute('key') ?: $item->getAttribute('name');

                $itemKey = match($item->getAttribute('key-type')) {
                    'constant' => '\\'.ltrim($itemKey, '\\'),
                    'binary' => 'base64_decode('.$this->formatString($itemKey).')',
                    default => $this->formatString($itemKey),
                };

                if ($itemKey) {
                    $items[] =  $itemKey . ' => ' . $this->formatArgument($item);
                } else {
                    $items[] = $this->formatArgument($item);
                }
            }

            if ($items) {
                return '[' . implode(', ', $items) . ']';
            }

            // Force empty array for a "collection" type, even if no child nodes
            if ($type === 'collection') {
                return '[]';
            }
        }

        // Inline services are defined with a nested <service> element
        foreach ($argument->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->nodeName === 'service') {
                return $this->processInlineService($childNode);
            }
        }

        // Handle specific argument types
        if ($type === 'service' || $type === 'service_closure') {
            return $this->processServiceReference($argument, $type);
        }

        if ($type === 'expression') {
            return "expr({$this->formatString($value)})";
        }

        if ($type === 'string') {
            return $this->formatString($value);
        }

        if ($type === 'constant') {
            return "constant({$this->formatString($value)})";
        }

        if ($type === 'binary') {
            return 'base64_decode('.$this->formatString($value).')';
        }

        if ($type === 'tagged' || $type === 'tagged_iterator') {
            return $this->processTagged('tagged_iterator', $argument);
        }

        if ($type === 'tagged_locator') {
            return $this->processTagged('tagged_locator', $argument);
        }

        if ($type === 'service_locator') {
            return $this->processServiceLocator($argument);
        }

        // Default handling (treat as string or convert to appropriate PHP value)
        return $this->formatValue($value);
    }

    private function processTagged(string $method, \DOMElement $argument): string
    {
        $output = $method.'(' . $this->formatString($argument->getAttribute('tag'));

        if ($argument->hasAttribute('index-by')) {
            $output .= ', indexAttribute: ' . $this->formatString($argument->getAttribute('index-by'));
        }

        if ($argument->hasAttribute('default-index-method')) {
            $output .= ', defaultIndexMethod: ' . $this->formatString($argument->getAttribute('default-index-method'));
        }

        if ($argument->hasAttribute('default-priority-method')) {
            $output .= ', defaultPriorityMethod: ' . $this->formatString($argument->getAttribute('default-priority-method'));
        }

        // Exclude can be an attribute or multiple <exclude> child elements
        $exclude = [];
        if ($argument->hasAttribute('exclude')) {
            $exclude[] = $this->formatString($argument->getAttribute('exclude'));
        }
        foreach ($argument->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->nodeName === 'exclude') {
                $exclude[] = $this->formatString($childNode->nodeValue);
            }
        }
        if ($exclude) {
            $output .= ', exclude: ' . match(count($exclude)) {
                1 => current($exclude),
                default => '[' . implode(', ', $exclude) . ']',
            };
        }

        if ($argument->hasAttribute('exclude-self')) {
            $output .= $this->formatBooleanAttribute($argument, 'exclude-self', null, ', excludeSelf: false');
        }

        return $output . ')';
    }

    private function processServiceLocator(\DOMElement $argument): string
    {
        $output = 'service_locator([';

        $this->indentLevel++;
        foreach ($argument->childNodes as $item) {
            if (!$item instanceof \DOMElement || $item->nodeName !== 'argument') {
                continue;
            }

            $itemKey = $item->getAttribute('key');
            if ($itemKey) {
                $output .= $this->nl() . $this->formatString($itemKey) . ' => ' . $this->formatArgument($item) . ',';
            } else {
                $output .= $this->nl() . $this->formatArgument($item) . ',';
            }
        }
        $this->indentLevel--;

        return $output . $this->nl().'])';
    }

    /**
     * Process a factory element
     */
    private function processFactory(\DOMElement $factory): string
    {
        // Class::method form
        if ($factory->hasAttribute('class') && $factory->hasAttribute('method')) {
            $class = $factory->getAttribute('class');
            $method = $factory->getAttribute('method');
            return '->factory([' . $this->formatString($class) . ', ' . $this->formatString($method) . '])';
        }

        // self::method form
        if ($factory->hasAttribute('method')) {
            $method = $factory->getAttribute('method');
            return '->factory([null, ' . $this->formatString($method) . '])';
        }

        // Service::method form
        if ($factory->hasAttribute('service') && $factory->hasAttribute('method')) {
            $service = $factory->getAttribute('service');
            $method = $factory->getAttribute('method');
            return '->factory([service(' . $this->formatString($service) . '), ' . $this->formatString($method) . '])';
        }

        // Function form
        if ($factory->hasAttribute('function')) {
            $function = $factory->getAttribute('function');
            return '->factory(' . $this->formatString($function) . ')';
        }

        // Expression form
        if ($factory->hasAttribute('expression')) {
            $expression = $factory->getAttribute('expression');
            return '->factory(expr(' . $this->formatString($expression) . '))';
        }

        throw new \LogicException(sprintf('Invalid factory definition in XML: %s', $factory->ownerDocument->saveXML($factory)));
    }

    /**
     * Process a callable (configurator, from-callable)
     */
    private function processCallable(\DOMElement $callable, string $methodName): string
    {
        // Class::method form
        if ($callable->hasAttribute('class') && $callable->hasAttribute('method')) {
            $class = $callable->getAttribute('class');
            $method = $callable->getAttribute('method');
            return '->'.$methodName.'([' . $this->formatString($class) . ', ' . $this->formatString($method) . '])';
        }

        // self::method form
        if ($callable->hasAttribute('method')) {
            $method = $callable->getAttribute('method');
            return '->'.$methodName.'([null, ' . $this->formatString($method) . '])';
        }

        // Service::method form
        if ($callable->hasAttribute('service') && $callable->hasAttribute('method')) {
            $service = $callable->getAttribute('service');
            $method = $callable->getAttribute('method');
            return '->'.$methodName.'([service(' . $this->formatString($service) . '), ' . $this->formatString($method) . '])';
        }

        // Function form
        if ($callable->hasAttribute('function')) {
            $function = $callable->getAttribute('function');
            return '->'.$methodName.'(' . $this->formatString($function) . ')';
        }

        return '';
    }

    /**
     * Process a method call
     */
    private function processCall(\DOMElement $call): string
    {
        $method = $call->getAttribute('method');

        $arguments = $this->formatArguments($call);
        $output = '->call(' . $this->formatString($method);
        // Add arguments if present
        if ($arguments !== null) {
            $output .= ', ' . $arguments;
        }

        $output .= $this->formatBooleanAttribute($call, 'returns-clone', $arguments === null ? ', [], true' : ', true');
        $output .= ')';

        return $output;
    }

    /**
     * Process a tag element
     */
    private function processTag(\DOMElement $tag, bool $isResource = false): string
    {
        $tagNameComesFromAttribute = $tag->childElementCount || '' === $tag->nodeValue;
        $tagName = $tagNameComesFromAttribute ? $tag->getAttribute('name') : $tag->nodeValue;

        if (!$tagName) {
            throw new \LogicException(' The tag name for must be a non-empty string.');
        }

        $method = $isResource ? '->resourceTag(' : '->tag(';

        $output = $method . $this->formatString($tagName);

        // Check for attributes
        $attributes = [];
        foreach ($tag->attributes as $attrName => $attrNode) {
            if ($tagNameComesFromAttribute && $attrName === 'name') {
                continue;
            }

            $attributes[$attrName] = $this->formatValue($attrNode->nodeValue);
        }

        // Check for nested attributes
        foreach ($tag->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement) || $childNode->nodeName !== 'attribute') {
                continue;
            }

            $attrName = $childNode->getAttribute('name');
            if ($childNode->childNodes->length > 0) {
                $attributes[$attrName] = $this->formatArgument($childNode);
            } else {
                $attributes[$attrName] = $this->formatValue($childNode->nodeValue);
            }
        }

        if (!empty($attributes)) {
            $outputs = [];
            foreach ($attributes as $key => $value) {
                if (str_contains($key, '-') && !str_contains($key, '_') && !\array_key_exists($normalizedName = str_replace('-', '_', $key), $attributes)) {
                    $key = $normalizedName;
                }

                $outputs[] = $this->formatString($key) . ' => ' . $value;
            }
            $output .= ', ['.implode(', ', $outputs).']';
        }

        return $output.')';
    }

    /**
     * Process a property element
     */
    private function processProperty(\DOMElement $property): string
    {
        $name = $property->getAttribute('key') ?: $property->getAttribute('name');

        return '->property('.$this->formatString($name).', '.$this->formatArgument($property).')';
    }

    /**
     * Process a bind element
     */
    private function processBind(\DOMElement $bind): string
    {
        $key = $bind->getAttribute('key') ?: $bind->getAttribute('name');

        return '->bind('.$this->formatString($key).', '.$this->formatArgument($bind).')';
    }

    /**
     * Process a deprecated element
     */
    private function processDeprecated(\DOMElement $deprecated): string
    {
        $message = trim($deprecated->nodeValue);
        $package = $deprecated->getAttribute('package');
        $version = $deprecated->getAttribute('version');

        return '->deprecate(' .
            $this->formatString($package) . ', ' .
            $this->formatString($version) . ', ' .
            $this->formatString($message) .
        ')';
    }

    /**
     * Process a when element (environment-specific configuration)
     */
    private function processWhen(\DOMElement $when): string
    {
        $env = $when->getAttribute('env');

        $output = $this->nl(0);
        $output .= $this->addComment("Configuration for environment: {$env}");
        $output .= $this->nl() . 'if ($container->env() === ' . $this->formatString($env) . ') {';

        $this->indentLevel++;
        $output .= $this->processChildNodes($when);
        $this->indentLevel--;

        return $output . $this->nl() . '}';
    }

    /**
     * Add a comment to the output
     */
    private function addComment(string $comment): string
    {
        $lines = explode("\n", $comment);

        if (count($lines) === 1) {
            return $this->nl() . '// ' . trim($comment);
        }

        $output = $this->nl() . '/*';
        foreach ($lines as $line) {
            $output .= $this->nl() . ' * ' . trim($line);
        }
        $output .= $this->nl() . ' */';

        return $output;
    }

    /**
     * Format a boolean attribute based on its value
     */
    private function formatBooleanAttribute(
        \DOMElement $element,
        string $attribute,
        ?string $trueCode = null,
        ?string $falseCode = null
    ): string {
        $value = $element->getAttribute($attribute);

        if ($trueCode !== null && in_array($value, ['true', '1'], true)) {
            return $trueCode;
        } elseif ($falseCode !== null && in_array($value, ['false', '0'], true)) {
            return $falseCode;
        }

        return '';
    }

    /**
     * Format a string for PHP output (with quotes)
     */
    private function formatString(string $value): string
    {
        if (class_exists($value)) {
            return '\\'.ltrim($value, '\\') . '::class';
        }

        if (str_ends_with($value, '\\')) {
            $value = addcslashes($value, '\'\\');
        } else {
            $value = addcslashes($value, '\'');
        }

        return "'" . $value . "'";
    }

    /**
     * Format a value for PHP output, detecting type
     */
    private function formatValue(string $value): string
    {
        // Try to detect the value type
        if (strtolower($value) === 'true') {
            return 'true';
        }

        if (strtolower($value) === 'false') {
            return 'false';
        }

        if (strtolower($value) === 'null') {
            return 'null';
        }

        if (is_numeric($value)) {
            // Check if it's an integer or a float
            if ((string)(int)$value === $value || (string)(float)$value === $value) {
                return $value;
            }
        }

        // Default to string
        return $this->formatString($value);
    }

    /**
     * Add a new line with proper indentation
     */
    private function nl(?int $indentLevel = null): string
    {
        return "\n".str_repeat('    ', $indentLevel ?? $this->indentLevel);
    }
}
