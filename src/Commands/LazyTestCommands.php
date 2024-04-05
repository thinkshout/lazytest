<?php

namespace Drupal\lazytest\Commands;

use Drupal\lazytest\LazyTestService;
use Drush\Commands\DrushCommands;
use Drupal\lazytest\Plugin\URLProviderManager;

class LazyTestCommands extends DrushCommands {

  protected $lazyTestService;
  protected $urlProviderManager;

  public function __construct(LazyTestService $lazyTestService, URLProviderManager $urlProviderManager) {
    $this->lazyTestService = $lazyTestService;
    $this->urlProviderManager = $urlProviderManager;
  }

  /**
   * @command lazytest:plugins
   * @aliases ltp
   */
  public function plugins() {
    $definitions = $this->urlProviderManager->getDefinitions();
    $ids = [];
    foreach ($definitions as $definition) {
      $ids[] = $definition['id'];
    }
    $ids_string = implode(', ', $ids);
    $this->output()->writeln($ids_string);
  }

  /**
   * @command lazytest:run
   * @aliases ltr
   * @option baseurl A base url to override the base url from Drupal.
   * @option urls A comma-separated list of URLs to check.
   * @option plugins A comma-separated list of URL Providers to use.
   * @option crawl Enable crawl mode.
   * @option crawldepth Set crawl depth.
   */
  public function run($options = ['baseurl' => NULL, 'urls' => NULL, 'plugins' => NULL, 'crawl' => FALSE, 'crawldepth' => 1]) {

    $baseurl = $options["baseurl"];

    $urls = $this->lazyTestService->getAllURLs($options["urls"], $options["plugins"]);

    // Replace base url if provided
    if (!empty($baseurl)) {
      foreach ($urls as &$url) {
        $parsedUrl = parse_url($url['url']);
        $parsedUrl['scheme'] = parse_url($baseurl, PHP_URL_SCHEME);
        $parsedUrl['host'] = parse_url($baseurl, PHP_URL_HOST);
        $url['url'] = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . ($parsedUrl['path'] ?? '');
      }
      unset($url);
    }
    else {
      $parsedUrl = parse_url(reset($urls)['url']);
      $baseurl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
    }

    // Normalize all URLs before processing.
    foreach ($urls as &$url) {
      $url['url'] = $this->lazyTestService->normalizeUrl($url['url']);
    }
    unset($url);

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
    $urls = array_filter($urls, function($item) use ($current_scheme_and_host, $baseurl) {
      $itemHost = parse_url($item['url'], PHP_URL_SCHEME) . '://' . parse_url($item['url'], PHP_URL_HOST);
      return $itemHost === $current_scheme_and_host || $itemHost === $baseurl;
    });

    // Filter out specific urls like logout.
    $urls = array_filter($urls, function($item) {
      return strpos($item['url'], '/user/logout') === false;
    });

    $this->lazyTestService->checkURLs($baseurl, $urls, $options["crawl"], $options["crawldepth"]);
  }

}
