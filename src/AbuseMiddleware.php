<?php

namespace Drupal\abuse_ip_db;

use Drupal\Component\Render\FormattableMarkup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Abuse Middleware service.
 */
class AbuseMiddleware implements HttpKernelInterface {

  /**
   * The decorated kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The abuse_ip_db.manager service.
   *
   * @var \Drupal\abuse_ip_db\AbuseManager
   */
  protected $abuseManager;

  /**
   * Constructs an AbuseMiddleware object.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   * @param \Drupal\abuse_ip_db\AbuseManager $abuse_manager
   *   The abuse_ip_db.manager service.
   */
  public function __construct(HttpKernelInterface $http_kernel, AbuseManager $abuse_manager) {
    $this->httpKernel = $http_kernel;
    $this->abuseManager = $abuse_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = TRUE): Response {
    $ip = $request->getClientIp();
    if ($this->abuseManager->isBanned($ip)) {
      return new Response(new FormattableMarkup('@ip has been banned due to a bad abuse score', ['@ip' => $ip]), 403);
    }
    return $this->httpKernel->handle($request, $type, $catch);
  }

}
