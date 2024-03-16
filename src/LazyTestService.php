<?php

namespace Drupal\lazytest;

use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use Drupal\Core\Database\Database;
use Drupal\Core\Logger\RfcLogLevel;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class LazyTestService {

  protected $urlProviderManager;

  public function __construct(URLProviderManager $urlProviderManager) {
    $this->urlProviderManager = $urlProviderManager;
  }

  public function getAllURLs() {
    $allURLs = [];
    $definitions = $this->urlProviderManager->getDefinitions();
    foreach ($definitions as $definition) {
      $instance = $this->urlProviderManager->createInstance($definition['id']);
      $allURLs = array_merge($allURLs, $instance->getURLs());
    }
    return $allURLs;
  }

  public function checkURLs($urls) {

    // override url for debugging.
    $urls = [];
    $urls[] = "http://web.lvhn.localhost/admin/content";
    $urls[] = "http://web.lvhn.localhost/adminfoo/content";

    $client = new Client(['base_uri' => 'http://web.lvhn.localhost/']);
    $jar = new CookieJar;
    $errors = [];

    // Create a one-time login link for user 1
    $user = \Drupal\user\Entity\User::load(1);
    $timestamp = \Drupal::time()->getRequestTime();
    $link = Url::fromRoute(
      'user.reset.login',
      [
        'uid' => $user->id(),
        'timestamp' => $timestamp,
        'hash' => user_pass_rehash($user, $timestamp),
      ],
    )->toString();

    // Use the one-time login link and get the cookies.
    $client->request('GET', $link, ['cookies' => $jar]);
    $cookies = $jar->toArray();

    // Extract the session cookie name and value
    $session_cookie = '';
    foreach ($cookies as $cookie) {
      if (strpos($cookie['Name'], 'SESS') === 0) {
        $session_cookie = $cookie['Name'] . '=' . $cookie['Value'];
        break;
      }
    }

    $promises = function () use ($urls, $client, $session_cookie) {
      foreach ($urls as $url) {
        yield function() use ($client, $url, $session_cookie) {
          return $client->getAsync($url, [
            'headers' => [
              'Cookie' => $session_cookie,
            ],
          ]);
        };
      }
    };

    $pool = new Pool($client, $promises(), [
      'concurrency' => 100,
      'fulfilled' => function ($response, $url) use (&$errors) {
        $statusCode = $response->getStatusCode();
        drush_print("Request to $url completed with status code $statusCode");
        //        if ($statusCode >= 500) {
          // Please fix the url value here.
//          $errors[] = ['url' => $url, 'code' => $statusCode, 'log_message' => ''];
//        }
      },
      'rejected' => function ($reason, $url) use (&$errors) {
        $errors[] = ['url' => (string) $reason->getRequest()->getUri(), 'code' => $reason->getCode(), 'log_message' => 'test2'];
        drush_print("Request to $url failed with error: " . $reason->getMessage());
      },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    // End the session when you're done
    \Drupal::service('session_manager')->destroy();

    return $errors;
  }

}
