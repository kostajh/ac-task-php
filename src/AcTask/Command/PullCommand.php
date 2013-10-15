<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use LibTask\Taskwarrior;
use LibTask\Task\Task;
use LibTask\Task\Annotation;
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
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks();
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $output->writeln('<info>Getting list of AC tasks.</info>');
        $progress->start($output, count($tasks));
        $bw_managed_tasks = array();
        foreach ($tasks as $key => $task) {
            $udas = $task->getUdas();
            if (isset($udas['bwissueurl'])) {
                $bw_managed_tasks[$udas['bwissueurl']] = $task->getId();
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
            $output->writeln(sprintf('<info>Analyzing tasks and subtasks for %s...</info>', $project['name']));
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
                $tasks_to_add[$permalink] = $task;
            }
            else {
                $tasks_to_update[$permalink] = $task;
            }
        }
        $output->writeln(sprintf('<info>Found %d new tasks.</info>', count($tasks_to_add)));
        $output->writeln(sprintf('<info>Found %d tasks to update.</info>', count($tasks_to_update)));
        // Tasks to complete
        foreach ($bw_managed_tasks as $permalink => $task_id) {
            if (!isset($assigned_tasks[$permalink])) {
                $tasks_to_complete[$permalink] = $task_id;
            }
        }
        $output->writeln(sprintf('<info>Found %d tasks to complete.</info>', count($tasks_to_complete)));

        $bugwarrior_taskwarrior = new Taskwarrior('/home/kosta/.bugwarrior_taskrc', '/home/kosta/.bugwarrior-tasks');

        // Add new issues to BW database.
        if (count($tasks_to_add)) {
            foreach ($tasks_to_add as $remote_task) {
                $task_id = ($remote_task['type'] == 'subtask') ? $this->AcTask->getAcTaskId($remote_task['parent_url']) : $this->AcTask->getAcTaskId($remote_task['permalink']);
                $tw_task = new Task(sprintf('(bw)#%d - %s', $remote_task['task_id'], $remote_task['description']));
                $annotation = new Annotation('Added by Bugwarrior PHP');
                $tw_task->setAnnotations(array($annotation));
                $tw_task->setUdas(
                    array(
                        'ac' => (int) $remote_task['task_id'],
                        'bwissueurl' => $remote_task['permalink'],
                        'logged' => 'false',
                    )
                );
                $tw_task->setDue($remote_task['due']);
                $tw_task->setProject($remote_task['project_slug']);
                $tw_task->setTags(array('work'));
                $output->writeln(sprintf('Adding task "%s"', $tw_task->getDescription()));
                $response = $bugwarrior_taskwarrior->save($tw_task)->getResponse();
                $output->writeln(sprintf('<info>%s</info>', $response['output']));
                $this->notifySend('Added new task', $tw_task->getDescription());
            }
        }

        // Update existing issues.
        if (count($tasks_to_update)) {
            foreach ($tasks_to_update as $bw_issue_url => $remote_task) {
                // Get task ID to modify based on bwissueurl.
                $tw_task = $bugwarrior_taskwarrior->loadTask(null, array('bwissueurl' => $bw_issue_url));
                if (is_object($tw_task)) {
                    $tw_task->setDue(strtotime($remote_task['due']));
                    $tw_task->setDescription($remote_task['description']);
                    $tags = $tw_task->getTags();
                    $tags += array('work');
                    $tw_task->setTags($tags);
                    $output->writeln(sprintf('Updating task "%s"', $tw_task->getDescription()));
                    $response = $bugwarrior_taskwarrior->save($tw_task)->getResponse();
                    $output->writeln(sprintf('<info>%s</info>', $response['output']));
                    // Notify Send?
                }
                else {
                    $output->writeln(sprintf('<error>The permalink %s should be linked to a task...</error>', $bw_issue_url));
                }
            }
        }

        // Complete issues.
        if (count($tasks_to_complete)) {
            foreach ($tasks_to_complete as $bw_issue_url => $ac_task_id) {
                $tw_task = $bugwarrior_taskwarrior->loadTask(null, array('bwissueurl' => $bw_issue_url));
                if (is_object($tw_task)) {
                    $output->writeln('Completed');
                    $output->writeln(sprintf('Completing task "%s"', $tw_task->getDescription()));
                    $response = $bugwarrior_taskwarrior->complete($tw_task->getUuid())->getResponse();
                    $output->writeln(sprintf('<info>%s</info>', $response['output']));
                    $this->notifySend('Completed task', $tw_task->getDescription());
                }
                else {
                    $output->writeln('Could not find task!');
                }

            }
        }

        die();
        return;

        // Merge tasks in.
        $command = 'task rc.verbose=nothing rc.merge.autopush=no merge /home/kosta/.bugwarrior-tasks/';
        $output->writeln('Merging tasks...');
        $process = new Process($command);
        $process->run();
        $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));

        // Delete completed tasks from BW db.
        $command = 'task rc:/home/kosta/.bugwarrior_taskrc rc.verbose=nothing rc.confirmation=no rc.bulk=100 status:completed delete';
        $output->writeln('Deleting completed tasks from BW db');
        $output->writeln($output);
        $process = new Process($command);
        $process->run();
        // $output->writeln(sprintf('<info>%s</info>', $process->getOutput()));
    }

    protected function notifySend($summary, $body)
    {
        $process = new Process(sprintf('notify-send "%s" "%s"', $summary, $body));
        $process->run();
    }
}
