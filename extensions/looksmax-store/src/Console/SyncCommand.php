<?php

namespace Local\Store\Console;

use Flarum\Console\AbstractCommand;
use Local\Store\Catalogue;
use Symfony\Component\Console\Input\InputOption;

/**
 * Put the shipped catalogue into the table.
 *
 * Adds what is missing and refreshes names, blurbs and gates. It deliberately
 * never touches `price`, `active` or `stock_sold` on a row that already
 * exists: those belong to whoever last used the admin screen, and a deploy
 * that silently undoes a repricing is how an exploited item comes back on
 * sale.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(protected Catalogue $catalogue)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('store:sync')
            ->setDescription('Insert missing catalogue items and refresh their descriptions')
            ->addOption('no-refresh', null, InputOption::VALUE_NONE, 'only insert missing rows');
    }

    protected function fire()
    {
        $result = $this->catalogue->sync(!$this->input->getOption('no-refresh'));

        $this->info('catalogue: ' . $result['added'] . ' added, ' . $result['updated'] . ' refreshed');
    }
}
