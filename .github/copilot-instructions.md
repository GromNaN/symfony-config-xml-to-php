# GitHub Copilot Instructions

## Project Overview
This repository contains a Symfony XML to PHP configuration converter with both PHP and JavaScript implementations:
- **PHP version**: `src/XmlToPhpConfigConverter.php` - Main implementation for CLI tool
- **JavaScript version**: `public/js/XmlToPhpConfigConverter.js` - Browser-based converter

## Critical Synchronization Requirements

### 🔄 Keep JS and PHP Versions in Sync
When modifying the PHP converter class (`src/XmlToPhpConfigConverter.php`), **ALWAYS** apply equivalent changes to the JavaScript version (`public/js/XmlToPhpConfigConverter.js`).

### Key Synchronization Points:
1. **Method signatures** - Ensure all public methods exist in both versions
2. **Processing logic** - XML parsing and PHP output generation must be identical
3. **Error handling** - Same validation rules and error messages
4. **Output formatting** - Identical PHP code generation (indentation, syntax)
5. **Feature support** - All Symfony service container features supported equally

### Language-Specific Considerations:
- **PHP**: Uses `DOMDocument`, `DOMXPath`, native PHP functions
- **JavaScript**: Uses `DOMParser`, browser DOM APIs, JavaScript equivalents
- **Reserved words**: Avoid JS reserved keywords (e.g., use `instanceofElement` not `instanceof`)
- **Strict mode**: Ensure JS code works in strict mode (avoid `arguments` variable name)

### Testing Requirements:
When adding new features:
1. Add test cases to PHP test suite
2. Verify browser version produces identical output
3. Test with sample XML configurations in `tests/Fixtures/`

### Documentation Updates:
- Update method documentation in both files
- Keep README.md installation instructions current
- Update browser interface descriptions if UI changes

## Symfony Version Compatibility
- **Target**: Symfony 5.4+ (LTS) through Symfony 8.x
- **Focus**: Migration from XML (deprecated/removed in Symfony 8) to PHP config
- **Audience**: Bundle maintainers and application developers

## Code Style Guidelines
- **PHP**: Follow PSR-12, use typed properties/parameters where possible
- **JavaScript**: Use ES6+ features, maintain browser compatibility
- **Consistency**: Method names, variable names, and logic flow should mirror between versions

## Common Pitfalls to Avoid
1. ❌ Don't update only one version - always sync both
2. ❌ Don't use JavaScript reserved keywords as parameter names
3. ❌ Don't forget to update browser UI if adding new features
4. ❌ Don't break backward compatibility with existing XML configs
5. ❌ Don't add PHP-specific features that can't be replicated in JS

## When Making Changes
1. **Start with PHP version** - This is the canonical implementation
2. **Port to JavaScript** - Adapt PHP logic to JavaScript patterns
3. **Test both versions** - Ensure identical output for same input
4. **Update tests** - Add corresponding test cases
5. **Update documentation** - Keep all docs in sync

## Examples of Good Synchronization

### Adding a new XML element processor:
```php
// PHP version
public function processNewElement(DOMElement $element): string
{
    $attribute = $element->getAttribute('name');
    return $this->nl() . '$services->newFeature(' . $this->formatString($attribute) . ');';
}
```

```javascript
// JavaScript version  
processNewElement(element) {
    const attribute = element.getAttribute('name');
    return this.nl() + '$services->newFeature(' + this.formatString(attribute) + ');';
}
```

### Error handling synchronization:
```php
// PHP version
if (!$element->hasAttribute('required-attr')) {
    throw new InvalidArgumentException('Missing required attribute: required-attr');
}
```

```javascript
// JavaScript version
if (!element.hasAttribute('required-attr')) {
    throw new Error('Missing required attribute: required-attr');
}
```

Remember: The goal is that both converters produce **identical PHP output** for any given XML input.
