<?php

declare(strict_types=1);

namespace App\Chat\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class QuotaGuard
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheInterface $cache,
        #[Autowire(value: '%env(int:GEMINI_DAILY_LIMIT)%')]
        private int $limit,
        private \DateTimeImmutable $now = new \DateTimeImmutable(),
    ) {
    }

    public function canCall(): bool
    {
        return $this->current() < $this->limit;
    }

    public function recordCall(): int
    {
        return $this->bump(1);
    }

    public function exhaust(): void
    {
        $this->bump($this->limit);
    }

    private function current(): int
    {
        return (int) $this->cache->get($this->key(), static fn (ItemInterface $i): int => self::initItem($i));
    }

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

    private static function initItem(ItemInterface $item): int
    {
        $item->expiresAfter(new \DateInterval('PT26H'));

        return 0;
    }

    private function key(): string
    {
        return 'gemini_quota:' . $this->now->format('Y-m-d');
    }
}
