<?php

declare(strict_types=1);

namespace App\Application\Extension;

use Throwable;

/** Lightweight action/filter registry shared by trusted extension providers. */
final class HookRegistry
{
    /** @var array<string,array<int,array{priority:int,sequence:int,callback:callable}>> */
    private array $actions = [];
    /** @var array<string,array<int,array{priority:int,sequence:int,callback:callable}>> */
    private array $filters = [];
    private int $sequence = 0;

    public function addAction(string $name, callable $callback, int $priority = 10): void
    {
        $this->register($this->actions, $name, $callback, $priority);
    }

    public function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        $this->register($this->filters, $name, $callback, $priority);
    }

    public function doAction(string $name, mixed ...$arguments): void
    {
        foreach ($this->ordered($this->actions[$name] ?? []) as $listener) {
            try { ($listener['callback'])(...$arguments); }
            catch (Throwable $error) { $this->logFailure($name, $error); }
        }
    }

    public function applyFilters(string $name, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->ordered($this->filters[$name] ?? []) as $listener) {
            try { $value = ($listener['callback'])($value, ...$arguments); }
            catch (Throwable $error) { $this->logFailure($name, $error); }
        }
        return $value;
    }

    /** @param array<string,array<int,array{priority:int,sequence:int,callback:callable}>> $registry */
    private function register(array &$registry, string $name, callable $callback, int $priority): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $name)) throw new \InvalidArgumentException('Tên extension hook không hợp lệ.');
        $registry[$name][] = ['priority'=>$priority, 'sequence'=>$this->sequence++, 'callback'=>$callback];
    }

    /** @param array<int,array{priority:int,sequence:int,callback:callable}> $listeners
     *  @return array<int,array{priority:int,sequence:int,callback:callable}>
     */
    private function ordered(array $listeners): array
    {
        usort($listeners, static fn(array $a, array $b): int => [$a['priority'],$a['sequence']] <=> [$b['priority'],$b['sequence']]);
        return $listeners;
    }

    private function logFailure(string $name, Throwable $error): void
    {
        error_log(sprintf('Extension hook "%s" listener failed (%s).', $name, $error::class));
    }
}
