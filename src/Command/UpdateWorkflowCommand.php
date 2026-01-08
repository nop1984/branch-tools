<?php

namespace TestrailTools\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TestrailTools\Service\GitService;
use TestrailTools\Service\WorkflowService;

class UpdateWorkflowCommand extends Command
{
    private $gitService;
    private $workflowService;
    private $currentBranch;
    private $originBranch;
    private $detectionMethod;
    private $detectionLine;
    
    protected function configure()
    {
        $this
            ->setName('workflow:update')
            ->setDescription('Update GitHub workflow files with current branch')
            ->addArgument('repo', InputArgument::OPTIONAL, 'Path to git repository', null)
            ->setHelp('This command detects the current branch, determines its origin, and updates workflow configuration files.');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("=== GitHub Workflow Branch Updater ===");
        $output->writeln("");
        
        try {
            $repoPath = $input->getArgument('repo');
            if ($repoPath === null) {
                $repoPath = dirname(dirname(__DIR__));
            }
            
            // Initialize services
            $this->gitService = new GitService();
            $repoRoot = $this->gitService->validateAndGetRepoRoot($repoPath, $output);
            $this->workflowService = new WorkflowService($repoRoot);
            
            // Get current branch
            $this->currentBranch = $this->gitService->getCurrentBranch();
            $output->writeln("Current branch: {$this->currentBranch}");
            
            // Check if it's a main branch
            if (in_array($this->currentBranch, ['develop', 'main', 'master'])) {
                $output->writeln("<error>⚠ Warning: Current branch is a main branch. Skipping update.</error>");
                return Command::FAILURE;
            }
            
            // Determine origin branch
            $this->originBranch = $this->gitService->getOriginBranch($this->currentBranch, $this->detectionMethod, $this->detectionLine);
            $output->writeln("Origin branch: {$this->originBranch}");
            $output->writeln("Detection method: {$this->detectionMethod}");
            $output->writeln("Detection info: {$this->detectionLine}");
            $output->writeln("");
            
            // Update both workflow files
            $gizmoUpdated = $this->workflowService->updateGizmoWorkflow($this->currentBranch, $this->originBranch);
            $kiuwanUpdated = $this->workflowService->updateKiuwanWorkflow($this->currentBranch);
            
            if ($gizmoUpdated || $kiuwanUpdated) {
                $output->writeln("");
                $output->writeln("<info>✓ Workflow files updated successfully!</info>");
                $output->writeln("");
                $output->writeln("Next steps:");
                $output->writeln("1. Review the changes: git diff");
                $output->writeln("2. Commit the changes: git add .github/workflows/*.yml && git commit -m 'Update workflows for {$this->currentBranch}'");
                $output->writeln("3. Push the changes: git push");
            } else {
                $output->writeln("");
                $output->writeln("<info>✓ No updates needed - branch already exists in workflow files.</info>");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>✗ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
}
