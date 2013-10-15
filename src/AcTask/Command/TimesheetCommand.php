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
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks('+work', array('status' => 'pending'));
        $projects = array();
        $output->writeln('Searching through tasks...');
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $progress->start($output, count($tasks));

        foreach ($tasks as $task) {
            $task_time = $taskwarrior->getTaskActiveTime($task->getUuid());
            if ($task->getId() !== null) {
                $udas = $task->getUdas();
                $project = ($task->getProject() !== null) ? $task->getProject() : 'misc';
                $projects[$project][] = array(
                    'active' => $task->getStart(),
                    'id' => $task->getId(),
                    'time' => $task_time,
                    'task' => $task->getDescription(),
                    'ac' => isset($udas['ac']) ? $udas['ac'] : null,
                    'due' => $task->getDue(),
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
                $output->writeln(
                    sprintf('- %s#%d %s%s<comment> %s</comment>%s',
                    $format_start,
                    $task['id'],
                    $task['task'],
                    $format_end,
                    $task['time'],
                    !empty($task['due']) ? ' <info>[Due ' . date('m/d', strtotime($task['due'])) . ']</info>' : null
                ));
                if ($task['time'] && !$task['ac']) {
                    $output->writeln('  <error>' . 'No AC task linked!' . '</error>');
                }
            }
            $output->writeln('');
        }

    }
}
