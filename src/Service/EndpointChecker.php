<?php

namespace Drupal\rl\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks whether rl.php is accessible via HTTP, cached for 1 hour.
 *
 * The check pings rl.php from the browser's origin. When the HTTP
 * request fails due to networking (e.g. Docker container isolation)
 * but the file exists on disk, the endpoint is assumed accessible.
 */
class EndpointChecker {

  protected const CACHE_TTL = 3600;
  protected const STATE_KEY = 'rl.endpoint_accessible';

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
    protected ModuleExtensionList $moduleList,
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Returns TRUE when rl.php is believed accessible by the web server.
   */
  public function isAccessible(): bool {
    $cached = $this->state->get(static::STATE_KEY);
    if (is_array($cached) && isset($cached['checked']) && ($this->time->getRequestTime() - $cached['checked']) < static::CACHE_TTL) {
      return !empty($cached['accessible']);
    }
    $result = $this->check();
    $this->state->set(static::STATE_KEY, [
      'accessible' => $result,
      'checked' => $this->time->getRequestTime(),
    ]);
    return $result;
  }

  /**
   * Clears the cached result so the next call re-checks.
   */
  public function resetCache(): void {
    $this->state->delete(static::STATE_KEY);
  }

  /**
   * Performs the actual rl.php accessibility check.
   */
  protected function check(): bool {
    $rl_path = $this->moduleList->getPath('rl');
    $file_path = DRUPAL_ROOT . '/' . $rl_path . '/rl.php';

    if (!file_exists($file_path)) {
      return FALSE;
    }

    $request = $this->requestStack->getCurrentRequest();
    $base_url = $request ? $request->getSchemeAndHttpHost() : '';
    $base_path = $request ? $request->getBasePath() : '';
    $url = $base_url . $base_path . '/' . $rl_path . '/rl.php';

    try {
      $response = $this->httpClient->post($url, [
        'form_params' => ['action' => 'ping'],
        'timeout' => 3,
        'http_errors' => FALSE,
      ]);
      return $response->getStatusCode() === 200;
    }
    catch (\Exception $e) {
      // Connection failed (cURL error 6/7 in Docker, firewalls, etc.).
      // The file exists on disk, so assume the web server serves it.
      return TRUE;
    }
  }

}
