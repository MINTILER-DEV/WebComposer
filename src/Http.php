<?php

class HttpClient
{
    public static function get(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'User-Agent: AdvancedComposer/1.0',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200 || !$response) {
            throw new \RuntimeException("Failed to fetch $url (HTTP $status)");
        }

        return json_decode($response, true) ?? [];
    }

    public static function download(string $url, string $destination): void
    {
        $fp = fopen($destination, 'w+');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open $destination for writing");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HTTPHEADER => [
                'User-Agent: AdvancedComposer/1.0'
            ],
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => function($curl, $header) {
                // GitHub might rate limit us, check for these headers
                if (stripos($header, 'X-RateLimit-Remaining:') === 0 && trim(substr($header, 21)) === '0') {
                    throw new \RuntimeException("GitHub API rate limit exceeded");
                }
                return strlen($header);
            }
        ]);
        
        if (!curl_exec($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            unlink($destination);
            throw new \RuntimeException("Download failed: $error");
        }
        
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($status !== 200) {
            unlink($destination);
            throw new \RuntimeException("Download failed with HTTP status $status");
        }

        // Verify the downloaded file is actually a ZIP
        if (!self::isValidZip($destination)) {
            unlink($destination);
            throw new \RuntimeException("Downloaded file is not a valid ZIP archive");
        }
    }

    private static function isValidZip(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        // Check the first 4 bytes for ZIP magic number
        $fh = fopen($path, 'rb');
        if (!$fh) return false;
        
        $magic = fread($fh, 4);
        fclose($fh);
        
        return $magic === "PK\x03\x04" || $magic === "PK\x05\x06" || $magic === "PK\x07\x08";
    }
}