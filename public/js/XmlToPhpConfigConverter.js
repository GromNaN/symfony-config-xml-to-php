/**
 * This file is part of the gromnan/symfony-config-xml-to-php package.
 *
 * (c) Jérôme Tamarelle <jerome@tamarelle.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * ⚠️  IMPORTANT: This JavaScript version must be kept in sync with the PHP version
 *     at src/XmlToPhpConfigConverter.php. Any changes to the PHP converter must
 *     be reflected here to ensure identical output. See .github/copilot-instructions.md
 */

class XmlToPhpConfigConverter {
    constructor() {
        this.indentLevel = 0;
    }

    /**
     * Convert an XML configuration to PHP configuration
     */
    convert(xml) {
        // Parse XML from string
        const parser = new DOMParser();
        const dom = parser.parseFromString(xml, 'text/xml');

        // Check for parsing errors
        const parserError = dom.getElementsByTagName('parsererror')[0];
        if (parserError) {
            throw new Error('Invalid XML: ' + parserError.textContent);
        }

        this.validateNamespace(dom);

        this.indentLevel = 0;
        let output = '<?php';

        // Start the PHP output with the necessary namespace and function
        output += this.nl(0);
        output += this.nl() + 'namespace Symfony\\Component\\DependencyInjection\\Loader\\Configurator;';
        output += this.nl(0);
        output += this.nl() + 'return static function (ContainerConfigurator $container) {';

        // Process the root container element and its children
        this.indentLevel++;
        output += this.nl() + '$services = $container->services();';
        output += this.nl() + '$parameters = $container->parameters();';
        output += this.processChildNodes(dom.documentElement);
        this.indentLevel--;

        // Close the function
        output += this.nl() + '};';
        output += this.nl(0);

        return output;
    }

    validateNamespace(document) {
        const rootElement = document.documentElement;

        // Check if root element is 'container'
        if (rootElement.nodeName !== 'container') {
            throw new Error('Root element must be "container".');
        }

        // Check for the main Symfony services namespace
        const mainNamespace = rootElement.getAttribute('xmlns') ||
                             rootElement.lookupNamespaceURI(null) ||
                             rootElement.namespaceURI;

        if (mainNamespace === 'http://symfony.com/schema/dic/services') {
            return; // Valid namespace found
        }

        // Check for namespace declarations in attributes
        const attributes = rootElement.attributes;
        for (let i = 0; i < attributes.length; i++) {
            const attr = attributes[i];
            // Check for xmlns declarations or schemaLocation
            if ((attr.name === 'xmlns' || attr.name.startsWith('xmlns:')) &&
                attr.value === 'http://symfony.com/schema/dic/services') {
                return; // Valid namespace found
            }
            if (attr.name === 'schemaLocation' &&
                attr.value.includes('http://symfony.com/schema/dic/services')) {
                return; // Valid namespace found
            }
        }

        // If no valid namespace found, show a more helpful error
        throw new Error('Invalid or missing XML namespace. The container element must use the Symfony services namespace "http://symfony.com/schema/dic/services".');
    }

    /**
     * Process the child nodes of an element
     */
    processChildNodes(node) {
        const childNodes = node.childNodes;
        let output = '';

        for (let i = 0; i < childNodes.length; i++) {
            const childNode = childNodes[i];

            // Process comments
            if (childNode.nodeType === Node.COMMENT_NODE) {
                output += this.addComment(childNode.nodeValue);
                continue;
            }

            // Skip text nodes (whitespace)
            if (childNode.nodeType === Node.TEXT_NODE) {
                continue;
            }

            // Process element nodes
            if (childNode.nodeType === Node.ELEMENT_NODE) {
                output += this.processElement(childNode);
            }
        }

        return output;
    }

    /**
     * Process an XML element and convert it to PHP
     */
    processElement(element) {
        // Be more flexible with namespace checking
        if (element.namespaceURI &&
            element.namespaceURI !== 'http://symfony.com/schema/dic/services' &&
            element.namespaceURI !== null) {
            throw new Error('Converting XML config files containing configuration for extensions is not supported.');
        }

        switch (element.nodeName) {
            case 'imports':
                return this.processImports(element);
            case 'parameters':
                return this.processParameters(element);
            case 'services':
                return this.processServices(element);
            case 'when':
                return this.processWhen(element);
            default:
                return '';
        }
    }

