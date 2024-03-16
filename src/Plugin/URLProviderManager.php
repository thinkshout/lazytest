<?php

namespace Drupal\lazytest\Plugin;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides the URL Provider plugin manager.
 */
class URLProviderManager extends DefaultPluginManager {

  /**
   * Constructs a new URLProviderManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/URLProvider',
      $namespaces,
      $module_handler,
      'Drupal\lazytest\Plugin\URLProviderInterface',
      'Drupal\lazytest\Annotation\URLProvider'
    );
    $this->alterInfo('lazytest_url_provider_info');
    $this->setCacheBackend($cache_backend, 'lazytest_url_provider_plugins');
  }

}
