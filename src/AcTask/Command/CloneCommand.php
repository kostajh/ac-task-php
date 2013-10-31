<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use LibTask\Taskwarrior;
use LibTask\Task\Task;

class CloneCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('clone')
            ->setDescription('Clones a task and sets dependencies.')
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
        $new_task = clone $task;
        $new_task->setId(null);
        $new_task->setUuid(null);
        $new_task->setParent($task->getUuid());
        $tags = $new_task->getTags();
        $tags[] = '+next';
        $new_task->setTags($tags);
        unset($udas['bwissueurl']);
        $new_task->setUdas($udas);
        $dialog = $this->getHelperSet()->get('dialog');
        $description = $dialog->ask(
            $output,
            'Please enter a description: ',
            null
        );
        if (!$description) {
            return false;
        }
        $new_task->setDescription($description);
        $response = $taskwarrior->save($new_task)->getResponse();
        $output->writeln(sprintf('<info>Added task %d "%s" in "%s".</info>',
            $response['task']->getId(),
            $response['task']->getDescription(),
            $response['task']->getProject()));
        $task->setDependencies(array($response['task']->getUuid()));
        $response = $taskwarrior->save($task);
        $output->writeln(sprintf('<info>%s</info>', $response['output']));
    }
}
