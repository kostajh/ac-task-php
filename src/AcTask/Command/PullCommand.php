<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use AcTask\AcTask;

class PullCommand extends Command
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
            ->setName('pull')
            ->setDescription('Like bugwarrior-pull. Grab tasks from AC and place them in TW.')
            ->addOption(
                'silent',
                null,
                InputOption::VALUE_NONE,
                'If pull should be quiet'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not run `task merge` and `task delete`'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = !$input->getOption('silent');
        // Get list of BW managed tasks.
        $tasks = $this->AcTask->getTasks();
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $output->writeln('<info>Getting list of AC tasks.</info>');
        $progress->start($output, count($tasks));
        $bw_managed_tasks = array();
        foreach ($tasks as $task) {
            if (isset($task['bwissueurl'])) {
                $bw_managed_tasks[$task['bwissueurl']] = $task['id'];
            }
            $progress->advance();
        }
        $progress->finish();
        $output->writeln(sprintf('<info>Found %d tasks managed by Bugwarrior.</info>', count($bw_managed_tasks)));

        // Get favorite projects.
        $output->writeln('Getting list of starred projects...');
        $projects = $this->AcTask->getFavoriteProjects();

        // Get tasks per project
        $output->writeln('Getting tasks for projects');
        // Testing.
        // $projects = array_slice($projects, 3, 6);
        $assigned_tasks = array();
        foreach ($projects as $project) {
            $output->writeln(sprintf('<info>Analyzing (sub)tasks for %s...</info>', $project['name']));
            $tasks = $this->AcTask->ActiveCollab->getTasksForProject($project['id']);
            if (count($tasks)) {
                $task_progress = $this->getHelperSet()->get('progress');
                $task_progress->start($output, count($tasks));

                foreach ($tasks as $task) {
                    if ($task['assignee_id'] == $this->AcTask->userId && !$task['completed_on']) {
                        $assigned_tasks[$task['permalink']] = array(
                            'permalink' => $task['permalink'],
                            'task_id' => $task['task_id'],
                            'id' => $task['id'],
                            'project_id' => $task['project_id'],
                            'project_slug' => $this->AcTask->getProjectSlug($task['permalink']),
                            'description' => $task['name'],
                            'type' => 'task',
                            'created_on' => $task['created_on']['mysql'],
                            'created_by_id' => $task['created_by_id'],
                            'priority' => isset($task['priority']) ? $task['priority'] : null,
                            'due' => isset($task['due_on']['mysql']) ? $task['due_on']['mysql'] : null,
                        );
                    }
                    $task_progress->advance();
                }
                $task_progress->finish();
            }
            // Subtasks
            $subtasks = $this->AcTask->ActiveCollab->getSubtasksForProject($project['id']);
            if (count($subtasks)) {
                $subtask_progress = $this->getHelperSet()->get('progress');
                $subtask_progress->start($output, count($subtasks));

                foreach ($subtasks as $subtask) {
                    if ($subtask['assignee_id'] == $this->AcTask->userId && !$subtask['completed_on']) {
                        $assigned_tasks[$subtask['permalink']] = array(
                            'permalink' => $subtask['permalink'],
                            'task_id' => $subtask['id'],
                            'project_id' => isset($subtask['project_id']) ? $subtask['project_id'] : null,
                            'project_slug' => $this->AcTask->getProjectSlug($subtask['permalink']),
                            'description' => $subtask['body'],
                            'type' => 'subtask',
                            'created_on' => $subtask['created_on'],
                            'parent_url' => $subtask['parent_url'],
                            'created_by_id' => $subtask['created_by_id'],
                            'priority' => isset($subtask['priority']) ? $subtask['priority'] : null,
                            'due' => isset($subtask['due_on']) ? $subtask['due_on'] : null,
                        );
                    }
                    $subtask_progress->advance();
                }
                $subtask_progress->finish();
            }
        }

        $tasks_to_add = $tasks_to_update = $tasks_to_complete = array();

        // Find tasks to add/update
        foreach ($assigned_tasks as $permalink => $task) {
            if (!isset($bw_managed_tasks[$permalink])) {
                $tasks_to_add[] = $task;
            }
            else {
                $tasks_to_update[] = $task;
            }
        }
        $output->writeln(sprintf('<info>Found %d new tasks.</info>', count($tasks_to_add)));
        $output->writeln(sprintf('<info>Found %d tasks to update.</info>', count($tasks_to_update)));
        // Tasks to delete
        foreach ($bw_managed_tasks as $permalink => $task_id) {
            if (!isset($assigned_tasks[$permalink])) {
                $tasks_to_complete[] = $task_id;
            }
        }
        $output->writeln(sprintf('<info>Found %d tasks to complete.</info>', count($tasks_to_complete)));

        // Add new issues to BW database.
        if (count($tasks_to_add)) {
            foreach ($tasks_to_add as $task) {
                $command = sprintf('task rc:/home/kosta/.bugwarrior_taskrc add "%s" logged:false due:% project:%s bwissueurl:%s',
                    $task['description'],
                    $task['due'],
                    $task['project_slug'],
                    $task['permalink']
                );
                $process = new Process($command);
                $process->run();
                $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));
                $this->notifySend('Added new task', $task['description']);
            }
        }
        // Update existing issues.
        // @todo

        // Complete issues.
        if (count($tasks_to_complete)) {
            foreach ($tasks_to_complete as $task) {
                $command = sprintf('task rc:/home/kosta/.bugwarrior_taskrc %d done', $task['id']);
                $process = new Process($command);
                $process->run();
                $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));
                $this->notifySend('Completed task', $task['description']);
            }
        }

        // Merge tasks in.
        $command = 'task rc.verbose=nothing rc.merge.autopush=no merge /home/kosta/.bugwarrior-tasks/';
        $process = new Process($command);
        $process->run();
        $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));

        // Delete completed tasks from BW db.
        $command = 'task rc:/home/kosta/.bugwarrior_taskrc rc.verbose=nothing rc.confirmation=no rc.bulk=100 status:completed delete';
        $process = new Process($command);
        $process->run();
        $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));
    }

    protected function notifySend($summary, $body)
    {
        $process = new Process(sprintf('notify-send "%s" "%s"', $summary, $body));
        $process->run();
    }
}
