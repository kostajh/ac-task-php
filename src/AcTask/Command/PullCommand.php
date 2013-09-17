<?php

namespace AcTask\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
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
        // Order of operations.
        // 1. Get a list of BW managed tasks (use the 'url' field for this).
        // 2. Get remote data from AC (find favorite projects, then grab all tasks/subtasks).
        // 3. Create a list of tasks to add (loop through remote data, if task URL isn't in local data, then add to array)
        // 4. Create list of tasks to update (loop through remote data, if task URL matches local data, but aspects differ, add to array)
        // 5. Create list of tasks to complete (loop through local tasks, if task URL isn't in remote data, add to array)
        // 6. Add new issues to BW db
        //      - invoke notify-send
        // 7. Update existing issues in BW db
        //      - invoke notify-send
        // 8. Complete existing issues in BW db.
        //      - invoke notify-send
        // 9. Call task merge
        //      - Delete completed tasks in BW task db.
    }
}
