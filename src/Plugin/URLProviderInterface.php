<?php

namespace Drupal\lazytest\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

interface URLProviderInterface extends PluginInspectionInterface {

  public function getURLs();

}
