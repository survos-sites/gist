<?php

declare(strict_types=1);

namespace App\Tui;

use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\BorderPattern;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class ImportDashboard
{
    private Tui $tui;
    private SelectListWidget $sidebar;
    private LogViewerWidget $logViewer;
    private TextWidget $header;
    private TextWidget $footer;
    private string $focused = '';

    /**
     * @param list<ImportProcess> $processes
     */
    public function __construct(
        private readonly array $processes,
        private readonly string $projectDir,
        private readonly bool $force = false,
    ) {
        $total = count($processes);
        $headwords = array_sum(array_map(fn (ImportProcess $p) => $p->total, $processes));

        $this->tui = new Tui($this->buildStyleSheet());
        $this->sidebar = new SelectListWidget($this->buildSidebarItems(), maxVisible: 40);
        $this->logViewer = new LogViewerWidget();
        $this->logViewer->setFollow(true);
        $this->header = new TextWidget(
            sprintf('FreeDict Import  %d pairs · %s headwords total', $total, number_format($headwords))
        );
        $this->footer = new TextWidget($this->footerText());
    }

    public function run(): int
    {
        $body = new ContainerWidget();
        $body->addStyleClass('body');
        $body->add($this->sidebar);
        $body->add($this->logViewer);

        $this->tui->add($this->header);
        $this->tui->add($body);
        $this->tui->add($this->footer);
        $this->tui->setFocus($this->sidebar);

        $this->wireSidebar();
        $this->wireGlobalKeys();
        $this->wireTickLoop();

        $this->startNext();

        if (!empty($this->processes)) {
            $this->focusPair($this->processes[0]->pair);
        }

        $this->tui->run();

        return 0;
    }

    private function wireSidebar(): void
    {
        $this->sidebar->onSelectionChange(function (SelectionChangeEvent $event): void {
            $this->focusPair($event->getValue());
        });
    }

    private function wireGlobalKeys(): void
    {
        $this->tui->addListener(function (InputEvent $event): void {
            $key = $event->getData();
            $consumed = match (true) {
                $key === 'q', $key === Key::ctrl('c') => $this->doQuit(),
                $key === "\t" => $this->cycleFocus(),
                default => false,
            };
            if ($consumed) {
                $event->stopPropagation();
            }
        });
    }

    private function wireTickLoop(): void
    {
        $this->tui->onTick(function (TickEvent $event): void {
            $changed = $this->driveTick();
            if ($changed) {
                $this->refreshSidebar();
            }
            $this->refreshFooter();
            if ($this->hasPendingOrRunning()) {
                $event->setBusy();
            }
        });
    }

    private function driveTick(): bool
    {
        $changed = false;
        foreach ($this->processes as $proc) {
            if (!$proc->isRunning()) {
                continue;
            }
            $newLines = $proc->tick();
            if ($newLines) {
                $changed = true;
                if ($proc->pair === $this->focused) {
                    $this->logViewer->appendLines($newLines);
                }
            }
            if (!$proc->isRunning()) {
                $changed = true;
                $this->startNext();
            }
        }

        return $changed;
    }

    private function startNext(): void
    {
        foreach ($this->processes as $proc) {
            if ($proc->isPending()) {
                $proc->start($this->projectDir, $this->force);

                return;
            }
        }
    }

    private function focusPair(string $pair): void
    {
        if ('' === $pair || $pair === $this->focused) {
            return;
        }
        $proc = $this->findProcess($pair);
        if (null === $proc) {
            return;
        }
        $this->focused = $pair;
        $this->logViewer->setLines($proc->lines());
        $proc->markRead();
        $this->refreshSidebar();
        $this->refreshFooter();
    }

    private function findProcess(string $pair): ?ImportProcess
    {
        foreach ($this->processes as $proc) {
            if ($proc->pair === $pair) {
                return $proc;
            }
        }

        return null;
    }

    private function hasPendingOrRunning(): bool
    {
        foreach ($this->processes as $proc) {
            if ($proc->isPending() || $proc->isRunning()) {
                return true;
            }
        }

        return false;
    }

    private function refreshSidebar(): void
    {
        $items = $this->buildSidebarItems();
        $current = $this->sidebar->getSelectedItem()['value'] ?? null;
        $this->sidebar->setItems($items);
        if (null !== $current) {
            foreach ($items as $i => $item) {
                if ($item['value'] === $current) {
                    $this->sidebar->setSelectedIndex($i);
                    break;
                }
            }
        }
    }

    private function refreshFooter(): void
    {
        $text = $this->footerText();
        if ($this->footer->getText() !== $text) {
            $this->footer->setText($text);
        }
    }

    /** @return list<array{value: string, label: string}> */
    private function buildSidebarItems(): array
    {
        $items = [];
        foreach ($this->processes as $proc) {
            $items[] = [
                'value' => $proc->pair,
                'label' => $this->labelFor($proc),
            ];
        }

        return $items;
    }

    private function labelFor(ImportProcess $proc): string
    {
        $name = $proc->pair;
        $unread = $proc->unreadCount();
        $unreadSuffix = ($unread > 0 && $name !== $this->focused) ? sprintf(' +%d', $unread) : '';

        return match ($proc->status()) {
            'pending' => sprintf('○ %-12s %s', $name, number_format($proc->total)),
            'running' => $proc->total > 0
                ? sprintf('● %-12s %s/%s (%d%%)%s',
                    $name,
                    number_format($proc->count()),
                    number_format($proc->total),
                    (int) ($proc->count() / $proc->total * 100),
                    $unreadSuffix,
                )
                : sprintf('● %-12s %s%s', $name, number_format($proc->count()), $unreadSuffix),
            'done' => sprintf('✓ %-12s %s', $name, number_format($proc->total)),
            'failed' => sprintf('✗ %-12s failed', $name),
            default => $name,
        };
    }

    private function footerText(): string
    {
        $done = count(array_filter($this->processes, fn (ImportProcess $p) => $p->isDone()));
        $failed = count(array_filter($this->processes, fn (ImportProcess $p) => $p->isFailed()));
        $total = count($this->processes);
        $follow = $this->logViewer->isFollowing() ? 'ON' : 'OFF';
        $wrap = $this->logViewer->isWrapping() ? 'ON' : 'OFF';

        return sprintf(
            '↑↓ select · Tab focus · q quit · f follow:%s · w wrap:%s  [%d/%d done%s]',
            $follow,
            $wrap,
            $done,
            $total,
            $failed > 0 ? sprintf(', %d failed', $failed) : '',
        );
    }

    private function doQuit(): bool
    {
        foreach ($this->processes as $proc) {
            if ($proc->isRunning()) {
                $proc->stop();
            }
        }
        $this->tui->stop();

        return true;
    }

    private function cycleFocus(): bool
    {
        $current = $this->tui->getFocus();
        $this->tui->setFocus($current === $this->sidebar ? $this->logViewer : $this->sidebar);

        return true;
    }

    private function buildStyleSheet(): StyleSheet
    {
        return new StyleSheet([
            ':root' => new Style(direction: Direction::Vertical),
            '.body' => new Style(direction: Direction::Horizontal, gap: 1),
            SelectListWidget::class => new Style(
                maxColumns: 42,
                border: Border::from([1], BorderPattern::ROUNDED, 'gray'),
            ),
            SelectListWidget::class.':focus' => new Style(
                border: Border::from([1], BorderPattern::ROUNDED, 'cyan'),
            ),
            LogViewerWidget::class => new Style(
                flex: 1,
                border: Border::from([1], BorderPattern::ROUNDED, 'gray'),
            ),
            LogViewerWidget::class.':focus' => new Style(
                border: Border::from([1], BorderPattern::ROUNDED, '#10b981'),
            ),
            TextWidget::class => new Style(dim: true),
        ]);
    }
}
