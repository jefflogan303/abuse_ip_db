<?php

namespace Drupal\abuse_ip_db;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;

/**
 * Service description.
 */
class AbuseManager {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs an AbuseManager object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $connection, TimeInterface $time, ClientInterface $http_client, ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->connection = $connection;
    $this->time = $time;
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * Check if IP address has a score over 50.
   */
  public function isBanned($ip) {

    if (empty($this->configFactory->get('abuse_ip_db.settings')->get('api_key'))) {
      return FALSE;
    }

    // First check database.
    $score = $this->checkDatabaseIpScore($ip);

    // Check api if no database result and store.
    if ($score === FALSE) {
      $score = $this->checkApiIpScore($ip);
      if ($score === FALSE) {
        return FALSE;
      }
    }

    if ($score > 50) {
      return TRUE;
    }

    return FALSE;

  }

  /**
   * Lookup score from database.
   *
   * @param mixed $ip
   *
   * @return string|false
   *   The score or FALSE.
   */
  public function checkDatabaseIpScore($ip) {

    $score = $this->connection->query("SELECT score FROM {abuse_ip_db} WHERE ip = :ip AND timestamp > :timestamp ORDER BY timestamp DESC LIMIT 0,1", [
      ':ip' => $ip,
      ':timestamp' => $this->getExpiryTimestamp(),
    ])->fetchField();
    return $score;

  }

  /**
   * Check API for score and store result.
   * @param mixed $ip
   *   The IP address.
   *
   * @return string|false
   *   The score or FALSE.
   */
  public function checkApiIpScore($ip) {

    try {

      $request = $this->httpClient->request('GET', 'https://api.abuseipdb.com/api/v2/check', [
        'query' => [
          'ipAddress' => $ip,
        ],
        'headers' => [
          'Accept' => 'application/json',
          'Key' => $this->configFactory->get('abuse_ip_db.settings')->get('api_key'),
        ]
      ]);
      $json = json_decode($request->getBody(), true);

      // If response isn't empty, store in database.
      if (!empty($json) &&
        isset($json['data']) &&
        isset($json['data']['abuseConfidenceScore'])) {

        $this->connection->insert('abuse_ip_db')
          ->fields([
            'ip' => $ip,
            'score' => $json['data']['abuseConfidenceScore'] ?? 0,
            'timestamp' => $this->time->getRequestTime(),
          ])
          ->execute();
        return $json['data']['abuseConfidenceScore'] ?? 0;
      }
    }
    catch (ClientException $ex) {
      $response = $ex->getResponse();
      $jsonStr = $response->getBody()->getContents();
      $jsonArr = json_decode($jsonStr, TRUE);
      $this->logger->error('client error @error', [
        '@error' => print_r($jsonArr, TRUE),
      ]);
    }
    catch (ServerException $ex) {
      $response = $ex->getResponse();
      $responseBody = $response->getBody()->getContents();
      $this->logger->error('server error @error', [
        '@error' => $responseBody,
      ]);
    }
    catch (Exception $ex) {
      $this->logger->error('Unspecified Error: @error', [
        '@error' => print_r($ex, TRUE),
      ]);
    }

    return FALSE;

  }

  /**
   * Delete expired cached ip addresses.
   */
  public function deleteExpired() {
    $this->connection->delete('abuse_ip_db')
      ->condition('timestamp', $this->getExpiryTimestamp(), '<')
      ->execute();
  }

  /**
   * Helper function.
   *
   * @todo perhaps make this configurable.
   *
   * @return int
   *   The timestamp.
   */
  private function getExpiryTimestamp() {
    $expiry_timestamp = $this->time->getRequestTime() - (24 * 60 * 60);
    return $expiry_timestamp;
  }

}
