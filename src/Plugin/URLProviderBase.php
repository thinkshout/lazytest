<?php

namespace Drupal\lazytest\Plugin;

use Drupal\Component\Plugin\PluginBase;

abstract class URLProviderBase extends PluginBase implements URLProviderInterface {

  public function getURLs() {
    return [];
  }

}
