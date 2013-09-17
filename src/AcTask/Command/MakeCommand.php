<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

class MakeCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('make')
            ->setDescription('Make a task from an AC URL.')
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'The task URL. Subtasks not allowed.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // @todo Move this into libtask-php.
        $url = $input->getArgument('url');
        print $url;
        $parse = parse_url($url);
        $parts = explode('/', ltrim($parse['path'], '/'));
        $project = $parts[1];
        $task_id = $parts[3];
        // @todo Get description
        // @todo Add task
    }
}
