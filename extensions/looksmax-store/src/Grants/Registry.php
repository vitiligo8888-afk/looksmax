<?php

namespace Local\Store\Grants;

use Illuminate\Contracts\Container\Container;

/**
 * kind -> handler, resolved lazily out of the container.
 *
 * The map is declared rather than discovered by instantiating every handler
 * and asking it: BundleGrant takes this registry as a dependency, so building
 * handlers eagerly here would recurse through the container and never return.
 * A new item kind is one class plus one line.
 */
class Registry
{
    private const MAP = [
        'style' => CosmeticGrant::class,
        'frame' => CosmeticGrant::class,
        'tier' => TierGrant::class,
        'boost' => BenefitGrant::class,
        'streakfreeze' => BenefitGrant::class,
        'highlight' => BenefitGrant::class,
        'sticky' => BenefitGrant::class,
        'bump' => BenefitGrant::class,
        'rename' => BenefitGrant::class,
        'credits' => CreditsGrant::class,
        'oro' => OroGrant::class,
        'mystery' => MysteryGrant::class,
        'bundle' => BundleGrant::class,
    ];

    /** @var array<string,Grant> */
    private array $built = [];

    public function __construct(protected Container $container)
    {
    }

    public function for(string $kind): Grant
    {
        if (!isset(self::MAP[$kind])) {
            throw new \RuntimeException('nothing knows how to grant a ' . $kind);
        }

        return $this->built[$kind] ??= $this->container->make(self::MAP[$kind]);
    }

    public function knows(string $kind): bool
    {
        return isset(self::MAP[$kind]);
    }

    public function kinds(): array
    {
        return array_keys(self::MAP);
    }
}
