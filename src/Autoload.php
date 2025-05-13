<?php
class AutoloadGenerator
{
    private static $mappings = [
        'psr-4' => [],
        'psr-0' => [],
        'classmap' => [],
        'files' => []
    ];

    public static function addPackage(string $path): void
    {
        $configFile = "$path/composer.json";
        if (!file_exists($configFile)) return;

        $config = json_decode(file_get_contents($configFile), true);
        $autoload = $config['autoload'] ?? [];

        foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $type) {
            $entries = $autoload[$type] ?? [];
            
            if ($type === 'files') {
                self::$mappings[$type] = array_merge(
                    self::$mappings[$type],
                    array_map(fn($f) => "$path/$f", $entries)
                );
            } else {
                foreach ($entries as $prefix => $paths) {
                    $fullPaths = array_map(fn($p) => "$path/$p", (array)$paths);
                    if (!isset(self::$mappings[$type][$prefix])) {
                        self::$mappings[$type][$prefix] = [];
                    }
                    self::$mappings[$type][$prefix] = array_merge(
                        self::$mappings[$type][$prefix],
                        $fullPaths
                    );
                }
            }
        }
    }

    public static function generate(): void
    {
        // Load existing autoloader if it exists
        $existingLoader = [];
        $loaderFile = 'vendor/autoload.php';
        if (file_exists($loaderFile)) {
            $content = file_get_contents($loaderFile);
            preg_match_all('/\/\/ psr-4 autoloading.*?if \(strpos\(\$class, \'(.*?)\\\\\\) === 0\) {.*?paths = \[(.*?)\];/s', $content, $psr4Matches);
            preg_match_all('/\/\/ psr-0 autoloading.*?if \(strpos\(\$class, \'(.*?)\\\\\\) === 0\) {.*?paths = \[(.*?)\];/s', $content, $psr0Matches);
            preg_match('/\$classMap = (\[.*?\])/s', $content, $classMapMatch);
            preg_match('/foreach \(array \((.*?)\) as \$file\)/s', $content, $filesMatch);

            // Merge existing PSR-4 mappings
            foreach ($psr4Matches[1] as $i => $prefix) {
                $paths = array_map('trim', explode(',', $psr4Matches[2][$i]));
                $paths = array_map(fn($p) => trim($p, "'\""), $paths);
                if (!isset(self::$mappings['psr-4'][$prefix])) {
                    self::$mappings['psr-4'][$prefix] = [];
                }
                self::$mappings['psr-4'][$prefix] = array_merge(
                    self::$mappings['psr-4'][$prefix],
                    $paths
                );
            }

            // Merge existing PSR-0 mappings
            foreach ($psr0Matches[1] as $i => $prefix) {
                $paths = array_map('trim', explode(',', $psr0Matches[2][$i]));
                $paths = array_map(fn($p) => trim($p, "'\""), $paths);
                if (!isset(self::$mappings['psr-0'][$prefix])) {
                    self::$mappings['psr-0'][$prefix] = [];
                }
                self::$mappings['psr-0'][$prefix] = array_merge(
                    self::$mappings['psr-0'][$prefix],
                    $paths
                );
            }

            // Merge existing classmap
            if (!empty($classMapMatch[1])) {
                eval('$existingClassMap = ' . $classMapMatch[1] . ';');
                foreach ($existingClassMap as $class => $file) {
                    if (!isset(self::$mappings['classmap'][$class])) {
                        self::$mappings['classmap'][$class] = $file;
                    }
                }
            }

            // Merge existing files
            if (!empty($filesMatch[1])) {
                $existingFiles = array_map('trim', explode(',', $filesMatch[1]));
                $existingFiles = array_map(fn($f) => trim($f, "'\""), $existingFiles);
                self::$mappings['files'] = array_merge(
                    self::$mappings['files'],
                    $existingFiles
                );
            }
        }

        // Generate new autoloader with all mappings
        $loader = '<?php // AUTO GENERATED AUTOLOADER' . PHP_EOL;
        $loader .= 'spl_autoload_register(function ($class) {' . PHP_EOL;
        $loader .= self::generateClassMapCode();
        $loader .= self::generatePsrCode('psr-4');
        $loader .= self::generatePsrCode('psr-0');
        $loader .= '});' . PHP_EOL;
        $loader .= self::generateFilesCode();
        
        file_put_contents($loaderFile, $loader);
    }

    private static function generateClassMapCode(): string
    {
        $code = '    // Classmap autoloading' . PHP_EOL;
        $classMap = [];
        
        foreach (self::$mappings['classmap'] as $prefix => $dirs) {
            foreach ($dirs as $dir) {
                foreach (glob("$dir/**/*.php") as $file) {
                    $classes = self::findClassesInFile($file);
                    foreach ($classes as $class) {
                        $classMap[$class] = $file;
                    }
                }
            }
        }

        return $code . '    $classMap = ' . var_export($classMap, true) . ';' . PHP_EOL .
            '    if (isset($classMap[$class])) require $classMap[$class];' . PHP_EOL;
    }

    private static function generatePsrCode(string $type): string
    {
        $code = "    // $type autoloading\n";
        $mappings = self::$mappings[$type];
        
        foreach ($mappings as $prefix => $dirGroups) {
            $prefix = trim($prefix, '\\');
            $prefixLength = strlen($prefix);
            
            $code .= "    if (strpos(\$class, '$prefix\\'') === 0) {\n";
            
            if ($type === 'psr-4') {
                $code .= "        \$relativeClass = substr(\$class, $prefixLength);\n";
                $code .= "        \$path = str_replace('\\\\', '/', \$relativeClass) . '.php';\n";
            } else { // psr-0
                $code .= "        \$path = str_replace(['\\\\', '_'], '/', \$class) . '.php';\n";
            }
            
            $code .= "        \$paths = [\n";
            foreach ($dirGroups as $dir) {
                $code .= "            '$dir/' . \$path,\n";
            }
            $code .= "        ];\n";
            
            $code .= "        foreach (\$paths as \$file) {\n";
            $code .= "            if (file_exists(\$file)) { require \$file; return; }\n";
            $code .= "        }\n";
            $code .= "    }\n";
        }
        
        return $code;
    }

    private static function generateFilesCode(): string
    {
        return '// File autoloading' . PHP_EOL .
            'foreach (' . var_export(self::$mappings['files'], true) . ' as $file) {' . PHP_EOL .
            '    if (file_exists($file)) require_once $file;' . PHP_EOL .
            '}' . PHP_EOL;
    }

    private static function findClassesInFile(string $file): array
    {
        $contents = file_get_contents($file);
        $tokens = token_get_all($contents);
        $classes = [];
        $namespace = '';
        
        for ($i = 0; $i < count($tokens); $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j] === ';') break;
                    $namespace .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }
                $namespace = trim($namespace);
            }
            
            if ($tokens[$i][0] === T_CLASS && $tokens[$i + 1][0] === T_WHITESPACE && $tokens[$i + 2][0] === T_STRING) {
                $classes[] = $namespace ? "$namespace\\{$tokens[$i + 2][1]}" : $tokens[$i + 2][1];
            }
        }
        
        return $classes;
    }
}