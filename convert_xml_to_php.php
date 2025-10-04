<?php

class XmlToPhpConverter
{
    public function inline_var_export($value)
    {
        $php = var_export($value, true);
        $php = preg_replace('/\s+/', ' ', $php);

        return str_replace('\\\\', '\\', $php);
    }

    public function convertValueToPhp(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as &$v) {
                $v = $this->convertValueToPhp($v);
            }

            return $value;
        }

        if (null !== filter_var($value, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE)) {
            return (int) $value;
        }

        if (null !== filter_var($value, \FILTER_VALIDATE_FLOAT, \FILTER_NULL_ON_FAILURE)) {
            return (float) $value;
        }

        if (null === $value || 'null' === strtolower($value)) {
            return null;
        }

        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return 'true' === strtolower($value);
        }

        return $value;
    }

    public function xmlToPhpDslValue($argument)
    {
        $argType = isset($argument['type']) ? (string) $argument['type'] : null;
        $argValue = (string) $argument;
        $argId = isset($argument['id']) ? (string) $argument['id'] : null;
        $onInvalid = isset($argument['on-invalid']) ? (string) $argument['on-invalid'] : null;

        // Inline service definition
        if (isset($argument->service)) {
            $inlineService = $argument->service;
            $class = isset($inlineService['class']) ? (string) $inlineService['class'] : null;
            $inlineCall = "inline_service('$class')";
            $inlineArgIndex = 0;
            foreach ($inlineService->argument as $inlineArg) {
                $inlineArgKey = isset($inlineArg['key']) ? (string) $inlineArg['key'] : null;
                $inlineArgType = isset($inlineArg['type']) ? (string) $inlineArg['type'] : null;
                // Collection type
                if ('collection' === $inlineArgType) {
                    $collectionItems = [];
                    foreach ($inlineArg->argument as $collectionArg) {
                        $collectionItems[] = $this->xmlToPhpDslValue($collectionArg);
                    }
                    $inlineArgValue = '['.implode(', ', $collectionItems).']';
                } else {
                    $inlineArgValue = $this->xmlToPhpDslValue($inlineArg);
                }
                if ($inlineArgKey) {
                    $inlineCall .= "->arg('".$inlineArgKey."', $inlineArgValue)";
                } else {
                    $inlineCall .= "->arg($inlineArgIndex, $inlineArgValue)";
                }
                ++$inlineArgIndex;
            }
            // Handle inline service tags
            foreach ($inlineService->tag as $tag) {
                $tagName = (string) $tag['name'];
                $tagAttrs = [];
                foreach ($tag->attributes() as $attrName => $attrValue) {
                    if ('name' !== $attrName) {
                        $tagAttrs[$attrName] = (string) $attrValue;
                    }
                }
                if (!empty($tagAttrs)) {
                    $inlineCall .= "->tag('$tagName', ".$this->inline_var_export($this->convertValueToPhp($tagAttrs)).')';
                } else {
                    $inlineCall .= "->tag('$tagName')";
                }
            }
            // Handle inline service calls
            foreach ($inlineService->call as $call) {
                $method = (string) $call['method'];
                $callArgs = [];
                foreach ($call->argument as $callArg) {
                    $callArgs[] = $this->xmlToPhpDslValue($callArg);
                }
                $inlineCall .= "->call('$method', [".implode(', ', $callArgs).'])';
            }

            return $inlineCall;
        }

        if ('service' === $argType && $argId) {
            $call = "service('$argId')";
            if ('ignore' === $onInvalid) {
                $call .= '->ignoreOnInvalid()';
            } elseif ('null' === $onInvalid) {
                $call .= '->nullOnInvalid()';
            }

            return $call;
        }

        if ('collection' === $argType) {
            $collectionItems = [];
            foreach ($argument->argument as $collectionArg) {
                $collectionItems[] = ($collectionArg['key'] ? "'".$collectionArg['key']."' => " : '').$this->xmlToPhpDslValue($collectionArg);
            }

            return '['.implode(', ', $collectionItems).']';
        }

        if ('tagged_iterator' === $argType && isset($argument['tag'])) {
            return "tagged_iterator('".$argument['tag']."')";
        }

        if ('tagged_locator' === $argType && isset($argument['tag'])) {
            $indexBy = isset($argument['index-by']) ? (string) $argument['index-by'] : null;
            $call = "tagged_locator('".$argument['tag']."'";
            if ($indexBy) {
                $call .= ", '$indexBy'";
            }
            $call .= ')';

            return $call;
        }

        if ($argType === 'string') {
            return $this->inline_var_export($this->convertValueToPhp($argValue));
        }

        if ($argType !== null) {
            throw new \RuntimeException(\sprintf('Unsupported conversion for the argument type "%s".', $argType));
        }

        return $this->inline_var_export($this->convertValueToPhp($argValue));
    }

    public function transformXmlToPhpFile(string $xmlPath): void
    {
        if (!file_exists($xmlPath)) {
            echo "File not found: $xmlPath\n";

            return;
        }

        echo "Processing: $xmlPath\n";

        if (!str_ends_with($xmlPath, '.xml')) {
            echo "Error: The file must have a .xml extension.\n";

            return;
        }

        $xml = simplexml_load_file($xmlPath);
        if (!$xml) {
            echo "Failed to parse XML file.\n";

            return;
        }

        // Check for required xmlns attribute in root
        $namespaces = $xml->getNamespaces(true);
        if (!isset($xml['xmlns']) && (!isset($namespaces['']) || 'http://symfony.com/schema/dic/services' !== $namespaces[''])) {
            echo "Error: The root element must contain xmlns=\"http://symfony.com/schema/dic/services\"\n";

            return;
        }

        $phpPath = preg_replace('/\.xml$/', '.php', $xmlPath);

        $php = <<<PHP
            <?php
            
            namespace Symfony\Component\DependencyInjection\Loader\Configurator;
            
            return function (ContainerConfigurator \$container) {
                \$services = \$container->services();
        
            PHP;

        foreach ($xml->services->service as $service) {
            $id = (string) $service['id'];
            if (isset($service['alias'])) {
                $aliasId = (string) $service['id'];
                $aliasTo = (string) $service['alias'];
                $setCall = "    \$services->alias('$aliasId', '$aliasTo')";
            } else {
                $class = isset($service['class']) ? (string) $service['class'] : null;
                $setCall = "    \$services->set('$id'";
                if ($class) {
                    $setCall .= ", '$class'";
                }
                $setCall .= ')';
            }

            // Handle attributes
            if (isset($service['public']) && ('true' == $service['public'] || '1' == $service['public'])) {
                $setCall .= "\n        ->public()";
            }
            if (isset($service['abstract']) && ('true' == $service['abstract'] || '1' == $service['abstract'])) {
                $setCall .= "\n        ->abstract()";
            }
            if (isset($service['parent'])) {
                $setCall .= "\n        ->parent('".$service['parent']."')";
            }
            if (isset($service['decorates'])) {
                $decorateArgs = "'".$service['decorates']."'";
                $decorateArgs .= isset($service['decoration-inner-name']) ? ", '".$service['decoration-inner-name']."'" : ', null';
                $decorateArgs .= isset($service['decoration-priority']) ? ', '.(int) $service['decoration-priority'] : ', 0';
                $setCall .= "\n        ->decorate($decorateArgs)";
            }
            if (isset($service['factory'])) {
                $factory = $service['factory'];
                $factoryParts = explode(':', $factory);
                if (2 == count($factoryParts)) {
                    $setCall .= "->factory([service('".$factoryParts[0]."'), '".$factoryParts[1]."'])";
                } else {
                    $setCall .= "->factory('".$factory."')";
                }
            }
            // Handle <factory> child
            if (isset($service->factory)) {
                $factoryService = (string) $service->factory['service'];
                $factoryMethod = (string) $service->factory['method'];
                if ($factoryService && $factoryMethod) {
                    $setCall .= "->factory([service('$factoryService'), '$factoryMethod'])";
                }
            }
            // Handle <argument> child elements
            if ($service->argument) {
                $argumentsAsArray = false;
                $argIndex = 0;
                foreach ($service->argument as $argument) {
                    if (isset($argument['key']) && (string) $argument['key'] !== (string) $argIndex) {
                        $argumentsAsArray = true;
                        break;
                    }
                    ++$argIndex;
                }
                if (!$argumentsAsArray) {
                    if (1 === count($service->argument)) {
                        $setCall .= "\n        ->args([".$this->xmlToPhpDslValue($argument).'])';
                    } else {
                        $setCall .= "\n        ->args([\n";
                        foreach ($service->argument as $argument) {
                            $setCall .= '            '.$this->xmlToPhpDslValue($argument).",\n";
                        }
                        $setCall .= '        ])';
                    }
                } else {
                    $argIndex = 0;
                    foreach ($service->argument as $argument) {
                        $argKey = isset($argument['key']) ? (string) $argument['key'] : null;
                        $argCall = $this->xmlToPhpDslValue($argument);
                        if ($argKey) {
                            $setCall .= "\n        ->arg('".$argKey."', $argCall)";
                        } else {
                            $setCall .= "\n        ->arg($argIndex, $argCall)";
                        }
                        ++$argIndex;
                    }
                }
            }
            // Handle <tag> child elements
            foreach ($service->tag as $tag) {
                $tagName = (string) $tag['name'];
                $tagAttrs = [];
                foreach ($tag->attributes() as $attrName => $attrValue) {
                    if ('name' !== $attrName) {
                        $tagAttrs[$attrName] = (string) $attrValue;
                    }
                }
                if (!empty($tagAttrs)) {
                    $setCall .= "\n        ->tag('$tagName', ".$this->inline_var_export($this->convertValueToPhp($tagAttrs)).')';
                } else {
                    $setCall .= "\n        ->tag('$tagName')";
                }
            }
            // Handle <call> child elements
            foreach ($service->call as $call) {
                $method = (string) $call['method'];
                $callArgs = [];
                foreach ($call->argument as $callArg) {
                    $callArgs[] = $this->xmlToPhpDslValue($callArg);
                }
                $setCall .= "\n        ->call('$method', [".implode(', ', $callArgs).'])';
            }
            $setCall .= ";\n";
            $php .= "\n".$setCall;
        }
        $php .= <<<PHP
            };
            
            PHP;

        file_put_contents($phpPath, $php);

        echo "Generated: $phpPath\n";
    }

    public function run(string $inputDir): void
    {
        $xmlPath = rtrim($inputDir, '/').'/';
        $files = array_merge(
            glob($xmlPath.'*.xml'),
            glob($xmlPath.'**/*.xml')
        );
        foreach ($files as $file) {
            if (str_contains('/routing/', $file) || str_contains('/doctrine/', $file)) {
                continue;
            }
            $this->transformXmlToPhpFile($file);
        }
    }
}

// Script entry point
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        echo "Usage: php convert_xml_to_php.php src/Symfony/Bundle/Resources/config/\n";
        exit(1);
    }
    $converter = new XmlToPhpConverter();
    $converter->run($argv[1]);
}
