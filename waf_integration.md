# WAF Security System Integration

## Overview

This document describes the implementation and integration of the Web Application Firewall (WAF) security system in the Go.js-Lite project. The WAF system provides comprehensive protection against common web attacks including SQL injection, XSS, command injection, rate limiting, and geographic blocking.

## Features Implemented

### C1. IP Blacklist/Whitelist Management
- **Manual IP Blocking/Allowing**: Add individual IP addresses to block or allow lists
- **CIDR Support**: Support for CIDR notation (e.g., 192.168.1.0/24) for network ranges
- **Persistent Storage**: Rules stored in JSON configuration files
- **Real-time Enforcement**: Immediate enforcement of IP-based access controls

### C2. Basic WAF Rules
- **SQL Injection Detection**: Comprehensive pattern matching for SQL injection attacks
  - UNION SELECT detection
  - SQL keyword patterns (SELECT, INSERT, UPDATE, DELETE, DROP)
  - Comment-based attacks (--, /*, #)
  - Time-based attacks (WAITFOR DELAY)
  
- **XSS Detection**: Cross-site scripting attack prevention
  - Script tag detection
  - Event handler detection (onload, onclick, etc.)
  - Protocol-based attacks (javascript:, vbscript:)
  - Iframe and object embedding detection
  - PHP code injection attempts
  
- **Command Injection Detection**: System command injection prevention
  - Common command detection (ls, dir, cat, rm, etc.)
  - Shell metacharacter detection (|, ;, &&, ||)
  - Command execution patterns (exec, system, shell_exec)
  - Cross-platform command detection (cmd.exe, powershell)

### C3. Rate Limiting
- **Per-IP Rate Limiting**: Limit requests per IP address
- **Configurable Time Window**: Currently set to 1-hour window
- **Request Threshold**: Maximum 1000 requests per hour per IP
- **Automatic Cleanup**: Old requests automatically removed from tracking
- **CC Attack Prevention**: Effective against crawlers and denial-of-service attacks

### C4. Geographic Blocking
- **Country-based Blocking**: Block access from specific countries
- **IP Geolocation**: Integration with PHP's geoip extension
- **Caching**: IP-to-country mapping cached for performance
- **Flexible Configuration**: Easy addition/removal of blocked countries

## File Structure

```
backend/
├── waf.php                    # Main WAF implementation
└── autoload.php               # Updated to include WAF module

tests/
└── WafTest.php               # Comprehensive PHPUnit tests

waf_integration.md             # This documentation file

Configuration Files (created automatically):
└── .gojs/
    ├── waf_ip_rules.json     # IP blacklist/whitelist rules
    ├── waf_rules.json        # WAF rule configurations
    ├── waf_rate_limits.json  # Rate limiting data
    ├── waf_geo_blocks.json   # Geographic block rules
    ├── ip_geo_cache.json     # IP geolocation cache
    └── waf_attack_log.txt    # Attack logging
```

## Integration

### 1. Automatic Loading
The WAF system is automatically loaded through the `autoload.php` file and initializes during application startup.

### 2. Request Processing
The WAF system intercepts all incoming requests and applies security checks in the following order:
1. IP blacklist/whitelist checking
2. Rate limiting enforcement
3. Geographic blocking
4. Attack pattern detection (SQL injection, XSS, command injection)

### 3. Configuration Management
All WAF configurations are stored in JSON files within the `.gojs` directory:
- Rules can be modified directly in the JSON files
- Changes are applied immediately without requiring application restart
- File permissions are set to 600 for security

## Usage Examples

### Adding IP Rules
```php
// Block a specific IP
gojs_waf_add_ip_rule('192.168.1.100', 'block');

// Allow a CIDR range
gojs_waf_add_ip_rule('192.168.1.0/24', 'allow');

// Remove an IP rule
gojs_waf_remove_ip_rule('192.168.1.100');
```

### Managing Geographic Blocks
```php
// Block a country
gojs_waf_add_geo_block('CN');

// Remove a country block
gojs_waf_remove_geo_block('CN');
```

### Managing WAF Rules
```php
// Load current rules
$rules = gojs_waf_load_rules();

// Save rule changes
$newRules = [
    'sql_injection' => true,
    'xss' => true,
    'command_injection' => false
];
gojs_waf_save_rules($newRules);
```

## Testing

### Running Tests
```bash
# Run all WAF tests
./vendor/bin/phpunit tests/WafTest.php

# Run tests with coverage
./vendor/bin/phpunit tests/WafTest.php --coverage-html coverage
```

### Test Coverage
The test suite covers:
- IP blacklist/whitelist functionality
- CIDR matching accuracy
- SQL injection detection patterns
- XSS detection patterns
- Command injection detection patterns
- Rate limiting enforcement
- Geographic blocking
- Rule management
- Attack logging
- File permissions

## Performance Considerations

- **Memory Usage**: Minimal memory footprint with efficient data structures
- **Processing Speed**: Pattern matching optimized for performance
- **File I/O**: JSON files used for configuration with proper locking
- **Caching**: IP geolocation cached to reduce external lookups
- **Logging**: Efficient logging with minimal performance impact

## Security Best Practices

1. **Regular Rule Updates**: Keep WAF rules updated with new attack patterns
2. **Log Monitoring**: Regularly review attack logs for emerging threats
3. **IP Reputation**: Consider integrating with IP reputation services
4. **Rate Limit Tuning**: Adjust rate limits based on application requirements
5. **Geographic Considerations**: Carefully consider geographic blocking implications

## Troubleshooting

### Common Issues

1. **False Positives**: Adjust detection patterns if legitimate requests are blocked
2. **Performance Impact**: Monitor system performance under high load
3. **Configuration Errors**: Verify JSON syntax in configuration files
4. **Permission Issues**: Ensure proper file permissions on configuration files

### Debug Mode
Enable debug logging by checking the attack log file for detailed information about blocked requests.

## Future Enhancements

1. **Real-time Updates**: Web interface for rule management
2. **Machine Learning**: AI-based attack pattern detection
3. **Integration with External Services**: IP reputation, threat intelligence
4. **Advanced Analytics**: Detailed reporting and analytics
5. **Multi-language Support**: Additional programming language injection detection

## Contributing

When contributing to the WAF system:
1. Follow the existing code style
2. Add comprehensive tests for new features
3. Update documentation accordingly
4. Test thoroughly before committing changes

## License

This WAF system is part of the Go.js-Lite project and is subject to the same Apache 2.0 license.