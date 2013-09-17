<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;
use TijsVerkoyen\ActiveCollab\ActiveCollab;

class MakeCommand extends Command
{

    public function __construct(AcTask $AcTask = null)
    {
        $this->AcTask = $AcTask ?: new AcTask();
        parent::__construct();
    }

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
        $parse = parse_url($url);
        $parts = explode('/', ltrim($parse['path'], '/'));
        $project = $parts[1];
        $task_id = $parts[3];
        $dialog = $this->getHelperSet()->get('dialog');
        $description = $dialog->ask(
            $output,
            'Please enter a description: ',
            null
        );
        if (!$description) {
            return false;
        }
        $process = new Process(sprintf('task add %s logged:false ac:%d project:%s url:%s', $description, $task_id, $project, $url));
        $process->run();
        $result = $process->getOutput();
        $output->writeln('<info>' . $result . '</info>');
    }
}
