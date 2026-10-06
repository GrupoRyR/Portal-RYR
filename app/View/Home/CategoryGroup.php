<?php

namespace App\View\Home;

/**
 * A category section of the home screen. It can be empty when all its apps
 * are favorites: the page keeps it hidden so an app can return to it.
 */
final readonly class CategoryGroup
{
    /**
     * @param  list<AppCard>  $cards
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $cards,
    ) {}

    public function isEmpty(): bool
    {
        return $this->cards === [];
    }
}
