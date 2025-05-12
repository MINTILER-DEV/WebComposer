# WebComposer - A Lightweight Dependency Manager for Shared Hosting

## Overview
WebComposer is a lightweight PHP dependency manager designed specifically for shared hosting environments where traditional Composer might not be available or practical. It provides basic dependency management with minimal requirements.

## Project Structure
```
webcomposer/
├── vendor/          # Installed packages
├── src/             # WebComposer source files
│   ├── Composer.php
│   ├── Package.php
│   ├── Autoload.php
│   ├── Semver.php
│   └── HttpClient.php
├── install.php      # Installation script
└── README.md        # This file
```

## Installation
```
just download install.php and run it.
```

## Usage

### Basic Usage
```php
require_once 'src/WebComposer.php';

$composer = new WebComposer();
$composer->require('psr/log', '^1.1');
$composer->require('monolog/monolog', '^2.0');
$composer->install();
```

### In Your Project
```php
require __DIR__ . '/vendor/autoload.php';

// Your code using installed packages...
```

## Features
- Lightweight alternative to Composer
- No shell access required
- Minimal dependencies (only requires cURL and Zip extensions)
- Supports PSR-4 and PSR-0 autoloading
- Handles recursive dependency resolution

## Requirements
- PHP 7.4+
- cURL extension
- Zip extension
- write permissions to project directory

## Limitations
- No support for dev dependencies
- No script execution
- Simplified version resolution compared to Composer

## License
MIT License

---

To use this in your project:
1. Run `install.php` to set up WebComposer
2. Create a bootstrap file that uses WebComposer
3. Include `vendor/autoload.php` in your application

Example bootstrap.php:
```php
<?php
require_once 'src/WebComposer.php';

// Initialize and install dependencies
$webComposer = new WebComposer();
$webComposer->require('psr/log', '^1.1');
$webComposer->install();

// Now your dependencies are available
require 'vendor/autoload.php';
```
