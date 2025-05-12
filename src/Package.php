<?php

class PackageResolver
{
    private static $cache = [];

    public static function resolve(string $name, string $constraint): array
    {
        $key = "$name:$constraint";
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $data = HttpClient::get("https://repo.packagist.org/p2/$name.json");
        $versions = $data['packages'][$name] ?? [];
        
        usort($versions, function ($a, $b) {
            return version_compare($b['version_normalized'], $a['version_normalized']);
        });

        foreach ($versions as $version) {
            if (Semver::satisfies($version['version'], $constraint)) {
                self::$cache[$key] = $version;
                return $version;
            }
        }

        throw new \RuntimeException("No matching version found for $name ($constraint)");
    }

    public static function install(string $name, array $versionData): void
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
            // Download the package
            HttpClient::download($distUrl, $tempFile);
            
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
            throw new \RuntimeException("Failed to install $name: " . $e->getMessage());
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