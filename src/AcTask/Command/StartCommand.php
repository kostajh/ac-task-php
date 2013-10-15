<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use LibTask\Taskwarrior;

class StartCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('start')
            ->setDescription('Starts a task, and ensures that only one task is active.')
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
        if (isset($udas['bwissueurl']) && !empty($udas['bwissueurl'])) {
            $output->writeln('<error>Clone this task before starting it.</error>');
            return false;
        }
        foreach ($tasks as $task) {
            if (($task->getStart() !== null) && !empty($task->getStart())) {
                // We have an active task, so prompt user to stop current task
                // and start new one.
                $active_task = $task->getDescription();
                $new_task = $task_names[$task_id];

                if (!$dialog->askConfirmation(
                        $output, sprintf('<question>Stop task "%s" and start task "%s"? (y/n)</question>', $active_task, $new_task),
                        false
                    )) {
                    $output->writeln('Did not start task!');

                    return;
                } else {
                    $output->writeln('<comment>' . $taskwarrior->stop($task->getUuid())->getOutput() . '</comment>');
                }

            }
        }
        // Get the UUID
        foreach ($tasks as $task) {
            if ($task->getId() == $task_id) {
                $output->writeln('<info>' . $taskwarrior->start($task->getUuid())->getOutput() . '</info>');
                break;
            }
        }
    }
}
