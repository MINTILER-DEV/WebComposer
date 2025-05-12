<?php
// install.php - WebComposer installer

// Create directory structure
$structure = [
    'vendor',
    'src'
];

foreach ($structure as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

$files = [
    'Composer.php',
    'Package.php',
    'Autoload.php',
    'Semver.php',
    'Http.php'
];

$baseUrl = 'https://raw.githubusercontent.com/MINTILER-DEV/webcomposer/main/src/';

foreach ($files as $file) {
    $content = file_get_contents($baseUrl . $file);
    file_put_contents("src/$file", $content);
}

echo "WebComposer installed successfully!\n";
echo "Include 'vendor/autoload.php' in your project to use installed packages.\n";
