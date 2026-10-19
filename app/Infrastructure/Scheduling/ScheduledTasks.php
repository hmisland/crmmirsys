<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduling;

use App\Infrastructure\Logging\LoggerInterface;

/**
 * Background work run by ONE cron entry (app/bin/scheduler.php, every
 * minute) instead of one cron line per feature. Core and modules
 * register a task with a name and a closure that does a bounded amount
 * of work within the seconds it is given and returns a one-line summary.
 *
 * A failing task is logged and the others still run. Existing workers
 * with their own cron line (e.g. Marketing's) are unchanged.
 */
final class ScheduledTasks
{
    /** @var array<string, \Closure(int): string> */
    private array $tasks = [];

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    /** @param \Closure(int $budgetSeconds): string $task */
    public function register(string $name, \Closure $task): void
    {
        $this->tasks[$name] = $task;
    }

    /** @return string[] task names, in registration order */
    public function names(): array
    {
        return array_keys($this->tasks);
    }

    /** @return array<string, string> name => summary (or "error") */
    public function runAll(int $budgetSeconds): array
    {
        $deadline = time() + max(5, $budgetSeconds);
        $summaries = [];
        foreach ($this->tasks as $name => $task) {
            $left = $deadline - time();
            if ($left <= 0) {
                $summaries[$name] = 'skipped (no time left)';
                continue;
            }
            try {
                $summaries[$name] = $task($left);
            } catch (\Throwable $e) {
                $this->logger?->error('Scheduled task failed', ['task' => $name, 'exception' => $e->getMessage()]);
                $summaries[$name] = 'error';
            }
        }

        return $summaries;
    }
}
