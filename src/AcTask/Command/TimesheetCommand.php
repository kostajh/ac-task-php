<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;

class TimesheetCommand extends Command
{
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
        $process = new Process('task status:pending export');
        $process->run();
        $tasks = json_decode($process->getOutput(), TRUE);
        $projects = array();
        $output->writeln('Searching through tasks...');
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $progress->start($output, count($tasks));

        foreach ($tasks as $task) {
          $task_info = new Process('task rc.verbose=nothing ' . $task['id'] . ' info');
          $task_info->run();
          $task_info_output = $task_info->getOutput();
          if (strpos($task_info_output, 'Total active time')) {
            $task_info_output_components = explode(' ', $task_info_output);
            $time = trim(end($task_info_output_components));
            if (!isset($task['project'])) {
                $task['project'] = 'misc';
            }
            if (!isset($task['ac'])) {
                $task['ac'] = '';
            }
            if (!isset($task['start'])) {
                $task['start'] = '';
            }
            $projects[$task['project']][] = array('active' => $task['start'], 'id' => $task['id'], 'time' => $time, 'task' => $task['description'], 'ac' => $task['ac']);
          }
          $progress->advance();
        }
        $progress->finish();

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
