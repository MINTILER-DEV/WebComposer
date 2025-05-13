<?php

class Semver
{
    public static function satisfies(string $version, string $constraint): bool
    {
        // Normalize version string
        $version = self::normalize($version);
        
        // Handle multiple constraints separated by ||
        $orConstraints = preg_split('/\s*\|\|\s*/', $constraint);
        if (count($orConstraints) > 1) {
            foreach ($orConstraints as $orConstraint) {
                if (self::satisfiesConstraint($version, $orConstraint)) {
                    return true;
                }
            }
            return false;
        }
        
        // Handle space-separated AND constraints
        return self::satisfiesConstraint($version, $constraint);
    }

    private static function satisfiesConstraint(string $version, string $constraint): bool
    {
        $andConstraints = preg_split('/\s+/', trim($constraint));
        foreach ($andConstraints as $constraintPart) {
            if (!self::checkSingleConstraint($version, $constraintPart)) {
                return false;
            }
        }
        return true;
    }

    private static function checkSingleConstraint(string $version, string $constraint): bool
    {
        // Handle wildcard constraints
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        // Handle basic operators
        if (preg_match('/^([<>=]=?|!=)\s*([0-9.*]+)/', $constraint, $matches)) {
            $op = $matches[1];
            $constraintVersion = self::normalize($matches[2]);
            return self::compare($version, $constraintVersion, $op);
        }

        // Handle tilde (~) and caret (^) ranges
        if ($constraint[0] === '~' || $constraint[0] === '^') {
            return self::handleTildeCaret($version, $constraint);
        }

        // Handle hyphen ranges (1.0.0 - 2.0.0)
        if (strpos($constraint, ' - ') !== false) {
            list($lower, $upper) = explode(' - ', $constraint, 2);
            return self::compare($version, self::normalize($lower), '>=') && 
                   self::compare($version, self::normalize($upper), '<=');
        }

        // Default to exact match
        return self::compare($version, self::normalize($constraint), '=');
    }

    private static function handleTildeCaret(string $version, string $constraint): bool
    {
        $constraintVersion = substr($constraint, 1);
        $normalized = self::normalize($constraintVersion);
        $parts = explode('.', $normalized);
        
        if ($constraint[0] === '~') {
            // ~1.2.3 := >=1.2.3 <1.3.0
            $upper = $parts[0] . '.' . ($parts[1] + 1) . '.0';
            return self::compare($version, $normalized, '>=') && 
                   self::compare($version, $upper, '<');
        } else {
            // ^1.2.3 := >=1.2.3 <2.0.0
            $upper = ($parts[0] + 1) . '.0.0';
            return self::compare($version, $normalized, '>=') && 
                   self::compare($version, $upper, '<');
        }
    }

    private static function normalize(string $version): string
    {
        $version = preg_replace('/^v/', '', $version);
        $parts = explode('.', $version);
        while (count($parts) < 3) $parts[] = '0';
        return implode('.', array_slice($parts, 0, 3));
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