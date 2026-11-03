<?php

namespace Tests\Feature\Home;

/**
 * The most queries one home page may run, whatever the size of the
 * catalog, the directory, the announcements, the events and each block:
 * every block costs a fixed number (App\View\Home\HomePageBuilder). Shared
 * by every test that counts the home's queries.
 */
final class HomeQueryBudget
{
    /** An ordinary account: no management figures, no pending items, no products. */
    public const MAX = 17;

    /**
     * A «Gerencia» account with every block on the page: the management
     * panel (+2), pending items with their integrations and apps (+2) and
     * the products of the catalog (+1).
     */
    public const WITH_EVERY_BLOCK = 22;
}
