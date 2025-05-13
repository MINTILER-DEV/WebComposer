<?php

class PackageResolver
{
    private static $cache = [];
    private static $githubApiUrl = 'https://api.github.com/repos/%s/%s/commits/%s';
    private static $virtualPackages = [
        'ext-json' => ['version' => '1.0', 'installed' => true],
        'ext-curl' => ['version' => '1.0', 'installed' => true],
        // More virtaul extensions..
    ];

    private static function cprint(string $str)
    {
        echo "$str<br>";
    }
    
    public static function resolve(string $name, string $constraint): array
    {
        self::cprint("Resolving package: $name");
        // Check for virtual packages first
        if (isset(self::$virtualPackages[$name])) {
            if (extension_loaded(str_replace('ext-', '', $name))) {
                return self::$virtualPackages[$name];
            }
            throw new \RuntimeException("Required PHP extension not loaded: $name");
        }

        $key = "$name:$constraint";
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // Handle branch references (dev-master, dev-develop, etc.)
        if (strpos($constraint, 'dev-') === 0) {
            try {
                $branch = substr($constraint, 4);
                return self::resolveBranch($name, $branch);
            } catch (Exception $e) {
                
            }
        }

        $data = HttpClient::get("https://repo.packagist.org/p2/$name.json");
        $versions = $data['packages'][$name] ?? [];
        
        usort($versions, function ($a, $b) {
            return version_compare($b['version_normalized'], $a['version_normalized']);
        });

        foreach ($versions as $version) {
            try {
                if (Semver::satisfies($version['version'], $constraint)) {
                    self::$cache[$key] = $version;
                    return $version;
                }
            } catch (\Exception $e) {
                // Log constraint resolution errors but continue
                error_log("Version constraint error for $name: " . $e->getMessage());
            }
        }

        throw new \RuntimeException("No matching version found for $name ($constraint)");
    }

    private static function resolveBranch(string $name, string $branch): array
    {
        // Extract vendor and package from name (e.g., "norkunas/youtube-dl-php")
        $parts = explode('/', $name);
        if (count($parts) !== 2) {
            throw new \RuntimeException("Invalid package name format for branch resolution");
        }
        
        list($vendor, $package) = $parts;
        
        try {
            // Get latest commit for the branch
            $url = sprintf(self::$githubApiUrl, $vendor, $package, $branch);
            $commitData = HttpClient::get($url);
            
            if (!isset($commitData['sha'])) {
                throw new \RuntimeException("Could not get commit hash for branch $branch");
            }
            
            return [
                'version' => 'dev-' . $branch,
                'version_normalized' => 'dev-' . $branch,
                'source' => [
                    'type' => 'git',
                    'url' => "https://github.com/$vendor/$package.git",
                    'reference' => $commitData['sha']
                ],
                'dist' => [
                    'type' => 'zip',
                    'url' => "https://api.github.com/repos/$vendor/$package/zipball/{$commitData['sha']}",
                    'reference' => $commitData['sha'],
                    'shasum' => ''
                ],
                'require' => [] // Will be filled from composer.json after installation
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to resolve branch $branch for $name: " . $e->getMessage());
        }
    }

    public static function install(string $name, array $versionData): void
    {
        self::cprint("Installing package: $name");
        // Skip installation for virtual packages
        if (isset(self::$virtualPackages[$name])) {
            return;
        }

        // Handle branch installations
        if (isset($versionData['source']['type']) && $versionData['source']['type'] === 'git') {
            self::installFromGit($name, $versionData);
            return;
        }

        $targetDir = "vendor/$name";
        $distUrl = $versionData['dist']['url'] ?? null;
        print_r($versionData);
        
        if (!$distUrl) {
            throw new \RuntimeException("No distribution URL for $name");
        }

        // Create target directory
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new \RuntimeException("Failed to create directory: $targetDir");
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'composer_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create temporary file");
        }

        try {
            // Download the package
            HttpClient::download($distUrl, $tempFile);

            self::cprint("Downloaded package: $name");
            
            // Create a temporary extraction directory
            $tempExtractDir = sys_get_temp_dir() . '/composer_extract_' . uniqid();
            if (!mkdir($tempExtractDir)) {
                throw new \RuntimeException("Failed to create temp extraction directory");
            }

            // Extract the package
            $zip = new ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new \RuntimeException("Failed to open zip archive");
            }

            if (!$zip->extractTo($tempExtractDir)) {
                throw new \RuntimeException("Failed to extract package contents");
            }
            $zip->close();

            // Handle GitHub's zipball structure
            $extractedFiles = scandir($tempExtractDir);
            $actualPackageDir = null;
            
            foreach ($extractedFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_dir("$tempExtractDir/$file")) {
                    $actualPackageDir = "$tempExtractDir/$file";
                    break;
                }
            }

