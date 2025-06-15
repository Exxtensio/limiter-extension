<?php

namespace Exxtensio\LimiterExtension;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\SimpleCache\InvalidArgumentException;
use Closure;

class LimiterService
{
    protected Cache $cache;
    protected ?Carbon $expiredAt = null;
    protected string $userId;
    protected string $month;
    protected string $minute;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @param $id
     * @param Carbon|null $expiredAt
     * @return $this
     */
    public function for($id, ?Carbon $expiredAt = null): LimiterService
    {
        $this->userId = $id;
        $this->month = "$this->userId:limits:month";
        $this->minute = "$this->userId:limits:minute";
        $this->expiredAt = $expiredAt;

        return $this;
    }

    public function create($minuteLimit, $monthLimit): LimiterService
    {
        $this->put($this->minute, $minuteLimit, now()->addMinute());
        $this->put($this->month, $monthLimit, $this->expiredAt);
        $this->cache->put("$this->month:expiredAt", $this->expiredAt);

        return $this;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function update($minuteLimit, $monthLimit): LimiterService
    {
        $this->cache->put($this->minute, $minuteLimit, now()->addMinute());
        $this->cache->put("$this->minute:remaining", $minuteLimit, now()->addMinute());

        $used = $this->cache->get("$this->month:used");

        $this->cache->put($this->month, $monthLimit, $this->expiredAt);
        $this->cache->put("$this->month:remaining", $monthLimit-$used, $this->expiredAt);

        return $this;
    }

    public function reset($minuteLimit, $monthLimit): void
    {
        $this->put($this->minute, $minuteLimit, now()->addMinute());
        $this->put($this->month, $monthLimit, $this->expiredAt);
        $this->cache->put("$this->month:expiredAt", $this->expiredAt);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function hit($minuteLimit, $monthLimit, Closure $closure)
    {
        $minuteRemaining = $this->remaining('minute', $minuteLimit);
        $monthRemaining = $this->remaining('month', $monthLimit);

        if ($monthRemaining <= 0)
            return $closure('Rate limit exceeded. Too many requests this month.', $minuteRemaining, $monthRemaining);

        if ($minuteRemaining <= 0)
            return $closure('Rate limit exceeded. Too many requests per minute.', $minuteRemaining, $monthRemaining);

        $this->cache->increment("$this->minute:used");
        $this->cache->decrement("$this->minute:remaining");

        $this->cache->increment("$this->month:used");
        $this->cache->decrement("$this->month:remaining");

        return true;
    }

    private function put($key, $limit, Carbon $ttl): void
    {
        $this->cache->put($key, $limit);
        $this->cache->put("$key:used", 0, $ttl);
        $this->cache->put("$key:remaining", $limit, $ttl);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function remaining($key, $limit)
    {
        $resolvedKey = $key === 'minute' ? $this->minute : $this->month;
        $ttl = $key === 'minute' ? now()->addMinute() : now()->addDays(30);
        if(
            !$this->cache->has($resolvedKey) ||
            !$this->cache->has("$resolvedKey:used") ||
            !$this->cache->has("$resolvedKey:remaining")
        ) $this->put($resolvedKey, $limit, $ttl);

        return $this->cache->get("$resolvedKey:remaining");
    }
}
