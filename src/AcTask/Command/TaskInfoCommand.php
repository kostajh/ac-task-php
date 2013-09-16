<?php

namespace ActiveCollabConsole\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use ActiveCollabConsole\ActiveCollabConsole;

/**
 * Displays information about a task.
 * @author Kosta Harlan <kostajh@gmail.com>
 */
class TaskInfoCommand extends Command
{

    /**
     * @param ActiveCollabConsole $acConsole
     */
    public function __construct(ActiveCollabConsole $acConsole = null)
    {
        $this->acConsole = $acConsole ?: new ActiveCollabConsole();
        parent::__construct();
    }

    /**
     * @see Command
     */
    protected function configure()
    {
      $this
        ->setName('task-info')
        ->setDescription('Display information about a specific ticket.')
        ->setDefinition(array(
            new InputArgument('task', InputArgument::REQUIRED, 'Project ID and Ticket ID', NULL),
        ))
        ->setHelp('The <info>task-info</info> command displays information about a specific ticket. Information must be provided in the format <comment>project_id:ticket_id</comment>.

        <comment>Samples:</comment>
        To display ticket information for ticket 233 in project 150
        <info>ac task-info 150:233</info>
        ');
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectTicket = $input->getArgument('task');
        if (!$projectTicket) {
          $output->writeln("<error>Please specify a Project number and ticket ID in the format: {project_id}:{ticket_id}</error>");
          return false;
        }
        $projectId = substr($projectTicket, 0, strpos($projectTicket, ':'));
        $ticketId = substr($projectTicket, strpos($projectTicket, ':') + 1);
        if (!$projectId || !$ticketId) {
          $output->writeln("<error>Please specify a Project number and ticket ID in the format: {project_id}:{ticket_id}</error>");
          return false;
        }
        $currentUserId = $this->acConsole->api('whoAmI');
        $data = $this->acConsole->getTicket($projectId, $ticketId);
        $project = $this->acConsole->getProject($projectId);
        $info = array();
        if (is_array($data)) {
          $output->writeln("<info>Project:</info> " . $project['name']);
          $output->writeln("<info>Ticket:</info> [#" . $data['ticket_id'] . "] " . $data['name']);
          $output->writeln("<info>Created on:</info> " . $data['created_on']);
          $output->writeln("<info>URL:</info> " . $data['permalink']);
          // Display assignees.
          $output->writeln("<info>Assignees:</info>");
          $assignees = $this->acConsole->getAssigneesByTicket($data);
          $assigned = $assignees['assigned'];
          $responsible = $assignees['responsible'];
          if ($responsible) {
            $output->writeln("    <comment>Responsible:</comment> " . $responsible['name']);
          }
          if ($assigned) {
            foreach ($assigned as $assignee) {
              $names[] = $assignee['name']  ;
            }
            $output->writeln("    <info>Assigned:</info> " . implode(', ', $names));
          }
          $data['body'] = $this->acConsole->cleanText($data['body']);
          $output->writeln("<info>Body: </info>\n" . trim($data['body'], 200));
          // Get last comment.
          if (!empty($data['comments'])) {
            $data['comments'][0]['body'] = $this->acConsole->cleanText($data['comments'][0]['body']);
            $output->writeln("<info>Last comment: </info>" . strip_tags($data['comments'][0]['body']));
          }

          isset($data['due_on']) ? $output->writeln("<info>Due on:</info> " . $data['due_on']) : NULL;
          if (isset($data['tasks']) && $data['tasks']) {
            $output->writeln("<info>Tasks:</info>");
            foreach ($data['tasks'] as $task) {
              if ($task['completed_on']) {
                $output->writeln("<info>[DONE]</info> " . $task['body']);
              } else {
                $text = "<comment>[PENDING]</comment> " . $task['body'];
                if ($task['due_on'] && !$task['completed_on']) {
                  $text .= "<comment> [" . $task['due_on'] . "]</comment>";
                }
                $output->writeln($text);
              }

            }
          }

          return;
        } else {
          $output->writeln("<error>Could not load data for project " . $projectId . " and ticket " . $ticketId . "</error>");
          return false;
        }
    }

}
