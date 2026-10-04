<?php

namespace BoringO11y\HorizonWorkerStats;

class ProcessResources
{
    /**
     * The kernel's clock ticks per second, as /proc/<pid>/stat reports CPU in.
     *
     * This is USER_HZ, which is fixed at 100 on every architecture PHP runs on
     * in practice. It is not the kernel's internal HZ, so a kernel built with
     * a different tick rate still reports in hundredths.
     *
     * @var int
     */
    const CLOCK_TICKS = 100;

    /**
     * The exec implementation.
     *
     * @var Exec
     */
    public $exec;

    /**
     * Create a new process resources instance.
     *
     * @return void
     */
    public function __construct(Exec $exec)
    {
        $this->exec = $exec;
    }

    /**
     * Get the resident memory and total CPU time of the given processes.
     *
     * A process that is gone by the time it is read is left out rather than
     * reported as zero. Memory is resident set size, which counts shared pages
     * in every process that maps them, so a sum across workers slightly
     * overstates what the machine is actually holding.
     *
     * @param  array<int, int>  $pids
     * @return array<int, array{memory: int, cpu: float}>
     */
    public function sample(array $pids)
    {
        $pids = array_values(array_unique(array_filter(array_map('intval', $pids))));

        if (empty($pids)) {
            return [];
        }

        // Reading /proc costs two file reads a process and no fork, and it is
        // there even in slim container images that do not ship ps.
        return is_readable('/proc/self/stat')
            ? $this->fromProc($pids)
            : $this->fromPs($pids);
    }

    /**
     * Read the processes from the Linux proc filesystem.
     *
     * @param  array<int, int>  $pids
     * @return array<int, array{memory: int, cpu: float}>
     */
    protected function fromProc(array $pids)
    {
        $results = [];

        foreach ($pids as $pid) {
            $stat = @file_get_contents("/proc/{$pid}/stat");
            $status = @file_get_contents("/proc/{$pid}/status");

            if ($stat === false || $status === false) {
                continue;
            }

            $cpu = $this->parseProcStat($stat);

            if ($cpu === null) {
                continue;
            }

            $results[$pid] = [
                'memory' => $this->parseProcStatus($status),
                'cpu' => $cpu,
            ];
        }

        return $results;
    }

    /**
     * Get the user plus system CPU seconds from a /proc/<pid>/stat line.
     *
     * @param  string  $stat
     * @return float|null
     */
    public function parseProcStat($stat)
    {
        // The command name is the second field and may itself contain spaces
        // and parentheses, so the fields are counted from its closing paren.
        if (($end = strrpos($stat, ')')) === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));

        // utime and stime are the 14th and 15th fields; the state that follows
        // the command name is the 3rd.
        if (! isset($fields[12])) {
            return null;
        }

        return (float) ((int) $fields[11] + (int) $fields[12]) / static::CLOCK_TICKS;
    }

    /**
     * Get the resident set size in bytes from a /proc/<pid>/status file.
     *
     * @param  string  $status
     * @return int
     */
    public function parseProcStatus($status)
    {
        // Absent for a zombie, which holds no memory worth counting.
        return preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $status, $matches)
            ? (int) $matches[1] * 1024
            : 0;
    }

    /**
     * Read the processes through ps, where there is no proc filesystem.
     *
     * @param  array<int, int>  $pids
     * @return array<int, array{memory: int, cpu: float}>
     */
    protected function fromPs(array $pids)
    {
        return $this->parsePs($this->exec->run(
            'ps -o pid=,rss=,time= -p '.implode(',', $pids).' 2>/dev/null'
        ));
    }

    /**
     * Parse "pid rss time" lines as printed by ps.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{memory: int, cpu: float}>
     */
    public function parsePs(array $lines)
    {
        $results = [];

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (count($fields) < 3 || ! ctype_digit($fields[0]) || ! ctype_digit($fields[1])) {
                continue;
            }

            if (($cpu = $this->parseCpuTime($fields[2])) === null) {
                continue;
            }

            $results[(int) $fields[0]] = [
                'memory' => (int) $fields[1] * 1024,
                'cpu' => $cpu,
            ];
        }

        return $results;
    }

    /**
     * Parse a ps CPU time into seconds.
     *
     * procps prints "[dd-]hh:mm:ss" and BSD prints "m:ss.cc", with the minutes
     * running past sixty, so both are read as optional days and hours ahead of
     * minutes and (possibly fractional) seconds.
     *
     * @param  string  $time
     * @return float|null
     */
    public function parseCpuTime($time)
    {
        if (! preg_match('/^(?:(\d+)-)?(?:(\d+):)?(\d+):(\d+(?:\.\d+)?)$/', $time, $matches)) {
            return null;
        }

        return (int) $matches[1] * 86400
            + (int) $matches[2] * 3600
            + (int) $matches[3] * 60
            + (float) $matches[4];
    }
}
