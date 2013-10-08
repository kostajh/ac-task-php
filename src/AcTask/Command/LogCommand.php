<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;
use LibTask\Taskwarrior;

class LogCommand extends Command
{

    protected $dialog;
    protected $output;

    public function __construct(AcTask $AcTask = null)
    {
        $this->AcTask = $AcTask ?: new AcTask();
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('log')
            ->setDescription('Log time in AC for a task.')
            ->addArgument(
                'task_id',
                InputArgument::OPTIONAL,
                'The task ID to log.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks(null, array('status' => 'pending', 'logged' => 'false'));
        $task_names = array();
        foreach ($tasks as $task) {
            $task_names[$task->getId()] = $task->getDescription();
        }
        $task_id = $input->getArgument('task_id');

        $dialog = $this->getHelperSet()->get('dialog');
        $this->dialog = $dialog;
        $this->output = $output;
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
        $task_data = $taskwarrior->loadTask($task_id);
        $udas = $task_data->getUdas();
        if (!isset($udas['ac'])) {
            return $output->writeln('<error>No AC task is linked!</error>');
        }
        $this->task_id = $task_id;
        $this->time_type = $this->getTimeType();
        $this->billable = $this->getBillableStatus();
        $this->message = $this->getLogMessage();
        $this->time = $this->getTime();
        if (!$this->getConfirmation()) {
            return $output->writeln('<error>Cancelled logging time.</error>');
        }
        $ac = $this->AcTask->ActiveCollab;
        $params = array(
            'time_record[value]' => $this->time,
            'time_record[user_id]' => $this->AcTask->userId,
            'time_record[record_date]' => date('n/j/Y'),
            'time_record[job_type_id]' => $this->time_type,
            'time_record[billable_status]' => $this->billable,
            'time_record[summary]' => $this->message,
            'submitted' => 'submitted',
        );

        $path = sprintf('projects/%s/tasks/%s/tracking/time/add', $task_data->getProject(), $udas['ac']);
        $ac->setRequestString($path);
        $result = $ac->callAPI($params, 'POST');
        if (isset($result['permalink'])) {
            $output->writeln('<info>Successfully logged time. See the link here ' . $result['permalink'] . '</info>');
        } else {
            return $output->writeln('<error>An error occurred!</error>');
        }
        // Complete the task.
        $udas = $task_data->getUdas();
        $udas['logged'] = 'true';
        $task_data->setUdas($udas);
        $taskwarrior->update($task_data);
        $output->writeln(sprintf('<info>%s</info>', $taskwarrior->complete($task_data->getUuid())->getOutput()));
    }

    protected function getConfirmation()
    {
        $this->output->writeln('<info>Summary:</info>');
        $time_types = $this->getValidTypes();
        $this->output->writeln(sprintf('<info>- Time type: %s</info>', $time_types[$this->time_type]));
        $this->output->writeln(sprintf('<info>- Billable: %s</info>', $this->billable));
        $this->output->writeln(sprintf('<info>- Message: %s</info>', $this->message));
        $this->output->writeln(sprintf('<info>- Time: %s', $this->time));

        return $this->dialog->askConfirmation(
            $this->output,
            'Proceed with logging time? (y/n) ',
            true
        );
    }

    protected function getTime()
    {
        $time = $this->AcTask->taskTimeInfo($this->task_id);
        $this->output->writeln(sprintf('<info>Time logged in Taskwarrior: %s</info>', $time));

        return $this->dialog->ask(
            $this->output,
            'Time to log: ',
            null
        );
    }

    protected function getLogMessage()
    {
        return $this->dialog->ask(
            $this->output,
            'Message: ',
            null
        );
    }

    protected function getBillableStatus()
    {
        return $this->dialog->askConfirmation(
                        $this->output, 'Billable? (y/n) ',
                        true
                    );
    }

    protected function getTimeType()
    {
        return $this->dialog->select(
                $this->output,
                'Select a task: ',
                $this->getValidTypes(),
                null
        );
    }

    protected function getValidTypes()
    {
        return array(
            '1' => 'General (Legacy)',
            '2' => 'Design Concepts',
            '3' => 'Design Themeing',
            '4' => 'Development',
            '5' => 'System Administration',
            '6' => 'Site Maintenance',
            '7' => 'Project Management',
            '8' => 'Consulting',
            '9' => 'Testing',
            '10' => 'Internal: Sales',
            '11' => 'Internal: Overhead',
            '12' => 'Miscoded',
            '13' => 'Change of Scope',
        );
    }
}
