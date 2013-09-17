<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use AcTask\AcTask;

class PullCommand extends Command
{

    protected $dialog;
    protected $output;

    public function __construct(AcTask $AcTask = null)
    {
        $this->AcTask = $AcTask ?: new AcTask();
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('pull')
            ->setDescription('Like bugwarrior-pull. Grab tasks from AC and place them in TW.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}
