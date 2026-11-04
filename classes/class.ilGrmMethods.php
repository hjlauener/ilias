<?php

declare(strict_types=1);

/**
 * Registry of the available assignment methods (a–j).
 *
 * Only the methods listed in IMPLEMENTED can be created and activated.
 * Further methods (b–j) will be added step by step.
 */
final class ilGrmMethods
{
    /** Lecturer decides: random assignment into a fixed number of groups / topics */
    public const METHOD_A = "a";

    /** Lecturer decides: random assignment into groups of a fixed size (2, 3, 4, …) */
    public const METHOD_B = "b";

    public const ALL = ["a", "b", "c", "d", "e", "f", "g", "h", "i", "j"];

    public const IMPLEMENTED = [self::METHOD_A, self::METHOD_B];

    public static function isValid(string $method): bool
    {
        return in_array($method, self::ALL, true);
    }

    public static function isImplemented(string $method): bool
    {
        return in_array($method, self::IMPLEMENTED, true);
    }
}