            if (!$actualPackageDir) {
                throw new \RuntimeException("No package directory found in archive");
            }

            // Move files to the target directory
            self::moveDirectoryContents($actualPackageDir, $targetDir);

            // Verify extraction
            if (!file_exists("$targetDir/composer.json")) {
                throw new \RuntimeException("Extracted package is invalid (missing composer.json)");
            }
            self::cprint("Finished installing package: $name");
        } catch (\Exception $e) {
            // Clean up on failure
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if (isset($tempExtractDir) && is_dir($tempExtractDir)) {
                self::removeDirectory($tempExtractDir);
            }
            if (is_dir($targetDir)) {
                self::removeDirectory($targetDir);
            }
            self::cprint("Failed to install $name: " . $e->getMessage());
        } finally {
            // Clean up temp files
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if (isset($tempExtractDir) && is_dir($tempExtractDir)) {
                self::removeDirectory($tempExtractDir);
            }
        }
    }

    private static function installFromGit(string $name, array $versionData): void
    {
        $targetDir = "vendor/$name";
        $distUrl = $versionData['dist']['url'] ?? null;
        
        if (!$distUrl) {
            throw new \RuntimeException("No distribution URL for $name");
        }

        // Create target directory
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new \RuntimeException("Failed to create directory: $targetDir");
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'composer_');
        if ($tempFile === false) {
            throw new \RuntimeException("Failed to create temporary file");
        }

        try {
            // Download the zipball
            HttpClient::download($distUrl, $tempFile);
            
            // Extract the package
            $zip = new ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new \RuntimeException("Failed to open zip archive");
            }

            // Create temp extraction dir
            $tempExtractDir = sys_get_temp_dir() . '/composer_extract_' . uniqid();
            if (!mkdir($tempExtractDir)) {
                throw new \RuntimeException("Failed to create temp extraction directory");
            }

            if (!$zip->extractTo($tempExtractDir)) {
                throw new \RuntimeException("Failed to extract package contents");
            }
            $zip->close();

            // Handle GitHub's zipball structure (single directory with commit hash)
            $extractedFiles = scandir($tempExtractDir);
            $sourceDir = null;
            
            foreach ($extractedFiles as $file) {
                if ($file !== '.' && $file !== '..' && is_dir("$tempExtractDir/$file")) {
                    $sourceDir = "$tempExtractDir/$file";
                    break;
                }
            }

            if (!$sourceDir) {
                throw new \RuntimeException("No package directory found in archive");
            }

            // Move files to target directory
            self::recursiveCopy($sourceDir, $targetDir);
            
            // Verify installation
            if (!file_exists("$targetDir/composer.json")) {
                throw new \RuntimeException("Installed package is invalid (missing composer.json)");
            }
        } finally {
            // Clean up
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if (isset($tempExtractDir) && is_dir($tempExtractDir)) {
                self::removeDirectory($tempExtractDir);
            }
        }
    }

    private static function recursiveCopy(string $source, string $dest): void
    {
        $dir = opendir($source);
        @mkdir($dest);
        
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $sourcePath = "$source/$file";
            $destPath = "$dest/$file";
            
            if (is_dir($sourcePath)) {
                self::recursiveCopy($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
        }
        
        closedir($dir);
    }

    private static function moveDirectoryContents(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $items->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target);
                }
            } else {
                rename($item->getPathname(), $target);
            }
        }
    }

    private static function validateZipFile(string $filePath): bool
    {
        // Simple ZIP validation by checking magic number
        $fh = @fopen($filePath, 'rb');
        if (!$fh) return false;
        
        $magic = fread($fh, 4);
        fclose($fh);
        
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06" || $magic === "PK\x07\x08";
    }

    private static function getZipErrorMessage(int $code): string
    {
        $errors = [
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Malloc failure',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_NOZIP => 'Not a zip archive',
            ZipArchive::ER_OPEN => 'Can\'t open file',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error',
        ];
        
        return $errors[$code] ?? 'Unknown error';
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}