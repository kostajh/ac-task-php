<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;
use LibTask\Task\Task;
use LibTask\Taskwarrior;

class TimesheetCommand extends Command
{

    public function __construct(AcTask $AcTask = null)
    {
        $this->AcTask = $AcTask ?: new AcTask();
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('timesheet')
            ->setDescription('Show tasks worked today.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // @todo Move this into libtask-php.
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks('-life', array('status' => 'pending'));
        $projects = array();
        $output->writeln('Searching through tasks...');
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $progress->start($output, count($tasks));

        foreach ($tasks as $task) {
            $task_time = $this->AcTask->taskTimeInfo($task->getId());
            if ($task_time && $task->getId() !== null) {
                if ($task->getProject() == null) {
                    $task->setProject('misc');
                }
                $udas = $task->getUdas();
                $projects[$task->getProject()][] = array(
                    'active' => $task->getStart() ? $task->getStart() : null,
                    'id' => $task->getId(),
                    'time' => $task_time,
                    'task' => $task->getDescription(),
                    'ac' => isset($udas['ac']) ? $udas['ac'] : null,
                  );
            }
            $progress->advance();
        }
        $progress->finish();
        $output->writeln('');

        foreach ($projects as $project => $tasks) {
            $output->writeln('<info>' . $project . '</info>');
            $separator = '';
            for ($i = 0; $i < strlen($project); $i++) {
                $separator .= '=';
            }
            $output->writeln('<info>' . $separator . '</info>');
            foreach ($tasks as $task) {
                if ($task['active']) {
                    $format_start = '<question>';
                    $format_end = '</question>';
                } else {
                    $format_start = '<info>';
                    $format_end = '</info>';
                }
                $output->writeln('- ' . $format_start . '#' . $task['id'] . ' ' . $task['task'] . $format_end . '<comment> ' . $task['time'] . '</comment>');
                if (!$task['ac']) {
                    $output->writeln('  <error>' . 'No AC task linked!' . '</error>');
                }
            }
            $output->writeln('');
        }

    }
}
