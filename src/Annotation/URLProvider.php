<?php

namespace Drupal\lazytest\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines an annotation object.
 *
 * @see \Drupal\lazytest\Plugin\URLProviderManager
 * @see plugin_api
 *
 * @Annotation
 */
class URLProvider extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label of the plugin.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

}
