<?php

namespace Drupal\lazytest;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Url;
use Drupal\lazytest\Plugin\URLProviderManager;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Helper\ProgressBar;


class LazyTestService {

  protected $urlProviderManager;

  public function __construct(URLProviderManager $urlProviderManager) {
    $this->urlProviderManager = $urlProviderManager;
  }

  public function getAllURLs() {
    $output = new ConsoleOutput();
    $output->writeln("Creating list of urls and starting download.");
    $allURLs = [];
    $definitions = $this->urlProviderManager->getDefinitions();
    foreach ($definitions as $definition) {
//      if ($definition["id"] == 'content_type_url_provider') {
        $instance = $this->urlProviderManager->createInstance($definition['id']);
        $allURLs = array_merge($allURLs, $instance->getURLs());
//      }
    }
    return $allURLs;
  }

  public function checkURLs($urls) {

    // We want to get fresh versions of pages.
    drupal_flush_all_caches();

    $output = new ConsoleOutput();
    $progressBar = new ProgressBar($output, count($urls));
    $progressBar->start();
    $completedRequests = 0;

    $messages = [];

    $client = new Client([
      'base_uri' => 'http://web.lvhn.localhost/',
      'verify' => false, // Ignore SSL certificate errors
      'defaults' => [
        'headers' => [
          'Connection' => 'keep-alive',
        ],
      ],
    ]);
    $jar = new CookieJar;

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

    $startTimestamp = time();

    $promises = function () use ($urls, $client, $session_cookie) {
      foreach ($urls as $index => $url) {
        yield $index => function() use ($client, $url, $session_cookie) {
          return $client->requestAsync('HEAD', $url["url"], [
            'headers' => [
              'Cookie' => $session_cookie,
            ],
            'timeout' => 120,
            'allow_redirects' => [
              'max' => 20, // follow up to x redirects
              'strict' => false, // use strict RFC compliant redirects
              'referer' => true, // add a Referer header
              'protocols' => ['http', 'https'], // restrict redirects to 'http' and 'https'
            ],
          ]);
        };
      }
    };

    $pool = new Pool($client, $promises(), [
      'concurrency' => 8,
      'fulfilled' => function ($response, $index) use ($output, $urls, $startTimestamp, &$messages, &$completedRequests, $progressBar) {
        $code = $response->getStatusCode();
        $url = $urls[$index];
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        if (!empty($log_messages) || $code != 200) {
          $message = [
            'source' => $url["source"],
            'subsource' => $url["subsource"] ?? '',
            'code' => $code,
            'url' => $url["url"],
            'message' => $log_messages,
          ];
          $messages[] = $message;
        }
        // Update the progress bar
        $completedRequests++;
        $progressBar->setProgress($completedRequests);
      },
      'rejected' => function ($reason, $index) use ($output, $urls, $startTimestamp, &$messages, &$completedRequests, $progressBar) {
        $code = $reason->getCode();
        $url = $urls[$index];
        $log_messages = $this->getLogMessages($url, $startTimestamp);
        $message = [
          'source' => $url["source"],
          'subsource' => $url["subsource"] ?? '',
          'code' => $code,
          'url' => $url["url"],
          'message' => $log_messages,
        ];
        $messages[] = $message;
        // Update the progress bar
        $completedRequests++;
        $progressBar->setProgress($completedRequests);
      },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    // End the user session
    \Drupal::service('session_manager')->destroy();

    // Sort
    $source = array_column($messages, 'source');
    $subsource = array_column($messages, 'subsource');
    $url = array_column($messages, 'url');
    array_multisort($source, SORT_ASC, $subsource, SORT_ASC, $url, SORT_ASC, $messages);

    if (!empty($messages)) {
      $output->writeln("\n\nDone. Copy this into a csv file and import into a spreadsheet like Google Sheets.\n");
      $messagesCsv = $this->getLogMessagesAsCsv($messages);
      $output->writeln($messagesCsv);
      $output->writeln("\nAnalysis:\n---------\n");
      $this->getLogMessagesAnalysis($messages);
    }
    else {
      $output->writeln("\n\nDone. No issues found.\n");
    }

  }

  public function getLogMessagesAnalysis($messages) {
    $consolidatedErrors = [];

    foreach ($messages as $result) {
      foreach ($result['message'] as $message) {
        // Create a unique key for each error type based on source, code, and message
        $errorKey = $result['source'] . '|' . $result['code'] . '|' . $message['message'];

        // Check if this error type has already been encountered
        if (!array_key_exists($errorKey, $consolidatedErrors)) {
          // If new, initialize the structure to hold error details and the first URL
          $consolidatedErrors[$errorKey] = [
            'source' => $result['source'],
            'code' => $result['code'],
            'message' => $message['message'],
            'urls' => [$result['url']],
            'count' => 1,
          ];
        } else {
          // If the error already exists, just append the URL to the list of affected URLs
          // This also checks to avoid duplicate URLs for the same error
          $consolidatedErrors[$errorKey]['count']++;  // Increment count
          if (!in_array($result['url'], $consolidatedErrors[$errorKey]['urls'])) {
            $consolidatedErrors[$errorKey]['urls'][] = $result['url'];
          }
        }
      }
    }

    // Output or further process the $consolidatedErrors array
    // For simplicity, the following code just prints the errors and associated URLs
    foreach ($consolidatedErrors as $error) {
      echo "Source: " . $error['source'] . " / HTTP Status code: " . $error['code'] . " / Error count: " .  $error['count'] . "\n";
      echo "Error Message: " . $error['message'] . "\n";
      echo "Affected URLs:\n";
      foreach ($error['urls'] as $url) {
        echo " - " . $url . "\n";
      }
      echo "\n\n";
    }
  }

  public function getLogMessagesAsCsv($messages) {

    // Create a variable to hold your CSV data
    $row_array = [
      'source',
      'subsource',
      'http status code',
      'url',
      'message (type, severity, message)',
    ];
    $csvData = '"' . implode('","', $row_array) . "\"\n";

    // Loop through each item in your array
    foreach ($messages as $row) {

      $message_array = [];
      foreach ($row['message'] as $message) {
        $message_array[] = $message['type'] . ' - ' . $message['severity'] . ' - ' . $message['message'];
      }
      $message_string = implode('","',$message_array);

      $row_array = [
        $row['source'],
        $row['subsource'] ?? '',
        $row['code'],
        $row['url'],
        $message_string,
      ];

      // For each item, implode the array values with a comma to create a CSV row
      $csvData .= '"' . implode('","', $row_array) . "\"\n";

    }

    // Output the CSV data
    return $csvData;
  }

  public function getLogMessages($url, $startTimestamp) {
    $query = \Drupal::database()->select('watchdog', 'w');
    $query->fields('w', ['message', 'variables', 'type', 'location', 'severity']);
    $query->condition('w.timestamp', $startTimestamp, '>=');
    $query->condition('w.location', $url["url"], '=');
    $query->condition('w.severity', RfcLogLevel::WARNING, '>=');
    $query->orderBy('w.wid', 'DESC');
    $result = $query->execute()->fetchAll();
    $log_messages = [];
    foreach ($result as $record) {
      // Don't include backtrace or path.
      $message = str_replace('@backtrace_string.', '[backtrace filtered]', $record->message);
      $message = str_replace('@uri', '[uri filtered]', $message);
      $message = str_replace('%file_uri', '[file_uri filtered]', $message);
      $message = str_replace('@uuid', '[uuid filtered]', $message);
      $message = str_replace('@url', '[url filtered]', $message);
      $message = trim($message);
      $message = (string) t($message, unserialize($record->variables));
      $message = strip_tags($message);
      $log_messages[] = [
        'type' => $record->type,
        'severity' => $record->severity,
        'message' => $message,
      ];
    }

    return $log_messages;
  }

}
