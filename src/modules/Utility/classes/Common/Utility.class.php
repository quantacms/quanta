<?php

namespace Quanta\Common;

class Utility
{

    /**
     * Replace elements in an array while preserving excluded elements.
     *
     * This function replaces the elements of the original array with the elements from 
     * a replacement array, but keeps the elements from the original array that are in 
     * the excluded list intact.
     *
     * @param array $original
     *   The original array to be replaced.
     * @param array $replacement
     *   The array that will replace the original array.
     * @param array $excluded
     *   An array of elements that should remain unchanged from the original array.
     *
     * @return array
     *   A new array where the elements from the original array are replaced by the 
     *   replacement array, except for the excluded elements which remain unchanged.
     */
    public static function replace_array_with_exclusions($original, $replacement, $excluded)
    {
        $preservedExcluded = array_filter($original, function ($element) use ($excluded) {
            return in_array($element, $excluded);
        });

        return array_merge($replacement, $preservedExcluded);
    }
}
