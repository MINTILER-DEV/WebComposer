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
                    self::$mappings[$type][$prefix] = array_merge(
                        self::$mappings[$type][$prefix] ?? [],
                        $fullPaths
                    );
                }
            }
        }
    }

    public static function generate(): void
    {
        $loader = '<?php // AUTO GENERATED AUTOLOADER' . PHP_EOL;
        $loader .= 'spl_autoload_register(function ($class) {' . PHP_EOL;
        $loader .= self::generateClassMapCode();
        $loader .= self::generatePsrCode('psr-4');
        $loader .= self::generatePsrCode('psr-0');
        $loader .= '});' . PHP_EOL;
        $loader .= self::generateFilesCode();
        
        file_put_contents('vendor/autoload.php', $loader);
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