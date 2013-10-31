<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use LibTask\Taskwarrior;

class OpenCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('open')
            ->setDescription('Opens a task in ActiveCollab.')
            ->addArgument(
                'task_id',
                InputArgument::OPTIONAL,
                'The task ID to start.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks('+work', array('status' => 'pending'));
        $task_names = array();
        foreach ($tasks as $task) {
            $task_names[$task->getId()] = $task->getDescription();
        }
        $task_id = $input->getArgument('task_id');

        $dialog = $this->getHelperSet()->get('dialog');
        if (!$task_id) {
            $task_id = $dialog->select(
                $output,
                'Select a task: ',
                $task_names,
                0
        );
        }
        if (!$task_id) {
            return;
        }
        $task = $taskwarrior->loadTask($task_id);
        $udas = $task->getUdas();
        $ac_id = $udas['ac'];
        $project = $task->getProject();
        if ($ac_id > 1000) {
            // If AC ID is greater than 1000, assume this is a subtask.
            $url = sprintf('https://ac.designhammer.net/projects/%s/user-tasks', $project);
        } else {
            $url = sprintf('https://ac.designhammer.net/projects/%s/tasks/%d', $project, $ac_id);
        }
        $process = new Process(sprintf('xdg-open %s', $url));
        $process->run();
    }
}
