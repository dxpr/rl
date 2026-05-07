<?php

namespace Drupal\rl\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks whether rl.php is accessible via HTTP.
 *
 * Cached for 1 hour on success, 5 minutes on failure (so a fixed misconfig
 * clears quickly without forcing a manual cache rebuild).
 *
 * Strategy:
 *   1. Probe the public URL Drupal would emit on the current request,
 *      preferring HTTPS when X-Forwarded-Proto signals it (even if
 *      $settings['reverse_proxy'] isn't configured — for a same-site
 *      self-probe this is safe and avoids the most common false negative).
 *   2. If that fails, retry on http://127.0.0.1[:port] with the original
 *      Host header so we can tell "rl.php is broken" from "the proxy /
 *      scheme / DNS chain to the public hostname is broken."
 *   3. Return a structured result so hook_requirements() can give a
 *      pointed description rather than a generic "not accessible."
 */
class EndpointChecker {

  /**
   * Cache TTLs.
   */
  protected const SUCCESS_TTL = 3600;
  protected const FAILURE_TTL = 300;

  /**
   * State key for the cached result.
   */
  protected const STATE_KEY = 'rl.endpoint_accessible';

  /**
   * Result statuses returned by ::check().
   */
  public const STATUS_OK = 'ok';
  public const STATUS_REDIRECTED = 'redirected';
  public const STATUS_HTTP_ERROR = 'http_error';
  public const STATUS_BODY_MISMATCH = 'body_mismatch';
  public const STATUS_CONNECTION_ERROR = 'connection_error';
  public const STATUS_FILE_MISSING = 'file_missing';

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
    protected ModuleExtensionList $moduleList,
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Returns TRUE when rl.php is believed accessible by the web server.
   *
   * Thin wrapper over ::getResult() for callers that only need a boolean.
   */
  public function isAccessible(): bool {
    return $this->getResult()['status'] === self::STATUS_OK;
  }

  /**
   * Returns the cached check result with full diagnostic detail.
   *
   * @return array
   *   An associative array with at least:
   *   - status: one of the STATUS_* constants.
   *   - detail: a human-readable explanation, or NULL on success.
   *   And optionally, depending on status:
   *   - code: HTTP status code of the failed response.
   *   - public_url / loopback_url: URLs probed.
   *   - redirects: redirect chain followed during the probe.
   */
  public function getResult(): array {
    $cached = $this->state->get(self::STATE_KEY);
    if (is_array($cached) && isset($cached['checked'], $cached['result']) && is_array($cached['result'])) {
      $ttl = $cached['result']['status'] === self::STATUS_OK
        ? self::SUCCESS_TTL
        : self::FAILURE_TTL;
      if (($this->time->getRequestTime() - $cached['checked']) < $ttl) {
        return $cached['result'];
      }
    }
    $result = $this->check();
    $this->state->set(self::STATE_KEY, [
      'result' => $result,
      'checked' => $this->time->getRequestTime(),
    ]);
    return $result;
  }