    /**
     * Process imports section
     */
    processImports(imports) {
        let output = '';
        const importElements = imports.getElementsByTagName('import');

        for (let i = 0; i < importElements.length; i++) {
            const importEl = importElements[i];
            const resource = importEl.getAttribute('resource');
            const ignoreErrors = importEl.getAttribute('ignore-errors');
            const type = importEl.getAttribute('type');

            output += this.nl() + '$container->import(';
            output += this.formatString(resource.replace(/\.xml$/, '.php'));

            if (type || ignoreErrors) {
                output += ', ';
                output += (type ? type.replace('xml', 'php') : 'null');
            }

            if (importEl.hasAttribute('ignore-errors')) {
                output += ', ';
                switch (ignoreErrors) {
                    case 'not_found':
                        output += this.formatString('not_found');
                        break;
                    case 'true':
                    case '1':
                        output += 'true';
                        break;
                    default:
                        output += 'false';
                        break;
                }
            }

            output += ');';
        }

        return output + this.nl(0);
    }

    /**
     * Process parameters section
     */
    processParameters(parameters) {
        const parametersKey = parameters.getAttribute('key');
        let output = '';

        if (parametersKey) {
            // For collection parameters with key
            output += this.nl() + '$parameters->set(' + this.formatString(parametersKey) + ', []);';
        }

        const paramElements = parameters.getElementsByTagName('parameter');
        for (let i = 0; i < paramElements.length; i++) {
            const param = paramElements[i];
            output += this.nl() + '$parameters->set(';
            output += this.formatString(param.getAttribute('key')) + ', ';
            output += this.formatParameter(param);
            output += ');';
        }

        return output;
    }

    /**
     * Process a single parameter element
     */
    formatParameter(parameter) {
        if (parameter.tagName !== 'parameter') {
            throw new Error('Expected a <parameter> element.');
        }

        const type = parameter.getAttribute('type');
        let value = parameter.textContent;

        if (type === 'collection') {
            const items = [];
            const childElements = parameter.children;

            for (let i = 0; i < childElements.length; i++) {
                const item = childElements[i];
                const itemKey = item.getAttribute('key');
                if (itemKey) {
                    items.push(this.formatString(itemKey) + ' => ' + this.formatParameter(item));
                } else {
                    items.push(this.formatParameter(item));
                }
            }

            return '[' + items.join(', ') + ']';
        }

        if (['true', '1'].includes(parameter.getAttribute('trim'))) {
            value = value.trim();
        }

        switch (type) {
            case 'string':
                return this.formatString(value);
            case 'constant':
                return '\\' + value.replace(/^\\/, '');
            case 'binary':
                return 'base64_decode(' + this.formatString(value) + ')';
            default:
                return this.formatValue(value);
        }
    }

    /**
     * Process services section
     */
    processServices(services) {
        let output = '';

        for (let i = 0; i < services.childNodes.length; i++) {
            const childNode = services.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE) {
                output += this.nl(0);
                switch (childNode.nodeName) {
                    case 'defaults':
                        output += this.processDefaults(childNode);
                        break;
                    case 'service':
                        output += this.processService(childNode);
                        break;
                    case 'prototype':
                        output += this.processPrototype(childNode);
                        break;
                    case 'instanceof':
                        output += this.processInstanceof(childNode);
                        break;
                    case 'stack':
                        output += this.processStack(childNode);
                        break;
                }
            }
        }

