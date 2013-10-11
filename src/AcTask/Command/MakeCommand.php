<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;
use LibTask\Task\Task;
use LibTask\Taskwarrior;

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
        $task = new Task($description);
        $task->setUdas(array('logged' => 'false', 'ac' => $task_id, 'url' => $url));
        $task->setTags(array('work'));
        $task->setDue('today');
        $task->setProject($project);
        $taskwarrior = new Taskwarrior();
        $response = $taskwarrior->save($task)->getResponse();

        $output->writeln(sprintf('<info>Added task %d "%s" in "%s".</info>',
            $response['task']->getId(),
            $response['task']->getDescription(),
            $response['task']->getProject()));
    }
}
