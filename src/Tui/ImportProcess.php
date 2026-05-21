<?php

declare(strict_types=1);

namespace App\Tui;

use Symfony\Component\Process\Process;

final class ImportProcess
{
    private ?Process $process = null;

    /** @var list<string> */
    private array $lines = [];
    private int $unread = 0;
    private string $status = 'pending'; // pending | running | done | failed
    private int $count = 0;

    public function __construct(
        public readonly string $pair,
        public readonly int $total,
    ) {}

    public function status(): string
    {
        return $this->status;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function unreadCount(): int
    {
        return $this->unread;
    }

    public function markRead(): void
    {
        $this->unread = 0;
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isRunning(): bool
    {
        return 'running' === $this->status;
    }

    public function isDone(): bool
    {
        return 'done' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }

    public function start(string $projectDir, bool $force): void
    {
        $cmd = ['php', 'bin/console', 'app:tei:import', $this->pair];
        if ($force) {
            $cmd[] = '--force';
        }
        $this->process = new Process($cmd, $projectDir, timeout: 7200);
        $this->process->start();
        $this->status = 'running';
    }

    public function stop(): void
    {
        $this->process?->stop(3);
    }

    /**
     * Drain new subprocess output. Returns new lines (empty if nothing changed).
     *
     * Also transitions status to done/failed when the process exits.
     *
     * @return list<string>
     */
    public function tick(): array
    {
        if (null === $this->process || 'running' !== $this->status) {
            return [];
        }

        $newLines = [];
        $raw = $this->process->getIncrementalOutput()
            . $this->process->getIncrementalErrorOutput();

        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line);
            if ('' === $line) {
                continue;
            }
            $this->lines[] = $line;
            ++$this->unread;
            $newLines[] = $line;

            // parse "  [fra-eng] 3000 of 8505 entries (35%)"
            if (preg_match('/\[[\w-]+\]\s+(\d+) of \d+ entries/', $line, $m)) {
                $this->count = (int) $m[1];
            }
        }

        if (!$this->process->isRunning()) {
            $this->status = 0 === $this->process->getExitCode() ? 'done' : 'failed';
            $marker = 'done' === $this->status
                ? "── done ({$this->count} entries imported) ──"
                : "── FAILED (exit {$this->process->getExitCode()}) ──";
            $this->lines[] = $marker;
            $newLines[] = $marker;
        }

        return $newLines;
    }
}
