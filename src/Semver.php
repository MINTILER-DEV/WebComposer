<?php

class Semver
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $version = self::normalize($version);
        $constraints = self::parseConstraint($constraint);
        
        foreach ($constraints as $c) {
            if (!self::compare($version, $c['version'], $c['operator'])) {
                return false;
            }
        }
        return true;
    }

    private static function normalize(string $version): string
    {
        $version = preg_replace('/^v/', '', $version);
        $parts = explode('.', $version);
        while (count($parts) < 3) $parts[] = '0';
        return implode('.', array_slice($parts, 0, 3));
    }

    private static function parseConstraint(string $constraint): array
    {
        $constraint = preg_replace('/\s+/', '', $constraint);
        preg_match_all('/([<=>!~^]+)?([0-9.*]+)/', $constraint, $matches);
        
        $results = [];
        foreach ($matches[0] as $i => $_) {
            $op = $matches[1][$i] ?: '=';
            $version = $matches[2][$i];
            
            if ($op === '~') {
                $results[] = ['operator' => '>=', 'version' => $version];
                $results[] = ['operator' => '<', 'version' => self::bumpMinor($version)];
            } elseif ($op === '^') {
                $results[] = ['operator' => '>=', 'version' => $version];
                $results[] = ['operator' => '<', 'version' => self::bumpMajor($version)];
            } elseif (strpos($op, '!') !== false) {
                $results[] = ['operator' => '!=', 'version' => $version];
            } else {
                $results[] = ['operator' => $op ?: '=', 'version' => $version];
            }
        }
        
        return $results;
    }

    private static function bumpMajor(string $version): string
    {
        $parts = explode('.', $version);
        $parts[0] = (string)((int)$parts[0] + 1);
        return implode('.', $parts) . '.0';
    }

    private static function bumpMinor(string $version): string
    {
        $parts = explode('.', $version);
        $parts[1] = (string)((int)$parts[1] + 1);
        return implode('.', $parts) . '.0';
    }

    private static function compare(string $version, string $constraintVersion, string $operator): bool
    {
        $constraintVersion = self::normalize($constraintVersion);
        
        $compare = version_compare(
            self::normalize($version),
            $constraintVersion
        );

        switch ($operator) {
            case '>=': return $compare >= 0;
            case '<=': return $compare <= 0;
            case '>': return $compare > 0;
            case '<': return $compare < 0;
            case '!=': return $compare !== 0;
            default: return $compare === 0;
        }
    }
}