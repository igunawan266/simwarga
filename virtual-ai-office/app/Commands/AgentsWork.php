<?php

namespace App\Commands;

use App\Services\AI\AgentWorker;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Queue worker for AI agent tasks. Run under a process supervisor (systemd/supervisord):
 *   php spark agents:work                 # long-running, restarts itself after --max-runtime
 *   php spark agents:work --once          # drain the queue and exit (cron / testing)
 * Several workers may run in parallel; task claiming is atomic.
 */
class AgentsWork extends BaseCommand
{
    protected $group       = 'Virtual AI Office';
    protected $name        = 'agents:work';
    protected $description = 'Process queued AI agent tasks.';
    protected $options     = [
        '--once'        => 'Process until the queue is empty, then exit.',
        '--sleep'       => 'Seconds to wait when the queue is empty (default 3).',
        '--max-runtime' => 'Exit after this many seconds so the supervisor restarts a fresh process (default 3600).',
    ];

    public function run(array $params)
    {
        $once    = CLI::getOption('once') !== null || array_key_exists('once', $params);
        $sleep   = max(1, (int) (CLI::getOption('sleep') ?? 3));
        $maxRun  = max(60, (int) (CLI::getOption('max-runtime') ?? 3600));
        $started = time();
        $worker  = new AgentWorker();
        $done    = 0;

        while (true) {
            $id = $worker->runNext();
            if ($id !== null) {
                $done++;
                CLI::write('[' . date('H:i:s') . "] task #{$id} processed");

                continue;
            }
            if ($once || time() - $started >= $maxRun) {
                break;
            }
            sleep($sleep);
        }
        CLI::write("Processed {$done} task(s).", 'green');
    }
}
