<?php

declare(strict_types=1);

namespace Drupal\yu_ipas\Http;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\yu_ipas\Rules\Decision;
use Drupal\yu_ipas\Rules\RuleEngine;
use Drupal\yu_ipas\Rules\RuleStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Drupal\Core\Session\SessionConfigurationInterface;

/**
 * Early HTTP middleware that blocks requests according to yu_ipas rules.
 *
 * Registered with priority 300 — higher than http_middleware.page_cache
 * (200) — so a blocked visitor can never be served a cached anonymous page.
 *
 * Safety nets against accidental self-lockout:
 * - paths starting with the rescue prefix are never blocked;
 * - logged-in administrators (uid 1, the "administrator" role or holders of
 *   the "bypass yu_ipas" permission) are never blocked when the
 *   "admins_safe" setting is on. The session is started on demand here
 *   because core bootstraps the user session later than this middleware.
 */
final class YuIpasMiddleware implements HttpKernelInterface {

  private const MIDDLEWARE_PRIORITY = 300;

  private const SKIP_PATHS = ['/install.php', '/update.php', '/robots.txt', '/favicon.ico'];

  private const RESCUE_PATH_PREFIX = '/yu-ipas/rescue';

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly RuleEngine $ruleEngine,
    private readonly RuleStorage $storage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SessionInterface $session,
    private readonly SessionConfigurationInterface $sessionConfiguration,
    private readonly RequestStack $requestStack,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    if ($this->shouldHandle($request)) {
      $response = $this->evaluate($request);
      if ($response instanceof Response) {
        return $response;
      }
    }

    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Returns TRUE when the module is enabled and the request should be checked.
   */
  private function shouldHandle(Request $request): bool {
    $config = $this->configFactory->get('yu_ipas.settings');
    if (!$config->get('enabled')) {
      return FALSE;
    }

    $path = $request->getPathInfo();

    // Never block the emergency rescue endpoint.
    if (str_starts_with($path, self::RESCUE_PATH_PREFIX)) {
      return FALSE;
    }

    foreach (self::SKIP_PATHS as $skip) {
      if ($path === $skip) {
        return FALSE;
      }
    }

    // Never block administrators and permission holders (safety net).
    if ($config->get('admins_safe') && $this->isAdmin($request)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Detects a logged-in admin by starting the session on demand.
   *
   * Core bootstraps the user session later in the middleware chain, so the
   * session must be started here explicitly (only when a session cookie is
   * present, which keeps anonymous page caching intact).
   */
  private function isAdmin(Request $request): bool {
    // hasSession() checks the request for the session cookie using the
    // correct per-site cookie name (Session::getName() would report the
    // default "PHPSESSID" before the session is started).
    if (!$this->sessionConfiguration->hasSession($request)) {
      return FALSE;
    }

    // SessionManager::start() reads options from the current request; the
    // request stack is empty at middleware stage, so push our request.
    $stacked = FALSE;
    if ($this->requestStack->getCurrentRequest() === NULL) {
      $this->requestStack->push($request);
      $stacked = TRUE;
    }

    try {
      $request->setSession($this->session);
      if (!$this->session->isStarted()) {
        $this->session->start();
      }
      $uid = (int) ($this->session->get('uid') ?? 0);
      if ($uid === 0) {
        return FALSE;
      }

      $account = $this->entityTypeManager->getStorage('user')->load($uid);
      if ($account === NULL) {
        return FALSE;
      }

      if ((int) $account->id() === 1) {
        return TRUE;
      }
      if (in_array('administrator', $account->getRoles(), TRUE)) {
        return TRUE;
      }
      if ($account->hasPermission('bypass yu_ipas')) {
        return TRUE;
      }
    }
    catch (\Throwable $e) {
      // Never break the site because of a session bootstrap failure, but
      // record the reason for diagnostics.
      \Drupal::logger('yu_ipas')->warning('Admin session check failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
    finally {
      if ($stacked) {
        $this->requestStack->pop();
      }
    }

    return FALSE;
  }

  /**
   * Evaluates the request; returns a 403 response when blocked.
   */
  private function evaluate(Request $request): ?Response {
    $ip = (string) ($request->getClientIp() ?? '');
    if ($ip === '') {
      return NULL;
    }

    $userAgent = (string) $request->headers->get('User-Agent');
    $decision = $this->ruleEngine->decide($ip, $userAgent);

    $config = $this->configFactory->get('yu_ipas.settings');

    if ($decision->isBlocked()) {
      $this->storage->log($ip, $decision->geo, $userAgent, $decision->isBot, Decision::BLOCK, $decision->ruleId);
      return $this->forbiddenResponse($decision);
    }

    if ($decision->action === Decision::EXCEPTION) {
      $this->storage->log($ip, $decision->geo, $userAgent, $decision->isBot, Decision::EXCEPTION);
      return NULL;
    }

    // Passed visits are logged only when explicitly enabled (sampling) to
    // keep the log table small on high-traffic sites.
    if ($config->get('log_passed')) {
      $this->storage->log($ip, $decision->geo, $userAgent, $decision->isBot, Decision::PASS);
    }
    else {
      $this->logFirstVisitOfDay($ip, $decision, $userAgent);
    }

    return NULL;
  }

  /**
   * Logs at most one "passed" record per IP per day.
   */
  private function logFirstVisitOfDay(string $ip, Decision $decision, string $userAgent): void {
    $day = \gmdate('Ymd');
    $cid = 'yu_ipas:visited:' . md5($ip . ':' . $day);
    if ($this->cache->get($cid)) {
      return;
    }
    $this->cache->set($cid, TRUE, \strtotime('tomorrow'));
    $this->storage->log($ip, $decision->geo, $userAgent, $decision->isBot, Decision::PASS);
  }

  /**
   * Builds the 403 response shown to blocked visitors.
   */
  private function forbiddenResponse(Decision $decision): Response {
    $reason = $decision->reason !== '' ? $decision->reason : 'access denied';
    $body = '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
      . '<title>403 Доступ запрещён</title></head>'
      . '<body style="font-family:sans-serif;text-align:center;padding-top:4rem;">'
      . '<h1>403 — Доступ запрещён</h1>'
      . '<p>Ваш запрос заблокирован системой защиты от спама.</p>'
      . '<p style="color:#888;font-size:0.8em;">Reason: ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
      . '</body></html>';

    return new Response($body, Response::HTTP_FORBIDDEN, [
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
      'X-Yu-Ipas' => 'blocked',
    ]);
  }

}
