<?php

namespace BoringO11y\HorizonWorkerStats\Http\Controllers;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;

class WorkerResourcesController
{
    public function __construct(protected WorkerResourcesRepository $resources)
    {
    }

    /**
     * Get the worker memory and CPU history for the retention window.
     *
     * @return array{labels: array<int, int>, memory: array<int, int|null>, cpu: array<int, float|null>, supervisors: array<int, array{name: string, memory: array<int, int|null>, cpu: array<int, float|null>}>}
     */
    public function index()
    {
        return $this->resources->trends();
    }
}
