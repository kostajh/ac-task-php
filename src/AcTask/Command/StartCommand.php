<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

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
        // @todo Move this into libtask-php.
        $process = new Process('task status:pending export');
        $process->run();
        $tasks = json_decode($process->getOutput(), TRUE);
        $task_names = array();
        foreach ($tasks as $task) {
            $task_names[$task['id']] = $task['description'];
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
        foreach ($tasks as $task) {
            if (isset($task['start']) && !empty($task['start'])) {
                // We have an active task, so prompt user to stop current task
                // and start new one.
                $active_task = $task['description'];
                $new_task = $task_names[$task_id];

                if (!$dialog->askConfirmation(
                        $output, sprintf('<question>Stop task "%s" and start task "%s"? (y/n)</question>', $active_task, $new_task),
                        false
                    )) {
                    $output->writeln('Did not start task!');

                    return;
                } else {
                    $process = new Process(sprintf('task %d stop', $task['id']));
                    $process->run();
                    $output->writeln('<comment>' . $process->getOutput() . '</comment>');
                }

            }
        }
        $process = new Process(sprintf('task %d start', $task_id));
        $process->run();
        $output->writeln('<info>' . $process->getOutput() . '</info>');
    }
}
