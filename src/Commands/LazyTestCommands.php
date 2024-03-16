<?php

namespace Drupal\lazytest\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\lazytest\LazyTestService;
use Drush\Commands\DrushCommands;

class LazyTestCommands extends DrushCommands {

  protected $lazyTestService;

  public function __construct(LazyTestService $lazyTestService) {
    $this->lazyTestService = $lazyTestService;
  }

  /**
   * @command lazytest:run
   * @aliases ltr
   */
  public function run() {
    $urls = $this->lazyTestService->getAllURLs();
    $urls = array_filter($urls, function($item) {
      return !empty($item['url']);
    });
    $urls = array_map('unserialize', array_unique(array_map('serialize', $urls), SORT_REGULAR));
    // Filter the URLs to only include those that have the same scheme and host as the current site
    $current_scheme_and_host = \Drupal::request()->getSchemeAndHttpHost();
    $urls = array_filter($urls, function($item) use ($current_scheme_and_host) {
      return parse_url($item['url'], PHP_URL_SCHEME) . '://' . parse_url($item['url'], PHP_URL_HOST) === $current_scheme_and_host;
    });
    $this->lazyTestService->checkURLs($urls);
  }

}
