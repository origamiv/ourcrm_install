<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitMergeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'git:merge {project} {to_branch} {from_branch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Merge git branches in a specific project';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $project = $this->argument('project');
        $toBranch = $this->argument('to_branch');
        $fromBranch = $this->argument('from_branch');

        $basePath = config('app.projects_base_path', '/www/wwwroot');
        $projectPath = rtrim($basePath, '/') . '/' . $project . '.our24.ru';

        if (!is_dir($projectPath)) {
            $this->error("Project directory not found: $projectPath");
            return 1;
        }

        $this->info("Merging $fromBranch into $toBranch in $projectPath...");

        $commands = [
            "git stash",
            "git clean -fd",
            "git fetch origin",
            "git checkout $toBranch",
            "git pull origin $toBranch",
            "git merge origin/$fromBranch",
            "git push origin $toBranch"
        ];

        foreach ($commands as $cmd) {
            $this->line("Executing: $cmd");

            $fullCommand = "cd " . escapeshellarg($projectPath) . " && $cmd 2>&1";
            exec($fullCommand, $output, $resultCode);

            if ($resultCode !== 0) {
                $this->error("Command failed: $cmd");
                $this->error(implode("\n", $output));
                return 1;
            }

            $this->line(implode("\n", $output));
            $output = []; // Clear output for next command
        }

        $this->info("Successfully merged $fromBranch into $toBranch");
        return 0;
    }
}
