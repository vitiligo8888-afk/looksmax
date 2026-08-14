<?php

namespace Local\Analytics\Console;

use Flarum\Console\AbstractCommand;
use Local\Analytics\Models\Event;
use Local\Analytics\PostHog\Client;
use Symfony\Component\Console\Input\InputOption;

/**
 * Drain the spool to PostHog.
 *
 * Runs from cron/scheduler, not from the request path — a forum page must never
 * wait on an analytics vendor. Because the spool is the source of truth, a
 * PostHog outage becomes a backlog that drains later rather than lost events,
 * and the watermark only advances for events the API actually accepted.
 */
class FlushCommand extends AbstractCommand
{
    public function __construct(protected Client $posthog)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('analytics:flush')
            ->setDescription('Forward pending analytics events to PostHog')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'events per request', 500)
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'max events per run', 50000);
    }

    protected function fire()
    {
        if (!$this->posthog->configured()) {
            $this->error('posthog host/key not set — nothing to do');
            return 1;
        }

        $batchSize = (int) $this->input->getOption('batch');
        $max = (int) $this->input->getOption('max');
        $sent = 0;

        while ($sent < $max) {
            $events = Event::query()
                ->whereNull('sent_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($events->isEmpty()) {
                break;
            }

            $result = $this->posthog->sendBatch($events->all());

            if ($result['error']) {
                $this->error("batch failed, leaving {$events->count()} pending: {$result['error']}");
                return 1;
            }

            Event::query()->whereIn('id', $result['ids'])->update(['sent_at' => date('Y-m-d H:i:s')]);
            $sent += $result['sent'];
            $this->info("  forwarded {$result['sent']} (total {$sent})");
        }

        $pending = Event::query()->whereNull('sent_at')->count();
        $this->info("done: {$sent} forwarded, {$pending} still pending");
        return 0;
    }
}
