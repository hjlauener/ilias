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

    /** First come – first serve: students enroll themselves, optional waitlist */
    public const METHOD_C = "c";

    /** Registration with priorities, assignment by algorithm, corrections by the lecturer */
    public const METHOD_D = "d";

    public const ALL = ["a", "b", "c", "d", "e", "f", "g", "h", "i", "j"];

    public const IMPLEMENTED = [self::METHOD_A, self::METHOD_B, self::METHOD_C, self::METHOD_D];

    /** Methods where the lecturer distributes the people randomly (draw) */
    public const RANDOM_DRAW = [self::METHOD_A, self::METHOD_B];

    /** Methods with a limited number of places per group */
    public const WITH_PLACES = [self::METHOD_C, self::METHOD_D];

    public static function usesRandomDraw(string $method): bool
    {
        return in_array($method, self::RANDOM_DRAW, true);
    }

    public static function hasPlaces(string $method): bool
    {
        return in_array($method, self::WITH_PLACES, true);
    }

    public static function isValid(string $method): bool
    {
        return in_array($method, self::ALL, true);
    }

    public static function isImplemented(string $method): bool
    {
        return in_array($method, self::IMPLEMENTED, true);
    }
}
