<?php

namespace GromNaN\SymfonyConfigXmlToPhp;

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
            if (!$parameter instanceof \DOMElement || $parameter->nodeName == 'parameter') {
                continue;
            }

            $output .= $this->processParameter($parameter);
        }

        return $output;
    }

    /**
     * Process a single parameter element
     */
    private function processParameter(\DOMElement $parameter, string $parentPath = ''): string
    {
        $id = $parameter->getAttribute('id');
        $type = $parameter->getAttribute('type') ?: 'string';
        $key = $parameter->getAttribute('key');
        $value = trim($parameter->nodeValue);
        $output = '';

        // Check for nested parameters
        $hasNestedParameters = $parameter->getElementsByTagName('parameter')->length > 0;

        if ($hasNestedParameters) {
            // Handle nested parameters
            $path = $parentPath ? "$parentPath.$key" : $key;

            if (!$parentPath) {
                $output .= sprintf('%s$parameters->set(%s, []);',
                    $this->nl(),
                    $this->formatString($key ?: $id)
                );
            }

            foreach ($parameter->getElementsByTagName('parameter') as $childParam) {
                if ($childParam->parentNode->isSameNode($parameter)) {
                    $output .= $this->processParameter($childParam, $path);
                }
            }
        } else {
            // Convert the value based on type
            $formattedValue = $this->formatParameterValue($value, $type);

            if ($parentPath) {
                $path = $parentPath . '.' . ($key ?: $id);
                $output .= sprintf('%s$parameters->set(%s, %s);',
                    $this->nl(),
                    $this->formatString($path),
                    $formattedValue
                );
            } else {
                $output .= sprintf('%s$parameters->set(%s, %s);',
                    $this->nl(),
                    $this->formatString($key ?: $id),
                    $formattedValue
                );
            }
        }

        return $output;
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
            $output .= $this->formatBooleanAttribute($defaults, 'autoconfigure', $this->nl() . '->autoconfigure()');
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
        $output = '';

        // Check if this is an alias
        if ($service->hasAttribute('alias')) {
            $alias = $service->getAttribute('alias');
            $output .= sprintf('%s$services->alias(%s, %s)',
                $this->nl(),
                $this->formatString($id),
                $this->formatString($alias)
            );

            $this->indentLevel++;
            if ($service->hasAttribute('public')) {
                $output .= $this->formatBooleanAttribute($service, 'public', $this->nl() . '->public()');
            }
            $output .= ';';
            $this->indentLevel--;

            return $output;
        }

        // Regular service definition
        $output .= sprintf('%s$services->set(%s, %s)',
            $this->nl(),
            $this->formatString($id),
            $class ? $this->formatString($class) : 'null'
        );

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
            $output .= $this->formatBooleanAttribute($service, 'autoconfigure', '->autoconfigure()');
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

    private function processServiceReference(\DOMElement $element): string
    {
        $id = $element->getAttribute('id');
        $output = 'service('.$this->formatString($id).')';

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
        if ($exclude) {
            $output .= '->exclude(' . $this->formatString($exclude) . ')';
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
            $output .= $this->formatBooleanAttribute($prototype, 'autoconfigure', $this->nl() . '->autoconfigure()');
        }

        // Handle arguments separately
        if ($prototype->getElementsByTagName('argument')->length > 0) {
            $output .= $this->processArguments($prototype);
        }

        // Process child elements (similar to service)
        foreach ($prototype->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            if ($childNode->nodeName === 'argument') {
                // Arguments are handled separately
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
                'exclude' => '->exclude(' . $this->formatString($childNode->nodeValue) . ')',
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
            $output .= $this->formatBooleanAttribute($instanceof, 'autoconfigure', $this->nl() . '->autoconfigure()');
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
                $services[] = $this->nl().$this->processServiceReference($service).',';
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
        /** @var \DOMElement[] $arguments */
        $arguments = array_filter(iterator_to_array($element->childNodes), fn(\DOMNode $node) => $node instanceof \DOMElement && $node->nodeName === 'argument');

        if (count($arguments) === 0) {
            return '';
        }

        // If there's only one argument, use ->args([...])
        if (count($arguments) === 1) {
            foreach( $arguments as $arg) {
                return $this->nl() . '->args([' . $this->formatArgument($arg) . '])';
            }
        }

        $output = $this->nl().'->args([';
        $this->indentLevel++;
        foreach ($element->childNodes as $arg) {
            if (!$arg instanceof \DOMElement) {
                continue;
            }

            if ($arg->hasAttribute('key')) {
                $key = $arg->getAttribute('key');
                $output .= $this->nl() . $this->formatString($key) . ' => ' . $this->formatArgument($arg) . ',';
            } else {
                $output .= $this->nl() . $this->formatArgument($arg) . ',';
            }
        }
        $this->indentLevel--;

        return $output . $this->nl().'])';
    }

    /**
     * Format a single argument
     */
    private function formatArgument(\DOMElement $argument): string
    {
        $type = $argument->getAttribute('type') ?: null;
        $id = $argument->getAttribute('id') ?: null;
        $value = $argument->nodeValue;

        // Handle nested arguments (collection)
        if ($type === 'collection') {
            $items = [];
            foreach ($argument->childNodes as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                if ($item->nodeName !== 'argument') {
                    throw new \LogicException(sprintf('A collection argument can only contain <argument> elements, got %s.', $item->getRootNode()->saveXML($item)));
                }

                $itemKey = $item->getAttribute('key');
                if ($itemKey) {
                    $items[] = $this->formatString($itemKey) . ' => ' . $this->formatArgument($item);
                } else {
                    $items[] = $this->formatArgument($item);
                }
            }

            return '[' . implode(', ', $items) . ']';
        }

        // Inline services are defined with a nested <service> element
        foreach ($argument->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement && $childNode->nodeName === 'service') {
                return $this->processInlineService($childNode);
            }
        }

        // Handle specific argument types
        if ($type === 'service') {
            return $this->processServiceReference($argument);
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

        if ($argument->hasAttribute('exclude')) {
            $output .= ', defaultPriorityMethod: ' . $this->formatString($argument->getAttribute('exclude'));
        }

        if ($argument->hasAttribute('exclude-self')) {
            $output .= $this->formatBooleanAttribute($argument, 'exclude-self', null, ', false');
        }

        return $output . ')';
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
        $returnsClone = $call->hasAttribute('returns-clone') &&
            ($call->getAttribute('returns-clone') === 'true' || $call->getAttribute('returns-clone') === '1');

        $result = '->call(' . $this->formatString($method);

        // Add arguments if present
        $args = $call->getElementsByTagName('argument');
        if ($args->length > 0) {
            $argValues = [];

            foreach ($args as $arg) {
                $argValues[] = $this->formatArgument($arg);
            }

            $result .= ', [' . implode(', ', $argValues) . ']';
        }

        $result .= ')';

        if ($returnsClone) {
            $result .= $this->nl().'->returns_clone()';
        }

        return $result;
    }

    /**
     * Process a tag element
     */
    private function processTag(\DOMElement $tag, bool $isResource = false): string
    {
        $name = $tag->getAttribute('name');

        if (!$name) {
            throw new \LogicException(' The tag name for must be a non-empty string.');
        }

        $method = $isResource ? '->resourceTag(' : '->tag(';

        $output = $method . $this->formatString($name);

        // Check for attributes
        $attributes = [];
        foreach ($tag->attributes as $attrName => $attrNode) {
            if ($attrName !== 'name') {
                $attributes[$attrName] = $attrNode->value;
            }
        }

        // Check for nested attributes
        $attrElements = $tag->getElementsByTagName('attribute');
        if ($attrElements->length > 0) {
            foreach ($attrElements as $attr) {
                if ($attr->parentNode->isSameNode($tag)) {
                    $attrName = $attr->getAttribute('name');
                    $attrValue = trim($attr->nodeValue);
                    $attributes[$attrName] = $attrValue;
                }
            }
        }

        if (!empty($attributes)) {
            $outputs = [];
            foreach ($attributes as $key => $value) {
                $outputs[] = $this->formatString($key) . ' => ' . $this->formatValue($value);
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
        $name = $property->getAttribute('name');

        return '->property('.$this->formatString($name).', '.$this->formatArgument($property).')';
    }

    /**
     * Process a bind element
     */
    private function processBind(\DOMElement $bind): string
    {
        $key = $bind->getAttribute('key');

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
        return "'" . str_replace("'", "\'", $value) . "'";
    }

    /**
     * Format a parameter value based on its type
     */
    private function formatParameterValue(string $value, string $type): string
    {
        return match ($type) {
            'string' => $this->formatString($value),
            'constant' => 'constant('.$this->formatString($value).')',
            // Binary values are base64 encoded in XML
            'binary' => $this->formatString(base64_decode($value)),
            default => $this->formatValue($value),
        };
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
