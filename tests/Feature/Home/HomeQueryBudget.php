<?php

namespace Tests\Feature\Home;

/**
 * The most queries one home page may run, whatever the size of the
 * catalog, the directory, the announcements and the events: each block
 * costs a fixed number (App\View\Home\HomePageBuilder). Shared by every
 * test that counts the home's queries.
 */
final class HomeQueryBudget
{
    public const MAX = 13;
}