  /**
   * Clears the cached result so the next call re-checks.
   */
  public function resetCache(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Performs the actual rl.php accessibility check.
   */
  protected function check(): array {
    $rl_path = $this->moduleList->getPath('rl');
    $file_path = DRUPAL_ROOT . '/' . $rl_path . '/rl.php';

    if (!file_exists($file_path)) {
      return [
        'status' => self::STATUS_FILE_MISSING,
        'detail' => sprintf('rl.php is missing on disk at %s.', $file_path),
      ];
    }

    $request = $this->requestStack->getCurrentRequest();
    $public_url = $this->buildPublicUrl($request, $rl_path);
    $public_result = $this->probe($public_url);
    $public_result['public_url'] = $public_url;

    if ($public_result['status'] === self::STATUS_OK) {
      return $public_result;
    }

    // Loopback fallback. Only attempt when we have a real Request — in CLI
    // there's nothing to fall back to, and the public probe already returned
    // a deterministic failure.
    if ($request === NULL) {
      return $public_result;
    }
    $loopback_url = $this->buildLoopbackUrl($request, $rl_path);
    $loopback_result = $this->probe($loopback_url, $request->getHost());

    if ($loopback_result['status'] !== self::STATUS_OK) {
      return $public_result;
    }

    // Network-layer failure on public + loopback OK = browser-reachable
    // (Docker / split-horizon DNS only blocks us, not browsers). Upgrade.
    if ($public_result['status'] === self::STATUS_CONNECTION_ERROR) {
      return [
        'status' => self::STATUS_OK,
        'detail' => sprintf(
          'rl.php served on loopback (%s). Public probe failed at network layer: %s. This does not affect browser access.',
          $loopback_url,
          $public_result['detail'] ?? '(no detail)'
        ),
        'public_url' => $public_url,
        'loopback_url' => $loopback_url,
      ];
    }
    return $public_result + [
      'loopback_ok' => TRUE,
      'loopback_url' => $loopback_url,
    ];
  }

  /**
   * Builds the URL Drupal would emit for rl.php on the current request.
   *
   * Self-probe-only exception: trusts X-Forwarded-Proto / -Port without
   * $settings['reverse_proxy'] (safe here, do not copy elsewhere).
   */
  protected function buildPublicUrl(?Request $request, string $rl_path): string {
    if ($request === NULL) {
      return '';
    }
    $forwarded_proto = $request->headers->get('X-Forwarded-Proto');
    if (in_array($forwarded_proto, ['http', 'https'], TRUE)) {
      $scheme = $forwarded_proto;
      $host = $request->getHost();
      $forwarded_port = $request->headers->get('X-Forwarded-Port');
      $port = ($forwarded_port !== NULL && ctype_digit($forwarded_port))
        ? (int) $forwarded_port
        : NULL;
    }
    else {
      $scheme = $request->getScheme();
      $host = $request->getHost();
      $port = $request->getPort();
    }
    $is_default_port = $port === NULL
      || ($scheme === 'http' && $port === 80)
      || ($scheme === 'https' && $port === 443);
    if (!$is_default_port) {
      $host .= ':' . $port;
    }
    return sprintf(
      '%s://%s%s/%s/rl.php',
      $scheme,
      $host,
      $request->getBasePath(),
      $rl_path
    );
  }

  /**
   * Builds an http://127.0.0.1[:port]/... URL for the loopback probe.
   *
   * Uses the local request port since the loopback bypasses any public TLS
   * terminator and lands on the same web server that just served us.
   */
  protected function buildLoopbackUrl(Request $request, string $rl_path): string {
    $port = $request->getPort();
    $port_part = ($port && $port !== 80) ? ':' . $port : '';
    return sprintf(
      'http://127.0.0.1%s%s/%s/rl.php',
      $port_part,
      $request->getBasePath(),
      $rl_path
    );
  }

  /**
   * POSTs action=ping to a URL and classifies the response.
   *
   * Redirects ARE followed (legit setups may HTTP→HTTPS upgrade or do
   * canonical-host redirects), but the chain is tracked so a body-mismatch
   * diagnostic can name the redirect target — usually the smoking gun for
   * scheme or host misconfiguration.
   */
  protected function probe(string $url, ?string $host_header = NULL): array {
    if ($url === '') {
      return [
        'status' => self::STATUS_HTTP_ERROR,
        'detail' => 'No URL available to probe (CLI / no current request).',
      ];
    }
    $options = [
      'form_params' => ['action' => 'ping'],
      'timeout' => 3,
      'http_errors' => FALSE,
      'allow_redirects' => [
        'max' => 5,
        'strict' => TRUE,
        'track_redirects' => TRUE,
      ],
    ];
    if ($host_header !== NULL) {
      $options['headers'] = ['Host' => $host_header];
    }

    try {
      $response = $this->httpClient->post($url, $options);
    }
    catch (\Throwable $e) {
      // Transport-layer failure (DNS / TCP / TLS / timeout); check() will
      // upgrade to OK iff the loopback probe succeeds.
      return [
        'status' => self::STATUS_CONNECTION_ERROR,
        'detail' => $e->getMessage(),
      ];
    }

    $code = $response->getStatusCode();
    $redirect_chain = $response->getHeader('X-Guzzle-Redirect-History');

    if ($code !== 200) {
      $result = [
        'status' => self::STATUS_HTTP_ERROR,
        'detail' => sprintf('rl.php probe returned HTTP %d.', $code),
        'code' => $code,
      ];
      if (!empty($redirect_chain)) {
        $result['redirects'] = $redirect_chain;
      }
      return $result;
    }

    $body = trim((string) $response->getBody());
    if ($body !== 'pong') {
      $result = [
        'status' => self::STATUS_BODY_MISMATCH,
        'detail' => sprintf(
          'rl.php probe returned HTTP 200 but body was %s, not "pong".',
          $body === '' ? '(empty)' : '"' . substr($body, 0, 80) . '"'
        ),
        'code' => $code,
      ];
      if (!empty($redirect_chain)) {
        $result['detail'] .= ' Followed redirect to: ' . end($redirect_chain) . '.';
        $result['redirects'] = $redirect_chain;
      }
      return $result;
    }

    return [
      'status' => self::STATUS_OK,
      'detail' => NULL,
    ];
  }

}
