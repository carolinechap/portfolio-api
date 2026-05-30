<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Per-day Gemini API call counter backed by a Symfony cache pool.
 *
 * Counts API calls against a daily limit and exposes helpers to check, bump
 * and force-exhaust the quota. The "day" key is derived from the injected
 * $now, which makes the service trivially testable with a frozen clock.
 */
final class QuotaGuard
{
    /**
     * @param \DateTimeImmutable $now Clock used to derive the daily cache key (defaults to "now")
     */
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        #[Autowire(value: '%env(int:GEMINI_DAILY_LIMIT)%')]
        private int $limit,
        private \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }

    /**
     * Returns true while the daily counter is strictly below the configured limit.
     */
    public function canCall(): bool
    {
        return $this->current() < $this->limit;
    }

    /**
     * Increments the daily counter by one.
     *
     * @return int New counter value
     */
    public function recordCall(): int
    {
        return $this->bump(1);
    }

    /**
     * Marks the quota as exhausted for the rest of the day by jumping the counter past the limit.
     */
    public function exhaust(): void
    {
        $this->bump($this->limit);
    }

    /**
     * Returns the current value of the daily counter, initializing it to zero on first access.
     */
    private function current(): int
    {
        return (int) $this->cache->get($this->key(), static fn (ItemInterface $i): int => self::initItem($i));
    }

    /**
     * Increases the daily counter by $by and returns the new value.
     */
    private function bump(int $by): int
    {
        $key = $this->key();
        $value = $this->current() + $by;
        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $item) use ($value): int {
            self::initItem($item);

            return $value;
        });

        return $value;
    }

    /**
     * Configures a freshly-created cache item with a 26h TTL and returns its initial value (zero).
     */
    private static function initItem(ItemInterface $item): int
    {
        $item->expiresAfter(new \DateInterval('PT26H'));

        return 0;
    }

    /**
     * Builds the cache key for the current day, e.g. "gemini_quota.2025-12-31".
     *
     * Uses "." rather than ":" as the separator: ":" is a PSR-6 reserved
     * character ({}()/\@:) and validating pools (e.g. the real cache.app
     * FilesystemAdapter) reject it.
     */
    private function key(): string
    {
        return 'gemini_quota.' . $this->now->format('Y-m-d');
    }
}
