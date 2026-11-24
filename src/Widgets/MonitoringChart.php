<?php

namespace PHPinnacle\Ferry\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;

class MonitoringChart extends ChartWidget
{
    protected static bool $isLazy = false;

    #[Locked]
    public string $title;

    #[Locked]
    public string $unit;

    #[Reactive]
    public string $summary;

    /** @var array{labels: list<string>, datasets: list<array{label: string, data: list<float|null>, borderDash?: list<int>, borderColor?: string}>} */
    #[Reactive]
    public array $chartData;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->summary;
    }

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getOptions(): array
    {
        return [
            'spanGaps' => false,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'UTC'], 'ticks' => ['maxTicksLimit' => 4]],
                'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => $this->unit]],
            ],
            'plugins' => ['legend' => ['display' => count($this->chartData['datasets']) > 1]],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
