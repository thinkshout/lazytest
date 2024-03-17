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

    // Filter out empty urls.
    $urls = array_filter($urls, function($item) {
      return !empty($item['url']);
    });

    // Filter out non-unique urls.
    array_multisort(array_column($urls, 'url'), SORT_ASC, $urls);
    $urls = array_intersect_key(
      $urls,
      array_unique(array_column($urls, 'url'))
    );

    // Filter out external urls.
    $current_scheme_and_host = \Drupal::request()->getSchemeAndHttpHost();
    $urls = array_filter($urls, function($item) use ($current_scheme_and_host) {
      return parse_url($item['url'], PHP_URL_SCHEME) . '://' . parse_url($item['url'], PHP_URL_HOST) === $current_scheme_and_host;
    });

    $this->lazyTestService->checkURLs($urls);
  }

}
