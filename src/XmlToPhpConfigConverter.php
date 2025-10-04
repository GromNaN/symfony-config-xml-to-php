<?php

namespace GromNaN\SymfonyConfigXmlToPhp;

class XmlToPhpConfigConverter
{
    private int $indentLevel;
    private string $output;

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

        $this->indentLevel = 0;
        $this->output = '';

        // Start the PHP output with the necessary namespace and function
        $this->output .= $this->nl().'<?php';
        $this->output .= $this->nl(0);
        $this->output .= $this->nl().'namespace Symfony\Component\DependencyInjection\Loader\Configurator;';
        $this->output .= $this->nl(0);
        $this->output .= $this->nl().'return static function(ContainerConfigurator $container) {';
        $this->output .= $this->nl().'    $services = $container->services();';
        $this->output .= $this->nl().'    $parameters = $container->parameters();';

        // Process the root container element and its children
        $this->indentLevel++;
        $root = $dom->documentElement;
        $this->processChildNodes($root);
        $this->indentLevel--;

        // Close the function
        $this->output .= $this->nl().'};';

        return $this->output;
    }

    /**
     * Process the child nodes of an element
     */
    private function processChildNodes(\DOMNode $node): void
    {
        $childNodes = $node->childNodes;
        foreach ($childNodes as $childNode) {
            // Process comments
            if ($childNode instanceof \DOMComment) {
                $this->addComment($childNode->nodeValue);
                continue;
            }

            // Skip text nodes (whitespace)
            if ($childNode instanceof \DOMText) {
                continue;
            }

            // Process element nodes
            if ($childNode instanceof \DOMElement) {
                $this->processElement($childNode);
            }
        }
    }

    /**
     * Process an XML element and convert it to PHP
     */
    private function processElement(\DOMElement $element): void
    {
        match ($element->nodeName) {
            'imports' => $this->processImports($element),
            'parameters' => $this->processParameters($element),
            'services' => $this->processServices($element),
            'when' => $this->processWhen($element),
        };
    }

    /**
     * Process imports section
     */
    private function processImports(\DOMElement $imports): void
    {
        $this->addComment('Imports');
        foreach ($imports->getElementsByTagName('import') as $import) {
            $resource = $import->getAttribute('resource');
            $ignoreErrors = $import->getAttribute('ignore-errors');
            $type = $import->getAttribute('type');

            $this->output .= sprintf('%s$container->import(%s',
                $this->nl(),
                $this->formatString($resource)
            );

            if ($type) {
                $this->output .= sprintf(', %s', $this->formatString($type));
            }

            if ($ignoreErrors === 'not_found') {
                $this->output .= ', true';
            }

            $this->output .= ');';
        }
        $this->output .= $this->nl();
    }

    /**
     * Process parameters section
     */
    private function processParameters(\DOMElement $parameters): void
    {
        $parametersKey = $parameters->getAttribute('key');
        if ($parametersKey) {
            // For collection parameters with key
            $this->output .= sprintf('%s$parameters->set(%s, []);',
                $this->nl(),
                $this->formatString($parametersKey)
            );
        }

        foreach ($parameters->getElementsByTagName('parameter') as $parameter) {
            $this->processParameter($parameter);
        }
    }

    /**
     * Process a single parameter element
     */
    private function processParameter(\DOMElement $parameter, string $parentPath = ''): void
    {
        $id = $parameter->getAttribute('id');
        $type = $parameter->getAttribute('type') ?: 'string';
        $key = $parameter->getAttribute('key');
        $value = trim($parameter->nodeValue);

        // Check for nested parameters
        $hasNestedParameters = $parameter->getElementsByTagName('parameter')->length > 0;

        if ($hasNestedParameters) {
            // Handle nested parameters
            $path = $parentPath ? "$parentPath.$key" : $key;

            if (!$parentPath) {
                $this->output .= sprintf('%s$parameters->set(%s, []);',
                    $this->nl(),
                    $this->formatString($key ?: $id)
                );
            }

            foreach ($parameter->getElementsByTagName('parameter') as $childParam) {
                if ($childParam->parentNode->isSameNode($parameter)) {
                    $this->processParameter($childParam, $path);
                }
            }
        } else {
            // Convert the value based on type
            $formattedValue = $this->formatParameterValue($value, $type);

            if ($parentPath) {
                $path = $parentPath . '.' . ($key ?: $id);
                $this->output .= sprintf('%s$parameters->set(%s, %s);',
                    $this->nl(),
                    $this->formatString($path),
                    $formattedValue
                );
            } else {
                $this->output .= sprintf('%s$parameters->set(%s, %s);',
                    $this->nl(),
                    $this->formatString($key ?: $id),
                    $formattedValue
                );
            }
        }
    }

    /**
     * Process services section
     */
    private function processServices(\DOMElement $services): void
    {
        // Process services
        foreach ($services->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement) {
                $this->output .= $this->nl(0);
                match ($childNode->nodeName) {
                    'defaults' => $this->processDefaults($childNode),
                    'service' => $this->processService($childNode),
                    'prototype' => $this->processPrototype($childNode),
                    'instanceof' => $this->processInstanceof($childNode),
                    'stack' => $this->processStack($childNode),
                };
            }
        }
    }

    /**
     * Process service defaults
     */
    private function processDefaults(\DOMElement $defaults): void
    {
        $this->addComment('Defaults');
        $this->output .= $this->nl() . '$services->defaults()';

        $this->indentLevel++;
        // Process attributes
        if ($defaults->hasAttribute('public')) {
            $this->output .= $this->formatBooleanAttribute($defaults, 'public', $this->nl() . '->public()');
        }

        if ($defaults->hasAttribute('autowire')) {
            $this->output .= $this->formatBooleanAttribute($defaults, 'autowire', $this->nl() . '->autowire()');
        }

        if ($defaults->hasAttribute('autoconfigure')) {
            $this->output .= $this->formatBooleanAttribute($defaults, 'autoconfigure', $this->nl() . '->autoconfigure()');
        }

        // Process tags
        foreach ($defaults->getElementsByTagName('tag') as $tag) {
            $this->output .= $this->nl() . $this->processTag($tag);
        }

        // Process binds
        foreach ($defaults->getElementsByTagName('bind') as $bind) {
            $this->output .= $this->nl() . $this->processBind($bind);
        }

        $this->output .= ';';
        $this->indentLevel--;
    }

    /**
     * Process a service definition
     */
    private function processService(\DOMElement $service): void
    {
        $id = $service->getAttribute('id');
        $class = $service->getAttribute('class');

        // Check if this is an alias
        if ($service->hasAttribute('alias')) {
            $alias = $service->getAttribute('alias');
            $this->output .= sprintf('%s$services->alias(%s, %s)',
                $this->nl(),
                $this->formatString($id),
                $this->formatString($alias)
            );

            $this->indentLevel++;
            if ($service->hasAttribute('public')) {
                $this->output .= $this->formatBooleanAttribute($service, 'public', $this->nl() . '->public()');
            }
            $this->output .= ';';
            $this->indentLevel--;

            return;
        }

        // Regular service definition
        $this->output .= sprintf('%s$services->set(%s, %s)',
            $this->nl(),
            $this->formatString($id),
            $class ? $this->formatString($class) : 'null'
        );

        $this->indentLevel++;

        // Service attributes
        if ($service->hasAttribute('shared')) {
            $this->output .= $this->formatBooleanAttribute($service, 'shared', $this->nl() . '->shared()', $this->nl() . '->shared(false)');
        }

        if ($service->hasAttribute('public')) {
            $this->output .= $this->formatBooleanAttribute($service, 'public', $this->nl() . '->public()');
        }

        if ($service->hasAttribute('synthetic')) {
            $this->output .= $this->formatBooleanAttribute($service, 'synthetic', $this->nl() . '->synthetic()');
        }

        if ($service->hasAttribute('abstract')) {
            $this->output .= $this->formatBooleanAttribute($service, 'abstract', $this->nl() . '->abstract()');
        }

        if ($service->hasAttribute('lazy')) {
            $lazy = $service->getAttribute('lazy');
            if ($lazy === 'true' || $lazy === '1') {
                $this->output .= $this->nl().'->lazy()';
            } else {
                $this->output .= $this->nl().'->lazy(' . $this->formatString($lazy) . ')';
            }
        }

        if ($service->hasAttribute('parent')) {
            $this->output .= $this->nl().'->parent(' . $this->formatString($service->getAttribute('parent')) . ')';
        }

        if ($service->hasAttribute('decorates')) {
            $decorates = $service->getAttribute('decorates');
            $decorationInnerName = $service->hasAttribute('decoration-inner-name') ?
                $service->getAttribute('decoration-inner-name') : null;
            $decorationPriority = $service->hasAttribute('decoration-priority') ?
                (int)$service->getAttribute('decoration-priority') : 0;
            $decorationOnInvalid = $service->hasAttribute('decoration-on-invalid') ?
                $service->getAttribute('decoration-on-invalid') : null;

            $this->output .= $this->nl().'->decorate('.$this->formatString($decorates);

            if ($decorationInnerName !== null || $decorationPriority !== 0 || $decorationOnInvalid !== null) {
                $this->output .= ', ' . ($decorationInnerName ? $this->formatString($decorationInnerName) : 'null');
            }

            if ($decorationPriority !== 0 || $decorationOnInvalid !== null) {
                $this->output .= ', ' . $decorationPriority;
            }

            if ($decorationOnInvalid !== null) {
                $this->output .= ', ' . $this->formatString($decorationOnInvalid);
            }

            $this->output .= ')';
        }

        if ($service->hasAttribute('autowire')) {
            $this->output .= $this->formatBooleanAttribute($service, 'autowire', '->autowire()');
        }

        if ($service->hasAttribute('autoconfigure')) {
            $this->output .= $this->formatBooleanAttribute($service, 'autoconfigure', '->autoconfigure()');
        }

        // Handle arguments separately for better formatting
        if ($service->getElementsByTagName('argument')->length > 0) {
            $this->output .= $this->processArguments($service);
        }

        // Process child elements
        foreach ($service->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            if ($childNode->nodeName === 'argument') {
                // Arguments are handled separately for better formatting
                continue;
            }

            $this->output .= $this->nl() . match ($childNode->nodeName) {
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

        $this->output .= ';';
        $this->indentLevel--;
    }

    /**
     * Process a service prototype
     */
    private function processPrototype(\DOMElement $prototype): void
    {
        $namespace = $prototype->getAttribute('namespace');
        $resource = $prototype->getAttribute('resource');
        $exclude = $prototype->getAttribute('exclude');

        $this->output .= $this->nl().'$services->prototype('.$this->formatString($namespace).', '.$this->formatString($resource).')';

        $this->indentLevel++;
        if ($exclude) {
            $this->output .= '->exclude(' . $this->formatString($exclude) . ')';
        }

        // Process attributes (same as for regular services)
        if ($prototype->hasAttribute('shared')) {
            $this->output .= $this->formatBooleanAttribute($prototype, 'shared', $this->nl() . '->shared()', $this->nl() . '->shared(false)');
        }

        if ($prototype->hasAttribute('public')) {
            $this->output .= $this->formatBooleanAttribute($prototype, 'public', $this->nl() . '->public()');
        }

        if ($prototype->hasAttribute('abstract')) {
            $this->output .= $this->formatBooleanAttribute($prototype, 'abstract', $this->nl() . '->abstract()');
        }

        if ($prototype->hasAttribute('parent')) {
            $this->output .= $this->nl().'->parent(' . $this->formatString($prototype->getAttribute('parent')) . ')';
        }

        if ($prototype->hasAttribute('autowire')) {
            $this->output .= $this->formatBooleanAttribute($prototype, 'autowire', $this->nl() . '->autowire()');
        }

        if ($prototype->hasAttribute('autoconfigure')) {
            $this->output .= $this->formatBooleanAttribute($prototype, 'autoconfigure', $this->nl() . '->autoconfigure()');
        }

        // Handle arguments separately
        if ($prototype->getElementsByTagName('argument')->length > 0) {
            $this->output .= $this->processArguments($prototype);
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

            $this->output .= $this->nl() . match ($childNode->nodeName) {
                'factory' => $this->processFactory($childNode),
                'configurator' => $this->processCallable($childNode, 'configurator'),
                'call' => $this->processCall($childNode),
                'tag' => $this->processTag($childNode),
                'resource-tag' => $this->processTag($childNode, true),
                'property' => $this->processProperty($childNode),
                'bind' => $this->processBind($childNode),
                'deprecated' => $this->processDeprecated($childNode),
                'exclude' => '->exclude(' . $this->formatString(trim($childNode->nodeValue)) . ')',
            };
        }

        $this->output .= ';';
        $this->indentLevel--;
    }

    /**
     * Process instanceof definition
     */
    private function processInstanceof(\DOMElement $instanceof): void
    {
        $id = $instanceof->getAttribute('id');

        $this->output .= $this->nl().'$services->instanceof('.$this->formatString($id).')';

        $this->indentLevel++;
        // Process attributes (subset of service attributes)
        if ($instanceof->hasAttribute('shared')) {
            $this->output .= $this->formatBooleanAttribute($instanceof, 'shared', $this->nl() . '->shared()', $this->nl() . '->shared(false)');
        }

        if ($instanceof->hasAttribute('public')) {
            $this->output .= $this->formatBooleanAttribute($instanceof, 'public', $this->nl() . '->public()');
        }

        if ($instanceof->hasAttribute('autowire')) {
            $this->output .= $this->formatBooleanAttribute($instanceof, 'autowire', $this->nl() . '->autowire()');
        }

        if ($instanceof->hasAttribute('autoconfigure')) {
            $this->output .= $this->formatBooleanAttribute($instanceof, 'autoconfigure', $this->nl() . '->autoconfigure()');
        }

        // Process child elements
        foreach ($instanceof->childNodes as $childNode) {
            if (!($childNode instanceof \DOMElement)) {
                continue;
            }

            $this->output .= $this->nl().match($childNode->nodeName) {
                'configurator' => $this->processCallable($childNode, 'configurator'),
                'call' => $this->processCall($childNode),
                'tag' => $this->processTag($childNode),
                'property' => $this->processProperty($childNode),
                'bind' => $this->processBind($childNode),
            };
        }

        $this->output .= ';';
        $this->indentLevel--;
    }

    /**
     * Process stack definition
     */
    private function processStack(\DOMElement $stack): void
    {
        $id = $stack->getAttribute('id');

        $this->output .= $this->nl().'$services->stack('.$this->formatString($id).')';
        $this->indentLevel++;

        if ($stack->hasAttribute('public')) {
            $this->output .= $this->formatBooleanAttribute($stack, 'public', '->public()');
        }

        // Process stack services
        $services = [];
        foreach ($stack->getElementsByTagName('service') as $service) {
            $serviceId = $service->getAttribute('id');
            $serviceClass = $service->getAttribute('class');

            if ($serviceId) {
                $services[] = sprintf('service(%s)', $this->formatString($serviceId));
            } elseif ($serviceClass) {
                $services[] = sprintf('inline_service(%s)', $this->formatString($serviceClass));
            }
        }

        if (!empty($services)) {
            $this->output .= $this->nl().'->args([' . implode(', ', $services) . '])';
        }

        // Process deprecated if present
        $deprecated = $stack->getElementsByTagName('deprecated');
        if ($deprecated->length > 0) {
            $this->output .= $this->nl().$this->processDeprecated($deprecated->item(0));
        }

        $this->output .= ';';
        $this->indentLevel--;
    }

    /**
     * Process arguments of a service or prototype
     */
    private function processArguments(\DOMElement $element): void
    {
        $arguments = $element->getElementsByTagName('argument');

        // If there's only one argument, use ->args([...])
        if ($arguments->length === 1) {
            $arg = $arguments->item(0);
            $this->output .= $this->nl().'->args([' . $this->formatArgument($arg) . '])';

            return;
        }

        // Check if we can use indexed arguments
        $useIndexed = true;
        $argIndex = 0;

        foreach ($arguments as $arg) {
            if ($arg->hasAttribute('key')) {
                $key = $arg->getAttribute('key');
                if ($key !== (string)$argIndex) {
                    $useIndexed = false;
                    break;
                }
            }
            $argIndex++;
        }

        if ($useIndexed) {
            $this->output .= $this->nl().'->args([';
            $this->indentLevel++;
            foreach ($arguments as $arg) {
                $this->output .= $this->nl().$this->formatArgument($arg).',';
            }
            $this->indentLevel--;
            $this->output .= $this->nl().'])';
        } else {
            $argIndex = 0;
            foreach ($arguments as $arg) {
                if ($arg->hasAttribute('key')) {
                    $key = $arg->getAttribute('key');
                    $this->output .= $this->nl() . '->arg(' . $this->formatString($key) . ', ' . $this->formatArgument($arg) . ')';
                } else {
                    $this->output .= $this->nl() . '->arg(' . $argIndex . ', ' . $this->formatArgument($arg) . ')';
                }
                $argIndex++;
            }
        }
    }

    /**
     * Format a single argument
     */
    private function formatArgument(\DOMElement $argument): string
    {
        $type = $argument->getAttribute('type') ?: null;
        $id = $argument->getAttribute('id') ?: null;
        $key = $argument->getAttribute('key') ?: null;
        $value = trim($argument->nodeValue);

        // Handle inline service inside argument
        if ($argument->getElementsByTagName('service')->length > 0) {
            $service = $argument->getElementsByTagName('service')->item(0);
            $class = $service->getAttribute('class') ?: 'null';
            return "inline_service({$this->formatString($class)})";
        }

        // Handle nested arguments (collection)
        if ($type === 'collection' && $argument->getElementsByTagName('argument')->length > 0) {
            $items = [];

            foreach ($argument->getElementsByTagName('argument') as $item) {
                if ($item->parentNode->isSameNode($argument)) {
                    $itemKey = $item->getAttribute('key');
                    $itemValue = $this->formatArgument($item);

                    if ($itemKey) {
                        $items[] = $this->formatString($itemKey) . ' => ' . $itemValue;
                    } else {
                        $items[] = $itemValue;
                    }
                }
            }

            return '[' . implode(', ', $items) . ']';
        }

        // Handle specific argument types
        if ($type === 'service' && $id) {
            return "service({$this->formatString($id)})";
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
            return $this->formatString(base64_decode($value));
        }

        if ($type === 'tagged' || $type === 'tagged_iterator') {
            $tag = $argument->getAttribute('tag');
            return "tagged_iterator({$this->formatString($tag)})";
        }

        if ($type === 'tagged_locator') {
            $tag = $argument->getAttribute('tag');
            return "tagged_locator({$this->formatString($tag)})";
        }

        // Default handling (treat as string or convert to appropriate PHP value)
        return $this->formatValue($value);
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

        return '';
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
        $method = $isResource ? '->resource_tag(' : '->tag(';

        $result = $method . $this->formatString($name);

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
            $result .= ', [';
            $attrStrings = [];

            foreach ($attributes as $key => $value) {
                $attrStrings[] = $this->formatString($key) . ' => ' . $this->formatValue($value);
            }

            $result .= implode(', ', $attrStrings);
            $result .= ']';
        }

        $result .= ')';
        return $result;
    }

    /**
     * Process a property element
     */
    private function processProperty(\DOMElement $property): string
    {
        $name = $property->getAttribute('name');
        $value = trim($property->nodeValue);
        $type = $property->getAttribute('type') ?: null;

        // Handle inline service
        if ($property->getElementsByTagName('service')->length > 0) {
            $service = $property->getElementsByTagName('service')->item(0);
            $class = $service->getAttribute('class') ?: 'null';
            return '->property('.$this->formatString($name).', inline_service('.$this->formatString($class).'))';
        }

        // Format value based on type
        if ($type === 'service' && $property->getAttribute('id')) {
            $id = $property->getAttribute('id');
            return "->property({$this->formatString($name)}, service({$this->formatString($id)}))";
        }

        if ($type === 'expression') {
            return "->property({$this->formatString($name)}, expr({$this->formatString($value)}))";
        }

        if ($type === 'string') {
            return "->property({$this->formatString($name)}, {$this->formatString($value)})";
        }

        if ($type === 'constant') {
            return "->property({$this->formatString($name)}, constant({$this->formatString($value)}))";
        }

        // Default handling
        return "->property({$this->formatString($name)}, {$this->formatValue($value)})";
    }

    /**
     * Process a bind element
     */
    private function processBind(\DOMElement $bind): string
    {
        $key = $bind->getAttribute('key');
        $value = trim($bind->nodeValue);
        $type = $bind->getAttribute('type') ?: null;

        // Handle inline service
        if ($bind->getElementsByTagName('service')->length > 0) {
            $service = $bind->getElementsByTagName('service')->item(0);
            $class = $service->getAttribute('class') ?: 'null';
            return "->bind({$this->formatString($key)}, inline_service({$this->formatString($class)}))";
        }

        // Format value based on type
        if ($type === 'service' && $bind->getAttribute('id')) {
            $id = $bind->getAttribute('id');
            return "->bind({$this->formatString($key)}, service({$this->formatString($id)}))";
        }

        if ($type === 'expression') {
            return "->bind({$this->formatString($key)}, expr({$this->formatString($value)}))";
        }

        if ($type === 'string') {
            return "->bind({$this->formatString($key)}, {$this->formatString($value)})";
        }

        if ($type === 'constant') {
            return "->bind({$this->formatString($key)}, constant({$this->formatString($value)}))";
        }

        // Default handling
        return "->bind({$this->formatString($key)}, {$this->formatValue($value)})";
    }

    /**
     * Process a deprecated element
     */
    private function processDeprecated(\DOMElement $deprecated): string
    {
        $message = trim($deprecated->nodeValue);
        $package = $deprecated->getAttribute('package');
        $version = $deprecated->getAttribute('version');

        return '->deprecated(' .
            $this->formatString($package) . ', ' .
            $this->formatString($version) . ', ' .
            $this->formatString($message) .
        ')';
    }

    /**
     * Process a when element (environment-specific configuration)
     */
    private function processWhen(\DOMElement $when): void
    {
        $env = $when->getAttribute('env');

        $this->output .= $this->nl(0);
        $this->addComment("Configuration for environment: {$env}");
        $this->output .= $this->nl() . 'if ($container->env() === ' . $this->formatString($env) . ') {';

        $this->indentLevel++;
        $this->processChildNodes($when);
        $this->indentLevel--;

        $this->output .= $this->nl() . '}';
    }

    /**
     * Add a comment to the output
     */
    private function addComment(string $comment): void
    {
        $lines = explode("\n", $comment);

        if (count($lines) === 1) {
            $this->output .= $this->nl() . '// ' . trim($comment);
        } else {
            $this->output .= $this->nl() . '/*';
            foreach ($lines as $line) {
                $this->output .= $this->nl() . ' * ' . trim($line);
            }
            $this->output .= $this->nl() . ' */';
        }
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
            // Check if it's an integer
            if ((string)(int)$value === $value) {
                return $value;
            }

            // Check if it's a float
            if ((string)(float)$value === $value) {
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
        return PHP_EOL.str_repeat('    ', $indentLevel ?? $this->indentLevel);
    }
}