        return output;
    }

    /**
     * Process service defaults
     */
    processDefaults(defaults) {
        this.addComment('Defaults');
        let output = this.nl() + '$services->defaults()';

        this.indentLevel++;

        // Process attributes
        output += this.formatBooleanAttribute(defaults, 'public', this.nl() + '->public()', this.nl() + '->private()');
        output += this.formatBooleanAttribute(defaults, 'autowire', this.nl() + '->autowire()', this.nl() + '->autowire(false)');
        output += this.formatBooleanAttribute(defaults, 'autoconfigure', this.nl() + '->autoconfigure()', this.nl() + '->autoconfigure(false)');

        // Process tags and other child elements
        for (let i = 0; i < defaults.childNodes.length; i++) {
            const childNode = defaults.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE) {
                switch (childNode.nodeName) {
                    case 'tag':
                        output += this.nl() + this.processTag(childNode);
                        break;
                    case 'resource-tag':
                        output += this.nl() + this.processTag(childNode, true);
                        break;
                    case 'bind':
                        output += this.nl() + this.processBind(childNode);
                        break;
                }
            }
        }

        this.indentLevel--;

        return output + ';';
    }

    /**
     * Process a service definition
     */
    processService(service) {
        const id = service.getAttribute('id');
        const className = service.getAttribute('class');
        let output = this.nl() + '$services';

        // Check if this is an alias
        if (service.hasAttribute('alias')) {
            const alias = service.getAttribute('alias');
            output += '->alias(' + this.formatString(id) + ', ' + this.formatString(alias) + ')';
        } else {
            // Regular service definition
            output += '->set(' + this.formatString(id);
            if (className) {
                output += ', ' + this.formatString(className);
            }
            output += ')';
        }

        this.indentLevel++;
        output += this.formatBooleanAttribute(service, 'shared', this.nl() + '->share()', this.nl() + '->share(false)');
        output += this.formatBooleanAttribute(service, 'public', this.nl() + '->public()', this.nl() + '->private()');
        output += this.formatBooleanAttribute(service, 'synthetic', this.nl() + '->synthetic()', this.nl() + '->synthetic(false)');
        output += this.formatBooleanAttribute(service, 'abstract', this.nl() + '->abstract()', this.nl() + '->abstract(false)');
        output += this.processServiceConfiguration(service);
        this.indentLevel--;

        return output + ';';
    }

    processInlineService(service) {
        const className = service.getAttribute('class');
        if (!className) {
            throw new Error('Inline service must have a class attribute.');
        }

        let output = 'inline_service(' + this.formatString(className) + ')';

        // Process service configuration
        this.indentLevel++;
        output += this.processServiceConfiguration(service);
        this.indentLevel--;

        return output;
    }

    processServiceConfiguration(service) {
        let output = '';

        // Service attributes
        if (service.hasAttribute('lazy')) {
            const lazy = service.getAttribute('lazy');
            if (lazy === 'true' || lazy === '1') {
                output += this.nl() + '->lazy()';
            } else {
                output += this.nl() + '->lazy(' + this.formatString(lazy) + ')';
            }
        }

        if (service.hasAttribute('parent')) {
            output += this.nl() + '->parent(' + this.formatString(service.getAttribute('parent')) + ')';
        }

        if (service.hasAttribute('decorates')) {
            const decorates = service.getAttribute('decorates');
            const decorationInnerName = service.hasAttribute('decoration-inner-name') ?
                service.getAttribute('decoration-inner-name') : null;
            const decorationPriority = service.hasAttribute('decoration-priority') ?
                parseInt(service.getAttribute('decoration-priority')) : 0;
            const decorationOnInvalid = service.hasAttribute('decoration-on-invalid') ?
                service.getAttribute('decoration-on-invalid') : null;

            output += this.nl() + '->decorate(' + this.formatString(decorates);

            if (decorationInnerName !== null || decorationPriority !== 0 || decorationOnInvalid !== null) {
                output += ', ' + (decorationInnerName ? this.formatString(decorationInnerName) : 'null');
            }

            if (decorationPriority !== 0 || decorationOnInvalid !== null) {
                output += ', ' + decorationPriority;
            }

            if (decorationOnInvalid !== null) {
                output += ', ' + this.processInvalidBehaviorConstant(decorationOnInvalid);
            }

            output += ')';
        }

        output += this.formatBooleanAttribute(service, 'autowire', this.nl() + '->autowire()', this.nl() + '->autowire(false)');
        output += this.formatBooleanAttribute(service, 'autoconfigure', this.nl() + '->autoconfigure()', this.nl() + '->autoconfigure(false)');

        if (service.hasAttribute('constructor')) {
            output += this.nl() + '->constructor(' + this.formatString(service.getAttribute('constructor')) + ')';
        }

        // Handle arguments separately for better formatting
        output += this.processArguments(service);

        // Process child elements
        for (let i = 0; i < service.childNodes.length; i++) {
            const childNode = service.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName !== 'argument') {
                switch (childNode.nodeName) {
                    case 'file':
                        output += this.nl() + '->file(' + this.formatString(childNode.textContent.trim()) + ')';
                        break;
                    case 'factory':
                        output += this.nl() + this.processCallable(childNode, 'factory');
                        break;
                    case 'from-callable':
                        output += this.nl() + this.processCallable(childNode, 'fromCallable');
                        break;
                    case 'configurator':
                        output += this.nl() + this.processCallable(childNode, 'configurator');
                        break;
                    case 'call':
                        output += this.nl() + this.processCall(childNode);
                        break;
                    case 'tag':
                        output += this.nl() + this.processTag(childNode);
                        break;
                    case 'resource-tag':
                        output += this.nl() + this.processTag(childNode, true);
                        break;
                    case 'property':
                        output += this.nl() + this.processProperty(childNode);
                        break;
                    case 'bind':
                        output += this.nl() + this.processBind(childNode);
                        break;
                    case 'deprecated':
                        output += this.nl() + this.processDeprecated(childNode);
                        break;
                }
            }
        }

        return output;
    }

    processServiceReference(element, type) {
        const id = element.getAttribute('id');
        let output = type + '(' + this.formatString(id) + ')';

        const onInvalid = element.getAttribute('on-invalid') || 'exception';
        switch (onInvalid) {
            case 'ignore':
                output += '->ignoreOnInvalid()';
                break;
            case 'null':
                output += '->nullOnInvalid()';
                break;
            case 'ignore_uninitialized':
                output += '->ignoreOnUninitialized()';
                break;
        }

        return output;
    }

    processInvalidBehaviorConstant(value) {
        const constants = {
            'ignore': 'IGNORE_ON_INVALID_REFERENCE',
            '1': 'IGNORE_ON_INVALID_REFERENCE',
            'null': 'NULL_ON_INVALID_REFERENCE',
            '2': 'NULL_ON_INVALID_REFERENCE',
            'ignore_uninitialized': 'IGNORE_ON_UNINITIALIZED_REFERENCE',
            '3': 'IGNORE_ON_UNINITIALIZED_REFERENCE',
            'exception': 'EXCEPTION_ON_INVALID_REFERENCE',
            '4': 'EXCEPTION_ON_INVALID_REFERENCE'
        };

        return '\\Symfony\\Component\\DependencyInjection\\ContainerInterface::' + (constants[value] || 'EXCEPTION_ON_INVALID_REFERENCE');
    }

    /**
     * Process a service prototype
     */
    processPrototype(prototype) {
        const namespace = prototype.getAttribute('namespace');
        const resource = prototype.getAttribute('resource');
        const exclude = prototype.getAttribute('exclude');

        let output = this.nl() + '$services->load(' + this.formatString(namespace) + ', ' + this.formatString(resource) + ')';

        this.indentLevel++;

        // Merge all exclude attribute and tags into a single exclude call
        this.indentLevel++;
        const excludes = [];
        if (exclude) {
            excludes.push(this.nl() + this.formatString(exclude) + ',');
        }

        for (let i = 0; i < prototype.childNodes.length; i++) {
            const childNode = prototype.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName === 'exclude') {
                excludes.push(this.nl() + this.formatString(childNode.textContent) + ',');
            }
        }

        this.indentLevel--;
        if (excludes.length > 0) {
            output += this.nl() + '->exclude([' + excludes.join('') + this.nl() + '])';
        }

        // Process attributes (same as for regular services)
        if (prototype.hasAttribute('parent')) {
            output += this.nl() + '->parent(' + this.formatString(prototype.getAttribute('parent')) + ')';
        }

        output += this.formatBooleanAttribute(prototype, 'shared', this.nl() + '->share()', this.nl() + '->share(false)');
        output += this.formatBooleanAttribute(prototype, 'public', this.nl() + '->public()', this.nl() + '->private()');
        output += this.formatBooleanAttribute(prototype, 'abstract', this.nl() + '->abstract()', this.nl() + '->abstract(false)');
        output += this.formatBooleanAttribute(prototype, 'autowire', this.nl() + '->autowire()', this.nl() + '->autowire(false)');
        output += this.formatBooleanAttribute(prototype, 'autoconfigure', this.nl() + '->autoconfigure()', this.nl() + '->autoconfigure(false)');

        // Handle arguments separately
        output += this.processArguments(prototype);

        // Process child elements (similar to service)
        for (let i = 0; i < prototype.childNodes.length; i++) {
            const childNode = prototype.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && !['argument', 'exclude'].includes(childNode.nodeName)) {
                switch (childNode.nodeName) {
                    case 'factory':
                        output += this.nl() + this.processCallable(childNode, 'factory');
                        break;
                    case 'configurator':
                        output += this.nl() + this.processCallable(childNode, 'configurator');
                        break;
                    case 'call':
                        output += this.nl() + this.processCall(childNode);
                        break;
                    case 'tag':
                        output += this.nl() + this.processTag(childNode);
                        break;
                    case 'resource-tag':
                        output += this.nl() + this.processTag(childNode, true);
                        break;
                    case 'property':
                        output += this.nl() + this.processProperty(childNode);
                        break;
                    case 'bind':
                        output += this.nl() + this.processBind(childNode);
                        break;
                    case 'deprecated':
                        output += this.nl() + this.processDeprecated(childNode);
                        break;
                }
            }
        }

        this.indentLevel--;

        return output + ';';
    }

    /**
     * Process instanceof definition
     */
    processInstanceof(instanceofElement) {
        const id = instanceofElement.getAttribute('id');

        let output = this.nl() + '$services->instanceof(' + this.formatString(id) + ')';

        this.indentLevel++;

        // Process attributes (subset of service attributes)
        output += this.formatBooleanAttribute(instanceofElement, 'shared', this.nl() + '->share()', this.nl() + '->share(false)');
        output += this.formatBooleanAttribute(instanceofElement, 'public', this.nl() + '->public()', this.nl() + '->private()');
        output += this.formatBooleanAttribute(instanceofElement, 'autowire', this.nl() + '->autowire()', this.nl() + '->autowire(false)');
        output += this.formatBooleanAttribute(instanceofElement, 'autoconfigure', this.nl() + '->autoconfigure()', this.nl() + '->autoconfigure(false)');

        // Process child elements
        for (let i = 0; i < instanceofElement.childNodes.length; i++) {
            const childNode = instanceofElement.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE) {
                switch (childNode.nodeName) {
                    case 'configurator':
                        output += this.nl() + this.processCallable(childNode, 'configurator');
                        break;
                    case 'call':
                        output += this.nl() + this.processCall(childNode);
                        break;
                    case 'tag':
                        output += this.nl() + this.processTag(childNode);
                        break;
                    case 'property':
                        output += this.nl() + this.processProperty(childNode);
                        break;
                    case 'bind':
                        output += this.nl() + this.processBind(childNode);
                        break;
                }
            }
        }

        this.indentLevel--;

        return output + ';';
    }

    /**
     * Process stack definition
     */
    processStack(stack) {
        const id = stack.getAttribute('id');

        let output = this.nl() + '$services->stack(' + this.formatString(id) + ', [';

        // Process stack services
        const services = [];
        this.indentLevel++;

        for (let i = 0; i < stack.childNodes.length; i++) {
            const service = stack.childNodes[i];
            if (service.nodeType === Node.ELEMENT_NODE && service.nodeName === 'service') {
                const serviceId = service.getAttribute('id');
                if (serviceId) {
                    services.push(this.nl() + this.processServiceReference(service, 'service') + ',');
                } else {
                    services.push(this.nl() + this.processInlineService(service) + ',');
                }
            }
        }

        this.indentLevel--;

        if (services.length === 0) {
            output += '])';
        } else {
            output += services.join('') + this.nl() + '])';
        }

        this.indentLevel++;
        output += this.formatBooleanAttribute(stack, 'public', '->public()', '->private()');

        // Process deprecated if present
        for (let i = 0; i < stack.childNodes.length; i++) {
            const deprecated = stack.childNodes[i];
            if (deprecated.nodeType === Node.ELEMENT_NODE && deprecated.nodeName === 'deprecated') {
                output += this.processDeprecated(deprecated);
            }
        }

        this.indentLevel--;

        return output + ';';
    }

    /**
     * Process arguments of a service or prototype
     */
    processArguments(element) {
        const argumentsStr = this.formatArguments(element);

        if (argumentsStr === null) {
            return '';
        }

        return this.nl() + '->args(' + argumentsStr + ')';
    }

    /**
     * Format a list of arguments, return null if empty
     */
    formatArguments(element) {
        const argumentNodes = [];
        for (let i = 0; i < element.childNodes.length; i++) {
            const node = element.childNodes[i];
            if (node.nodeType === Node.ELEMENT_NODE && node.nodeName === 'argument') {
                argumentNodes.push(node);
            }
        }

        if (argumentNodes.length === 0) {
            return null;
        }

        // If there's only one argument, use ->args([...])
        if (argumentNodes.length === 1) {
            const arg = argumentNodes[0];
            let key = arg.getAttribute('key');
            if (arg.hasAttribute('index')) {
                key = 'index_' + arg.getAttribute('index');
            }
            if (key) {
                return '[' + this.formatString(key) + ' => ' + this.formatArgument(arg) + ']';
            }

            return '[' + this.formatArgument(arg) + ']';
        }

        let output = '[';
        this.indentLevel++;

        for (let i = 0; i < argumentNodes.length; i++) {
            const arg = argumentNodes[i];
            let key = arg.getAttribute('key');
            if (arg.hasAttribute('index')) {
                key = arg.getAttribute('index');
            }
            if (key) {
                output += this.nl() + this.formatString(key) + ' => ' + this.formatArgument(arg) + ',';
            } else {
                output += this.nl() + this.formatArgument(arg) + ',';
            }
        }

        this.indentLevel--;

        return output + this.nl() + ']';
    }

    /**
     * Format a single argument
     */
    formatArgument(argument) {
        const type = argument.getAttribute('type') || null;
        let value = argument.textContent;

        // Handle nested arguments (collection)
        if (['collection', null].includes(type)) {
            const items = [];
            for (let i = 0; i < argument.childNodes.length; i++) {
                const item = argument.childNodes[i];
                if (item.nodeType === Node.ELEMENT_NODE && item.nodeName === argument.nodeName) {
                    const itemKey = item.getAttribute('key') || item.getAttribute('name');

                    let itemKeyFormatted;
                    switch (item.getAttribute('key-type')) {
                        case 'constant':
                            itemKeyFormatted = '\\' + itemKey.replace(/^\\/, '');
                            break;
                        case 'binary':
                            itemKeyFormatted = 'base64_decode(' + this.formatString(itemKey) + ')';
                            break;
                        default:
                            itemKeyFormatted = this.formatString(itemKey);
                            break;
                    }

                    if (itemKey) {
                        items.push(itemKeyFormatted + ' => ' + this.formatArgument(item));
                    } else {
                        items.push(this.formatArgument(item));
                    }
                }
            }

            if (items.length > 0) {
                return '[' + items.join(', ') + ']';
            }

            // Force empty array for a "collection" type, even if no child nodes
            if (type === 'collection') {
                return '[]';
            }
        }

        // Inline services are defined with a nested <service> element
        for (let i = 0; i < argument.childNodes.length; i++) {
            const childNode = argument.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName === 'service') {
                return this.processInlineService(childNode);
            }
        }

        // Handle specific argument types
        if (type === 'service' || type === 'service_closure') {
            return this.processServiceReference(argument, type);
        }

        if (type === 'closure') {
            if (argument.hasAttribute('id')) {
                return 'closure(' + this.processServiceReference(argument, 'service') + ')';
            }

            return 'closure(' + this.formatArguments(argument) + ')';
        }

        if (type === 'expression') {
            return "expr(" + this.formatString(value) + ")";
        }

        if (type === 'string') {
            return this.formatString(value);
        }

        if (type === 'constant') {
            return '\\' + value.replace(/^\\/, '');
        }

        if (type === 'binary') {
            return 'base64_decode(' + this.formatString(value) + ')';
        }

        if (type === 'tagged' || type === 'tagged_iterator') {
            return this.processTagged('tagged_iterator', argument);
        }

        if (type === 'tagged_locator') {
            return this.processTagged('tagged_locator', argument);
        }

        if (type === 'service_locator') {
            return this.processServiceLocator(argument);
        }

        if (type === 'iterator') {
            return 'iterator(' + (this.formatArguments(argument) || '[]') + ')';
        }

        if (type === 'abstract') {
            return 'abstract_arg(' + this.formatString(value) + ')';
        }

        if (type === null) {
            // Default handling (treat as string or convert to appropriate PHP value)
            return this.formatValue(value);
        }

        throw new Error('Unsupported argument type: ' + type);
    }

    processTagged(method, argument) {
        let output = method + '(' + this.formatString(argument.getAttribute('tag'));

        if (argument.hasAttribute('index-by')) {
            output += ', indexAttribute: ' + this.formatString(argument.getAttribute('index-by'));
        }

        if (argument.hasAttribute('default-index-method')) {
            output += ', defaultIndexMethod: ' + this.formatString(argument.getAttribute('default-index-method'));
        }

        if (argument.hasAttribute('default-priority-method')) {
            output += ', defaultPriorityMethod: ' + this.formatString(argument.getAttribute('default-priority-method'));
        }

        // Exclude can be an attribute or multiple <exclude> child elements
        const exclude = [];
        if (argument.hasAttribute('exclude')) {
            exclude.push(this.formatString(argument.getAttribute('exclude')));
        }

        for (let i = 0; i < argument.childNodes.length; i++) {
            const childNode = argument.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName === 'exclude') {
                exclude.push(this.formatString(childNode.textContent));
            }
        }

        if (exclude.length > 0) {
            output += ', exclude: ' + (exclude.length === 1 ? exclude[0] : '[' + exclude.join(', ') + ']');
        }

        output += this.formatBooleanAttribute(argument, 'exclude-self', null, ', excludeSelf: false');

        return output + ')';
    }

    processServiceLocator(argument) {
        let output = 'service_locator([';

        this.indentLevel++;
        for (let i = 0; i < argument.childNodes.length; i++) {
            const item = argument.childNodes[i];
            if (item.nodeType === Node.ELEMENT_NODE && item.nodeName === 'argument') {
                const itemKey = item.getAttribute('key');
                if (itemKey) {
                    output += this.nl() + this.formatString(itemKey) + ' => ' + this.formatArgument(item) + ',';
                } else {
                    output += this.nl() + this.formatArgument(item) + ',';
                }
            }
        }
        this.indentLevel--;

        return output + this.nl() + '])';
    }

    /**
     * Process a callable (configurator, from-callable)
     */
    processCallable(callable, methodName) {
        // Expression form (factory or from-callable)
        if (callable.hasAttribute('expression')) {
            const expression = callable.getAttribute('expression');
            return '->' + methodName + '(expr(' + this.formatString(expression) + '))';
        }

        // Class::method form
        if (callable.hasAttribute('class') && callable.hasAttribute('method')) {
            const className = callable.getAttribute('class');
            const method = callable.getAttribute('method');
            return '->' + methodName + '([' + this.formatString(className) + ', ' + this.formatString(method) + '])';
        }

        // Service::method form
        if (callable.hasAttribute('service') && callable.hasAttribute('method')) {
            const service = callable.getAttribute('service');
            const method = callable.getAttribute('method');
            return '->' + methodName + '([service(' + this.formatString(service) + '), ' + this.formatString(method) + '])';
        }

        // self::method form
        if (callable.hasAttribute('method')) {
            const method = callable.getAttribute('method');
            let service = 'null';

            for (let i = 0; i < callable.childNodes.length; i++) {
                const childNode = callable.childNodes[i];
                if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName === 'service') {
                    if (childNode.hasAttribute('id')) {
                        service = this.processServiceReference(childNode, 'service');
                    } else {
                        service = this.processInlineService(childNode);
                    }
                    break;
                }
            }

            return '->' + methodName + '([' + service + ', ' + this.formatString(method) + '])';
        }

        // Function form
        if (callable.hasAttribute('function')) {
            const functionName = callable.getAttribute('function');
            return '->' + methodName + '(' + this.formatString(functionName) + ')';
        }

        return '';
    }

    /**
     * Process a method call
     */
    processCall(call) {
        const method = call.getAttribute('method');

        const argumentsStr = this.formatArguments(call);
        let output = '->call(' + this.formatString(method);

        // Add arguments if present
        if (argumentsStr !== null) {
            output += ', ' + argumentsStr;
        }

        // The 2nd argument is the arguments array, so only add the 3rd argument if needed
        output += this.formatBooleanAttribute(call, 'returns-clone', ', returnsClone: true');
        output += ')';

        return output;
    }

    /**
     * Process a tag element
     */
    processTag(tag, isResource = false) {
        const tagNameComesFromAttribute = tag.children.length > 0 || tag.textContent === '';
        const tagName = tagNameComesFromAttribute ? tag.getAttribute('name') : tag.textContent;

        if (!tagName) {
            throw new Error('The tag name must be a non-empty string.');
        }

        const method = isResource ? '->resourceTag(' : '->tag(';

        let output = method + this.formatString(tagName);

        // Check for attributes
        const attributes = {};
        for (let i = 0; i < tag.attributes.length; i++) {
            const attr = tag.attributes[i];
            if (tagNameComesFromAttribute && attr.name === 'name') {
                continue;
            }
            attributes[attr.name] = this.formatValue(attr.value);
        }

        // Check for nested attributes
        for (let i = 0; i < tag.childNodes.length; i++) {
            const childNode = tag.childNodes[i];
            if (childNode.nodeType === Node.ELEMENT_NODE && childNode.nodeName === 'attribute') {
                const attrName = childNode.getAttribute('name');
                if (childNode.childNodes.length > 0) {
                    attributes[attrName] = this.formatArgument(childNode);
                } else {
                    attributes[attrName] = this.formatValue(childNode.textContent);
                }
            }
        }

        if (Object.keys(attributes).length > 0) {
            const outputs = [];
            for (const [key, value] of Object.entries(attributes)) {
                let normalizedKey = key;
                if (key.includes('-') && !key.includes('_') && !attributes.hasOwnProperty(key.replace(/-/g, '_'))) {
                    normalizedKey = key.replace(/-/g, '_');
                }
                outputs.push(this.formatString(normalizedKey) + ' => ' + value);
            }
            output += ', [' + outputs.join(', ') + ']';
        }

        return output + ')';
    }

    /**
     * Process a property element
     */
    processProperty(property) {
        const name = property.getAttribute('key') || property.getAttribute('name');
        return '->property(' + this.formatString(name) + ', ' + this.formatArgument(property) + ')';
    }

    /**
     * Process a bind element
     */
    processBind(bind) {
        const key = bind.getAttribute('key') || bind.getAttribute('name');
        return '->bind(' + this.formatString(key) + ', ' + this.formatArgument(bind) + ')';
    }

    /**
     * Process a deprecated element
     */
    processDeprecated(deprecated) {
        const message = deprecated.textContent.trim();
        const packageName = deprecated.getAttribute('package');
        const version = deprecated.getAttribute('version');

        return '->deprecate(' +
            this.formatString(packageName) + ', ' +
            this.formatString(version) + ', ' +
            this.formatString(message) +
        ')';
    }

    /**
     * Process a when element (environment-specific configuration)
     */
    processWhen(when) {
        const env = when.getAttribute('env');

        let output = this.nl(0);
        output += this.addComment("Configuration for environment: " + env);
        output += this.nl() + 'if ($container->env() === ' + this.formatString(env) + ') {';

        this.indentLevel++;
        output += this.processChildNodes(when);
        this.indentLevel--;

        return output + this.nl() + '}';
    }

    /**
     * Add a comment to the output
     */
    addComment(comment) {
        const lines = comment.split('\n');

        if (lines.length === 1) {
            return this.nl() + '// ' + comment.trim();
        }

        let output = this.nl() + '/*';
        for (let i = 0; i < lines.length; i++) {
            output += this.nl() + ' * ' + lines[i].trim();
        }
        output += this.nl() + ' */';

        return output;
    }

    /**
     * Format a boolean attribute based on its value
     */
    formatBooleanAttribute(element, attribute, trueCode = null, falseCode = null) {
        const value = element.getAttribute(attribute);

        if (trueCode !== null && ['true', '1'].includes(value)) {
            return trueCode;
        } else if (falseCode !== null && ['false', '0'].includes(value)) {
            return falseCode;
        }

        return '';
    }

    /**
     * Format a string for PHP output (with quotes)
     */
    formatString(value) {
        // Simple class detection for JavaScript (more limited than PHP)
        if (value && (value.includes('\\') || value.match(/^[A-Z][a-zA-Z0-9_]*$/))) {
            // Check if it looks like a class name
            const classPattern = /^[a-zA-Z_][a-zA-Z0-9_]*(?:\\[a-zA-Z_][a-zA-Z0-9_]*)*$/;
            if (classPattern.test(value)) {
                return '\\' + value.replace(/^\\/, '') + '::class';
            }
        }

        // Escape single quotes and backslashes
        let escapedValue;
        if (value.endsWith('\\')) {
            escapedValue = value.replace(/[\\']/g, '\\$&');
        } else {
            escapedValue = value.replace(/'/g, "\\'");
        }

        return "'" + escapedValue + "'";
    }

    /**
     * Format a value for PHP output, detecting type
     */
    formatValue(value) {
        // Try to detect the value type
        if (value.toLowerCase() === 'true') {
            return 'true';
        }

        if (value.toLowerCase() === 'false') {
            return 'false';
        }

        if (value.toLowerCase() === 'null') {
            return 'null';
        }

        if (!isNaN(value) && !isNaN(parseFloat(value))) {
            // Check if it's an integer or a float
            if (parseInt(value).toString() === value || parseFloat(value).toString() === value) {
                return value;
            }
        }

        // Default to string
        return this.formatString(value);
    }

    /**
     * Add a new line with proper indentation
     */
    nl(indentLevel = null) {
        return '\n' + '    '.repeat(indentLevel !== null ? indentLevel : this.indentLevel);
    }
}

// Export for both Node.js and browser environments
if (typeof module !== 'undefined' && module.exports) {
    module.exports = XmlToPhpConfigConverter;
} else if (typeof window !== 'undefined') {
    window.XmlToPhpConfigConverter = XmlToPhpConfigConverter;
}
