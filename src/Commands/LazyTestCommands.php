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
    $urls = array_filter($urls); // This removes empty values
    $urls = array_unique($urls); // This removes duplicates
    $this->lazyTestService->checkURLs($urls);
  }

}

