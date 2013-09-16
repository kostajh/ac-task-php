activeCollab Console 
================

A command-line interface for activeCollab 2.x.

## Installation

1. Clone this repo.
2. `cd /path/to/ac-console`
3. `curl http://getcomposer.org/installer | php`
4. `php composer.phar install`
5. `ln -s /path/to/ac-console/ac-console /usr/local/bin/acc`

## Usage

Type `acc list` for a list of commands.

### Available commands

`info`         Display API information about the current activeCollab instance
  
`task-info`    Display information about a specific ticket.
  
`user-tasks`   List tasks for the authenticating user.

## Configuration

Your `~/.active_collab` file contains information for connecting with AC.

The file configuration is in YAML format. It should look something like:

    ac_url: https://{site_url}/api.php
    ac_token: {token}
    projects:
      10: Project I care about
      25: Another project

Note that the `projects` section is optional.

Obtain your `ac_token` by logging into activeCollab and navigating to Profile > API Settings.
