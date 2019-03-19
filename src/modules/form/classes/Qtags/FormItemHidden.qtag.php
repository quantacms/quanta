<?php
namespace Quanta\Qtags;

/**
 * Class FormItemHidden
 * This class represents a Form Item of type dropdown Select
 */
class FormItemHidden extends FormItemString {
  public $type = 'hidden';
  protected $label_position = Label::LABEL_HIDDEN;

}
