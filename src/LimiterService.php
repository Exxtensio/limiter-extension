<?php

namespace Exxtensio\LimiterExtension;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Psr\SimpleCache\InvalidArgumentException;

class LimiterService
{
    protected Cache $cache;
    protected string $userId;
    protected string $month;
    protected string $minute;

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function for($id): LimiterService
    {
        $this->userId = $this->cache->get($id);
        $this->month = "$this->userId:limits:month";
        $this->minute = "$this->userId:limits:minute";

        return $this;
    }

    public function create($minuteLimit, $monthLimit): LimiterService
    {
        $this->put($this->minute, $minuteLimit, now()->addMinute());
        $this->put($this->month, $monthLimit, now()->addDays(30));

        return $this;
    }

    public function reset($minuteLimit, $monthLimit): void
    {
        DB::table('event_aggregation')->where('user_id', $this->userId)->delete();
        DB::table('events')->where('user_id', $this->userId)->delete();

        $this->put($this->minute, $minuteLimit, now()->addMinute());
        $this->put($this->month, $monthLimit, now()->addDays(30));
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
