<?php

namespace AcTask\Console;

use Symfony\Component\Console\Application as BaseApplication;
use AcTask\Command\AboutCommand;
use AcTask;

/**
* @author Kosta Harlan <kosta@embros.org>
*/
class Application extends BaseApplication
{
    /**
     * Constructor.
     */
    public function __construct()
    {
        error_reporting(-1);

        parent::__construct('AcTask', '0.1.0');
    }

    /**
     * Return long version.
     *
     * @return the version info for the application.
     */
    public function getLongVersion()
    {
        return parent::getLongVersion().' by <comment>Kosta Harlan</comment>';
    }
}
