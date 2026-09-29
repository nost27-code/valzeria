<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;
use Illuminate\Session\SessionManager;

class StartSession extends BaseStartSession
{
    public function __construct(SessionManager $manager, CacheFactory $cache)
    {
        parent::__construct($manager, fn () => $cache);
    }

    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! $request->routeIs('top.analytics.event')) {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        // CSRF照合・利用者の識別には読むが、遅い解析通信で認証情報を上書きしない。
        $request->setLaravelSession($this->startSession($request, $session));
        $response = $next($request);

        // CSRF middlewareが付けた古いCookieも、ログイン完了後に届く可能性がある。
        foreach ($response->headers->getCookies() as $cookie) {
            if (in_array($cookie->getName(), [$session->getName(), 'XSRF-TOKEN'], true)) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
