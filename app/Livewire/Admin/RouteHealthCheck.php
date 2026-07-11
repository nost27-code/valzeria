<?php

namespace App\Livewire\Admin;

use App\Services\Admin\RouteHealthCheckService;
use Livewire\Component;

class RouteHealthCheck extends Component
{
    public array $results = [];
    public ?string $checkedAt = null;

    public function runCheck(RouteHealthCheckService $healthCheck): void
    {
        $this->results = $healthCheck->check();
        $this->checkedAt = now()->format('Y-m-d H:i:s');
    }

    public function render(RouteHealthCheckService $healthCheck)
    {
        return view('livewire.admin.route-health-check', [
            'routeCount' => count($healthCheck->routes()),
            'summary' => collect($this->results)->countBy('state')->all(),
        ])->layout('components.layouts.admin');
    }
}
