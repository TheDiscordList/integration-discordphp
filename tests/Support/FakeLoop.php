<?php

declare(strict_types=1);

namespace TheDiscordList\DiscordPHP\Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use SplObjectStorage;

/**
 * A ReactPHP loop on virtual time: timers fire only inside advance().
 */
final class FakeLoop implements LoopInterface
{
    public float $now = 1_700_000_000.0;

    /** @var SplObjectStorage<TimerInterface, float> */
    private SplObjectStorage $timers;

    /** @var list<callable> */
    private array $ticks = [];

    public function __construct()
    {
        $this->timers = new SplObjectStorage;
    }

    public function advance(float $seconds): void
    {
        $until = $this->now + $seconds;

        while (true) {
            $this->runTicks();

            $next = null;
            $at = INF;
            foreach ($this->timers as $timer) {
                if ($this->timers[$timer] < $at) {
                    $at = $this->timers[$timer];
                    $next = $timer;
                }
            }
            if ($next === null || $at > $until) {
                break;
            }

            $this->now = max($this->now, $at);
            if ($next->isPeriodic()) {
                $this->timers[$next] = $at + $next->getInterval();
            } else {
                unset($this->timers[$next]);
            }
            ($next->getCallback())($next);
        }

        $this->now = $until;
        $this->runTicks();
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->timers[$timer] = $this->now + max((float) $interval, 0.0);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->timers[$timer] = $this->now + max((float) $interval, 0.0);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        unset($this->timers[$timer]);
    }

    public function timerCount(): int
    {
        return count($this->timers);
    }

    public function futureTick($listener): void
    {
        $this->ticks[] = $listener;
    }

    public function addReadStream($stream, $listener): void {}

    public function addWriteStream($stream, $listener): void {}

    public function removeReadStream($stream): void {}

    public function removeWriteStream($stream): void {}

    public function addSignal($signal, $listener): void {}

    public function removeSignal($signal, $listener): void {}

    public function run(): void {}

    public function stop(): void {}

    private function runTicks(): void
    {
        while ($tick = array_shift($this->ticks)) {
            $tick();
        }
    }
}
