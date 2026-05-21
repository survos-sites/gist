<?php

declare(strict_types=1);

namespace App\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

final class LogViewerWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    /** @var list<string> */
    private array $lines = [];
    private int $scrollOffset = 0;
    private bool $follow = true;
    private bool $wrap = false;
    private bool $verticallyExpanded = true;
    private int $lastViewportRows = 20;

    public function __construct(?Keybindings $keybindings = null)
    {
        if (null !== $keybindings) {
            $this->setKeybindings($keybindings);
        }
    }

    public function isFollowing(): bool
    {
        return $this->follow;
    }

    public function isWrapping(): bool
    {
        return $this->wrap;
    }

    public function setFollow(bool $follow): void
    {
        if ($this->follow === $follow) {
            return;
        }
        $this->follow = $follow;
        if ($follow) {
            $this->scrollOffset = 0;
        }
        $this->invalidate();
    }

    /** @param list<string> $lines */
    public function setLines(array $lines): void
    {
        $this->lines = $lines;
        $this->scrollOffset = 0;
        $this->invalidate();
    }

    /** @param list<string> $newLines */
    public function appendLines(array $newLines): void
    {
        if (!$newLines) {
            return;
        }
        $this->lines = array_merge($this->lines, $newLines);
        if (!$this->follow) {
            $this->scrollOffset += \count($newLines);
            $this->clampScroll();
        }
        $this->invalidate();
    }

    public function clearLines(): void
    {
        $this->lines = [];
        $this->scrollOffset = 0;
        $this->invalidate();
    }

    public function expandVertically(bool $fill): static
    {
        if ($this->verticallyExpanded !== $fill) {
            $this->verticallyExpanded = $fill;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->verticallyExpanded;
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        $page = max(1, $this->lastViewportRows - 1);

        if ($kb->matches($data, 'scroll_up')) {
            $this->scrollBy(1);

            return;
        }
        if ($kb->matches($data, 'scroll_down')) {
            $this->scrollBy(-1);

            return;
        }
        if ($kb->matches($data, 'page_up')) {
            $this->scrollBy($page);

            return;
        }
        if ($kb->matches($data, 'page_down')) {
            $this->scrollBy(-$page);

            return;
        }
        if ($kb->matches($data, 'top')) {
            $this->scrollOffset = max(0, \count($this->lines) - 1);
            $this->setFollow(false);
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'tail')) {
            $this->scrollOffset = 0;
            $this->setFollow(true);
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'toggle_follow')) {
            $this->setFollow(!$this->follow);

            return;
        }
        if ($kb->matches($data, 'toggle_wrap')) {
            $this->wrap = !$this->wrap;
            $this->invalidate();

            return;
        }
    }

    public function render(RenderContext $context): array
    {
        $cols = $context->getColumns();
        $rows = max(1, $context->getRows());
        $this->lastViewportRows = $rows;

        $out = [];
        if (!$this->lines) {
            $out[] = AnsiUtils::truncateToWidth(' (no output yet)', $cols, '');
        } elseif ($this->wrap) {
            $count = \count($this->lines);
            $end = max(1, min($count, $count - $this->scrollOffset));
            $idx = $end - 1;
            while ($idx >= 0 && \count($out) < $rows) {
                $wrapped = $this->wrapLine($this->lines[$idx], $cols);
                array_splice($out, 0, 0, $wrapped);
                --$idx;
            }
            if (\count($out) > $rows) {
                $out = \array_slice($out, \count($out) - $rows);
            }
        } else {
            $count = \count($this->lines);
            $start = max(0, $count - $rows - $this->scrollOffset);
            $end = min($count, $start + $rows);
            foreach (\array_slice($this->lines, $start, $end - $start) as $line) {
                $out[] = AnsiUtils::truncateToWidth($line, $cols, '');
            }
        }

        if ($this->verticallyExpanded) {
            while (\count($out) < $rows) {
                $out[] = '';
            }
        }

        return $out;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'scroll_up' => [Key::UP, 'k'],
            'scroll_down' => [Key::DOWN, 'j'],
            'page_up' => [Key::PAGE_UP, 'ctrl+b'],
            'page_down' => [Key::PAGE_DOWN, 'ctrl+f'],
            'top' => [Key::HOME, 'g'],
            'tail' => [Key::END, 'G'],
            'toggle_follow' => ['f'],
            'toggle_wrap' => ['w'],
        ];
    }

    /** @return list<string> */
    private function wrapLine(string $line, int $cols): array
    {
        $width = AnsiUtils::visibleWidth($line);
        if ($width <= $cols || $cols <= 0) {
            return [$line];
        }
        $parts = [];
        for ($start = 0; $start < $width; $start += $cols) {
            $parts[] = AnsiUtils::sliceByColumn($line, $start, $cols);
        }

        return $parts;
    }

    private function scrollBy(int $delta): void
    {
        if (0 === $delta) {
            return;
        }
        if ($delta > 0) {
            $this->setFollow(false);
        }
        $this->scrollOffset += $delta;
        $this->clampScroll();
        if (0 === $this->scrollOffset && $delta < 0) {
            $this->setFollow(true);
        }
        $this->invalidate();
    }

    private function clampScroll(): void
    {
        $maxOffset = max(0, \count($this->lines) - 1);
        $this->scrollOffset = max(0, min($maxOffset, $this->scrollOffset));
    }
}
