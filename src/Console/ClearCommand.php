<?php

namespace BoringO11y\HorizonWorkerStats\Console;

use BoringO11y\HorizonWorkerStats\Contracts\WorkerResourcesRepository;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon-worker-stats:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete the recorded worker memory and CPU history';

    /**
     * Execute the console command.
     *
     * Deliberately not gated on the enabled flag: clearing has to be able to
     * clean up what a period with the package on left behind.
     *
     * @return int
     */
    public function handle(WorkerResourcesRepository $resources)
    {
        $resources->clear();

        $this->components->info('Worker memory and CPU history cleared.');

        return self::SUCCESS;
    }
}
