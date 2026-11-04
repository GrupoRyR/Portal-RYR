<?php

namespace App\Assistant;

use Carbon\CarbonImmutable;

/**
 * The limits of one question, checked before every model call
 * (App\Assistant\Assistant): an overall deadline (`assistant.deadline_seconds`,
 * so retries, tool rounds, the secondary and the fallback together never
 * run longer) and the month's budget (what was spent before the question
 * plus what this question has cost so far).
 */
final readonly class QuestionGuard
{
    public function __construct(
        public CarbonImmutable $deadline,
        public float $budget,
        public float $spentBefore,
    ) {}

    public function expired(): bool
    {
        return $this->secondsLeft() <= 0;
    }

    /**
     * Whole seconds left before the deadline (0 when it passed).
     */
    public function secondsLeft(): int
    {
        return max(0, (int) floor(CarbonImmutable::now()->diffInSeconds($this->deadline, false)));
    }

    public function budgetReached(Usage $usage): bool
    {
        return $this->spentBefore + $usage->costUsd >= $this->budget;
    }
}
