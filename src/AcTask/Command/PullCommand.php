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
        // First things first. Make backup of ~/.task.
        $process = new Process('cd /home/kosta/.task && git add . && git commit -m "Snapshot before Bugwarrior Pull"');
        $process->run();
        $verbose = !$input->getOption('silent');
        // Get list of BW managed tasks.
        $taskwarrior = new Taskwarrior();
        $tasks = $taskwarrior->loadTasks(null, array());
        $progress = $this->getHelperSet()->get('progress');
        $progress->setBarCharacter('<comment>=</comment>');
        $output->writeln('<info>Getting list of AC tasks.</info>');
        $progress->start($output, count($tasks));
        $bw_managed_tasks = array();
        foreach ($tasks as $key => $task) {
            $udas = $task->getUdas();
            if (isset($udas['bwissueurl']) && in_array($task->getStatus(), array('pending', 'waiting'))) {
                $bw_managed_tasks[$udas['bwissueurl']] = $task->getId();
            }
            $progress->advance();
        }
        $progress->finish();
        $output->writeln(sprintf('<info>Found %d tasks managed by Bugwarrior.</info>', count($bw_managed_tasks)));

        // Get labels
        $output->writeln('<info>Getting labels...</info>');
        $label_data = $this->AcTask->ActiveCollab->getAssignmentLabels();
        $labels = array();
        foreach ($label_data as $label) {
            $labels[$label['id']] = preg_replace("/ +/", "", $label['name']);
        }
        $output->writeln(sprintf('<info>Found %d labels.</info>', count($labels)));

        // Get favorite projects.
        $output->writeln('<info>Getting list of starred projects...</info>');
        $projects = $this->AcTask->getFavoriteProjects();
        $output->writeln(sprintf('<info>Found %d projects</info>.', count($projects)));
        // Get tasks per project
        $output->writeln('<info>Getting tasks for projects...</info>');
        $assigned_tasks = array();
        foreach ($projects as $project) {
            $output->writeln(sprintf('<info>Analyzing tasks and subtasks for %s...</info>', $project['name']));
            // Get milestones for the project.
            $milestones = $this->AcTask->ActiveCollab->listMilestonesByProjectId($project['id']);
            $milestones_sorted = array();
            if (count($milestones)) {
                foreach ($milestones as $milestone) {
                    $milestones_sorted[$milestone['id']] = $milestone;
                }
            }
            $tasks = $this->AcTask->ActiveCollab->getTasksForProject($project['id']);
            if (count($tasks)) {
                $task_progress = $this->getHelperSet()->get('progress');
                $task_progress->start($output, count($tasks));

                foreach ($tasks as $task) {
                    if ($task['assignee_id'] == $this->AcTask->userId && !$task['completed_on']) {
                        $milestone = null;
                        if (isset($milestones_sorted[$task['milestone_id']])) {
                            $milestone = strtoupper($milestones_sorted[$task['milestone_id']]['name']);
                            $milestone = str_replace(array('/', ' ', ':', '-', '(', ')'), '', $milestone);
                        }
                        $assigned_tasks[md5($task['permalink'])] = array(
                            'permalink' => $task['permalink'],
                            'task_id' => $task['task_id'],
                            'id' => $task['id'],
                            'label' => isset($task['label_id']) && isset($labels[$task['label_id']]) ? $labels[$task['label_id']] : null,
                            'project_id' => $task['project_id'],
                            'project_slug' => $this->AcTask->getProjectSlug($task['permalink']),
                            'description' => $task['name'],
                            'type' => 'task',
                            'created_on' => $task['created_on']['mysql'],
                            'created_by_id' => $task['created_by_id'],
                            'priority' => isset($task['priority']) ? $task['priority'] : null,
                            'due' => isset($task['due_on']['mysql']) ? $task['due_on']['mysql'] : null,
                            'milestone' => $milestone ? $milestone : null,
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
                        $milestone = null;
                        if (isset($milestones_sorted[$task['milestone_id']])) {
                            $milestone = strtoupper($milestones_sorted[$task['milestone_id']]['name']);
                            $milestone = str_replace(array('/', ' ', ':', '-', '(', ')'), '', $milestone);
                        }
                        $assigned_tasks[md5($subtask['permalink'])] = array(
                            'permalink' => $subtask['permalink'],
                            'task_id' => $subtask['parent_id'],
                            'project_id' => isset($subtask['project_id']) ? $subtask['project_id'] : null,
                            'project_slug' => $this->AcTask->getProjectSlug($subtask['permalink']),
                            'description' => $subtask['body'],
                            'type' => 'subtask',
                            'label' => isset($subtask['label_id']) && isset($labels[$subtask['label_id']]) ? $labels[$subtask['label_id']] : null,
                            'created_on' => $subtask['created_on'],
                            'parent_url' => $subtask['parent_url'],
                            'created_by_id' => $subtask['created_by_id'],
                            'priority' => isset($subtask['priority']) ? $subtask['priority'] : null,
                            'due' => isset($subtask['due_on']) ? $subtask['due_on'] : null,
                            'milestone' => $milestone ? $milestone : null,
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
            } else {
                // TODO: Only update tasks that need to be updated.
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

        // Add new issues to BW database.
        if (count($tasks_to_add)) {
            foreach ($tasks_to_add as $remote_task) {
                $task_id = ($remote_task['type'] == 'subtask') ? $this->AcTask->getAcTaskId($remote_task['parent_url']) : $this->AcTask->getAcTaskId($remote_task['permalink']);
                $tw_task = new Task(sprintf('(bw)#%d - %s', $remote_task['task_id'], $remote_task['description']));
                // If it's a subtask, "ac" should be Task ID, not Subtask ID.
                if ($remote_task['type'] == 'subtask') {
                  $parse = parse_url($remote_task['permalink']);
                  $parts = explode('/', ltrim($parse['path'], '/'));
                  $project = $parts[1];
                  $ac_task_id = (int) $parts[3];
                } else {
                  $ac_task_id = (int) $remote_task['task_id'];
                }
                $tw_task->setUdas(
                    array(
                        'ac' => (int) $ac_task_id,
                        'bwissueurl' => md5($remote_task['permalink']),
                        'logged' => 'false',
                        'permalink' => $remote_task['permalink'],
                    )
                );
                $tw_task->setDue($remote_task['due']);
                $tw_task->setProject($remote_task['project_slug']);
                $tw_task->setPriority($this->parsePriority($remote_task['priority']));
                $tags = array('work');
                if (!empty($remote_task['label'])) {
                    $tags[] = $remote_task['label'];
                }
                if (!empty($remote_task['milestone'])) {
                    $tags[] = $remote_task['milestone'];
                }
                $tw_task->setTags($tags);
                $output->writeln(sprintf('Adding task "%s"', $tw_task->getDescription()));
                $response = $taskwarrior->save($tw_task)->getResponse();
                $output->writeln(sprintf('<info>%s</info>', $response['output']));
                $this->notifySend('Added new task', $tw_task->getDescription());
            }
        }

        // Update existing issues.
        if (count($tasks_to_update)) {
            foreach ($tasks_to_update as $bw_issue_url => $remote_task) {
                // Get task ID to modify based on bwissueurl.
                $tw_task = $taskwarrior->loadTask(null, array('bwissueurl' => $bw_issue_url));
                if (is_object($tw_task)) {
                    $tw_task->setDue(strtotime($remote_task['due']));
                    $tw_task->setDescription(sprintf('(bw)#%d - %s', $remote_task['task_id'], $remote_task['description']));
                    $tw_task->setPriority($this->parsePriority($remote_task['priority']));
                    $tags = $tw_task->getTags();
                    $udas = $tw_task->getUdas();
                    $udas['ac'] = (int) $remote_task['task_id'];
                    $tw_task->setUdas($udas);
                    $formatted_tags = array();
                    foreach ($tags as $tag) {
                        if (!empty($tag)) {
                            $formatted_tags[$tag] = $tag;
                        }
                    }
                    if (!empty($remote_task['label'])) {
                        $formatted_tags[$remote_task['label']] = $remote_task['label'];
                    }
                    if (!empty($remote_task['milestone'])) {
                        $formatted_tags[$remote_task['milestone']] = $remote_task['milestone'];
                    }
                    $tw_task->setTags(array_keys($formatted_tags));
                    $response = $taskwarrior->save($tw_task);
                    // Send notification and write to logs only if task was
                    // actually modified.
                    if (strpos($response['output'], 'Modified 1 tasks')) {
                        $this->notifySend('Modified task', $tw_task->getDescription());
                        $output->writeln(sprintf('Updating task "%s"', $tw_task->getDescription()));
                        $output->writeln(sprintf('<info>%s</info>', $response['output']));
                        $output->writeln(sprintf('<info>%s</info>', $response['error_output']));
                    }
                } else {
                    $output->writeln(sprintf('<error>The permalink %s should be linked to a task...</error>', $bw_issue_url));
                }
            }
        }

        // Complete issues.
        if (count($tasks_to_complete)) {
            foreach ($tasks_to_complete as $bw_issue_url => $ac_task_id) {
                $tw_task = $taskwarrior->loadTask(null, array('bwissueurl' => $bw_issue_url));
                if (is_object($tw_task)) {
                    $response = $taskwarrior->complete($tw_task->getUuid())->getResponse();
                    $output->writeln(sprintf('<info>%s</info>', $response['output']));
                    $this->notifySend('Completed task', $tw_task->getDescription());
                } else {
                    $output->writeln(sprintf('<error>Could not find task for %s</error>', $bw_issue_url));
                }

            }
        }

    }

    protected function notifySend($summary, $body)
    {
        $process = new Process(sprintf('notify-send "%s" "%s"', $summary, $body));
        $process->run();
    }

    protected function parsePriority($priority = 0)
    {
        if ($priority == 0) {
            return 'M';
        } elseif ($priority > 0) {
            return 'H';
        } else {
            return 'L';
        }
    }
}
