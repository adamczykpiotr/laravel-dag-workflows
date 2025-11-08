<?php

namespace AdamczykPiotr\DagWorkflows\Commands;

use Illuminate\Console\Command;

class DagWorkflowsCommand extends Command
{
    public $signature = 'dag-workflows';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
