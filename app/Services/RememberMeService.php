<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class RememberMeService
{
    private const COOKIE_NAME = 'kijo_remember';

    private const LIFETIME_DAYS = 30;

    private const REQUEST_SELECTOR_ATTRIBUTE = 'auth.remember_selector';

    private const REQUEST_USER_ATTRIBUTE = 'auth.remember_user_id';

    public function issue(Response $response, int $userId): Response
    {
        if (! Schema::hasTable('auth_remember_tokens')) {
            return $response;
        }

        $selector = Str::random(32);
        $token = Str::random(64);
        $now = now();

        DB::table('auth_remember_tokens')->insert([
            'selector' => $selector,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response->headers->setCookie($this->cookie($selector.':'.$token));

        return $response;
    }

    public function clear(Response $response, Request $request): Response
    {
        $selector = $this->selectorFromRequest($request);
        if ($selector !== null && Schema::hasTable('auth_remember_tokens')) {
            DB::table('auth_remember_tokens')->where('selector', $selector)->delete();
        }

        $response->headers->clearCookie(
            self::COOKIE_NAME,
            (string) config('session.path', '/'),
            config('session.domain'),
        );

        return $response;
    }

    public function resolveUser(Request $request): ?array
    {
        if (! Schema::hasTable('auth_remember_tokens') || ! Schema::hasTable('system_users')) {
            return null;
        }

        $parts = $this->cookieParts($request);
        if ($parts === null) {
            return null;
        }

        [$selector, $token] = $parts;
        $request->attributes->set(self::REQUEST_SELECTOR_ATTRIBUTE, $selector);

        $remember = DB::table('auth_remember_tokens')->where('selector', $selector)->first();
        if (! $remember) {
            return null;
        }

        if (Carbon::parse($remember->expires_at)->isPast()) {
            DB::table('auth_remember_tokens')->where('selector', $selector)->delete();

            return null;
        }

        if (! hash_equals((string) $remember->token_hash, hash('sha256', $token))) {
            DB::table('auth_remember_tokens')->where('selector', $selector)->delete();

            return null;
        }

        DB::table('auth_remember_tokens')
            ->where('selector', $selector)
            ->update(['last_used_at' => now(), 'updated_at' => now()]);

        $request->attributes->set(self::REQUEST_USER_ATTRIBUTE, (int) $remember->user_id);

        return ['id' => (int) $remember->user_id];
    }

    public function rotate(Response $response, Request $request): Response
    {
        $selector = $request->attributes->get(self::REQUEST_SELECTOR_ATTRIBUTE);
        $userId = (int) $request->attributes->get(self::REQUEST_USER_ATTRIBUTE, 0);

        if (! is_string($selector) || $selector === '' || $userId <= 0 || ! Schema::hasTable('auth_remember_tokens')) {
            return $response;
        }

        $nextSelector = Str::random(32);
        $nextToken = Str::random(64);
        $now = now();

        $updated = DB::table('auth_remember_tokens')
            ->where('selector', $selector)
            ->where('user_id', $userId)
            ->update([
                'selector' => $nextSelector,
                'token_hash' => hash('sha256', $nextToken),
                'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
                'last_used_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            $response->headers->setCookie($this->cookie($nextSelector.':'.$nextToken));
        }

        return $response;
    }

    private function cookie(string $value): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            $value,
            now()->addDays(self::LIFETIME_DAYS),
            (string) config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            true,
            false,
            (string) config('session.same_site', 'lax'),
        );
    }

    private function selectorFromRequest(Request $request): ?string
    {
        $parts = $this->cookieParts($request);

        return $parts[0] ?? null;
    }

    private function cookieParts(Request $request): ?array
    {
        $value = (string) $request->cookies->get(self::COOKIE_NAME, '');
        $parts = explode(':', $value, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return $parts;
    }
}
