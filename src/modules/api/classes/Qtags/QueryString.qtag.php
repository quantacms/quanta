<?php

namespace Quanta\Qtags;

use Quanta\Common\Api;

/**
 * Returns a parameter from the Query String.
 */
class QueryString extends Qtag
{
    /**
     * Render the Qtag.
     *
     * @return string
     *   The rendered Qtag.
     */
    public function render()
    {
        $name = $this->getAttribute('name');
        if (empty($name) || !isset($_REQUEST[$name])) {
            return '';
        }
        $value = $_REQUEST[$name];
        if ($this->getAttribute('JSON') && !empty($this->getAttribute('data'))) {
            if (!is_string($value)) {
                return '';
            }
            $request_data = json_decode($value);
            $value = is_object($request_data) ? ($request_data->{$this->getAttribute('data')} ?? null) : null;
        }
        if (!is_scalar($value)) {
            return '';
        }

        // Request values are text, not HTML or QTags for a later substitution pass.
        return Api::string_normalize(htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), true);
    }
}
