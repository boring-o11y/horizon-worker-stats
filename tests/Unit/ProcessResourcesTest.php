<?php

namespace BoringO11y\HorizonWorkerStats\Tests\Unit;

use BoringO11y\HorizonWorkerStats\Exec;
use BoringO11y\HorizonWorkerStats\ProcessResources;
use PHPUnit\Framework\TestCase;

class ProcessResourcesTest extends TestCase
{
    public function test_cpu_is_read_from_a_proc_stat_line()
    {
        $resources = new ProcessResources(new Exec);

        // The command name holds a space and a closing paren, which is exactly
        // what splitting the whole line on whitespace would get wrong.
        $stat = '4711 (php artisan) x) S 1 4711 4711 0 -1 4194560 12345 0 0 0 250 50 0 0 20 0 1 0 100 104857600 3000';

        $this->assertSame(3.0, $resources->parseProcStat($stat));
        $this->assertNull($resources->parseProcStat('garbage'));
    }

    public function test_resident_memory_is_read_from_a_proc_status_file()
    {
        $resources = new ProcessResources(new Exec);

        $status = "Name:\tphp\nVmPeak:\t  900000 kB\nVmRSS:\t   65536 kB\nThreads:\t1\n";

        $this->assertSame(65536 * 1024, $resources->parseProcStatus($status));
        $this->assertSame(0, $resources->parseProcStatus("Name:\tphp\nState:\tZ (zombie)\n"));
    }

    public function test_ps_output_from_linux_and_macos_is_understood()
    {
        $resources = new ProcessResources(new Exec);

        $this->assertSame([
            101 => ['memory' => 2048 * 1024, 'cpu' => 3723.0],
            102 => ['memory' => 1024 * 1024, 'cpu' => 90061.0],
            103 => ['memory' => 512 * 1024, 'cpu' => 125.5],
        ], $resources->parsePs([
            '  101  2048 01:02:03',
            '  102  1024 1-01:01:01',
            '  103   512 2:05.50',
            '',
            'not a process line',
        ]));
    }

    public function test_the_current_process_can_be_sampled()
    {
        $resources = new ProcessResources(new Exec);

        // Burn a little CPU so the counter is above zero at clock tick resolution.
        for ($i = 0, $x = 0; $i < 3000000; $i++) {
            $x += $i;
        }

        $sample = $resources->sample([getmypid()]);

        $this->assertArrayHasKey(getmypid(), $sample);
        $this->assertGreaterThan(0, $sample[getmypid()]['memory']);
        $this->assertGreaterThan(0, $sample[getmypid()]['cpu']);
    }

    public function test_a_process_that_does_not_exist_is_left_out()
    {
        $resources = new ProcessResources(new Exec);

        $this->assertSame([], $resources->sample([]));
        $this->assertSame([], $resources->sample([999999999]));
    }
}
