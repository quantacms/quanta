<?php

namespace Quanta\Qtags;

/**
 *
 * Renders the teaser of a node.
 *
 */
class Teaser extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $node = \Quanta\Common\NodeFactory::loadOrCurrent($this->env, $this->getTarget());
    $teaser = \Quanta\Common\Api::string_normalize(\Quanta\Common\Api::filter_xss($node->getTeaser()));

    // If teaser field is not valorized, and teaser has the "trim" attribute, try using trimmed body as teaser.
    if (empty($teaser) && !empty($this->getAttribute('trim')) ) {
      // Default max length for trimmed body: 255 characters. Can be overridden by trim_length attribute.
      $max_length = !empty($this->getAttribute('trim_length')) ? $this->getAttribute('trim_length') : 255;
      $teaser = substr(\Quanta\Common\Api::strip_qtags(strip_tags($node->getBody())), 0, $max_length);
    }
    if (!empty($teaser)) {
      return $teaser;
    }
  }
}
