<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;

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
        $process = new Process('task -life rc.verbose=nothing logged:false rc.json.array=TRUE status:pending export');
        $process->run();
        $tasks = json_decode($process->getOutput(), TRUE);
        $projects = array();
        $output->writeln('Searching through tasks...');
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $progress->start($output, count($tasks));

        foreach ($tasks as $task) {
            $task_time = $this->AcTask->taskTimeInfo($task['id']);
            if ($task_time) {
                $projects[$task['project']][] = array(
                    'active' => $task['start'],
                    'id' => $task['id'],
                    'time' => $task_time,
                    'task' => $task['description'],
                    'ac' => $task['ac']
                  );
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
