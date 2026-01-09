<?php

namespace TestrailTools\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use TestrailTools\Service\GitService;
use TestrailTools\Service\BuildNumberService;
use TestrailTools\Service\WorkflowService;

/**
 * Command to prepare repository for commit (update workflows and build number)
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class PrepareCommitCommand extends Command
{
    private $gitService;
    private $buildNumberService;
    private $workflowService;
    private $currentBranch;
    private $detectionMethod;
    private $detectionLine;
    
    protected function configure()
    {
        $this
            ->setName('prepare-commit')
            ->setDescription('Prepare repository for commit: update workflows and build number')
            ->addArgument('repo', InputArgument::OPTIONAL, 'Path to git repository', null)
            ->addOption('auto', 'a', InputOption::VALUE_NONE, 'Auto mode: auto-increment build and update workflows without prompts')
            ->addOption('skip-workflows', null, InputOption::VALUE_NONE, 'Skip workflow file updates')
            ->addOption('skip-build', null, InputOption::VALUE_NONE, 'Skip build number updates')
            ->setHelp('This command prepares the repository for commit by updating workflow files and build numbers. Suitable for git pre-commit hooks.');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("=== Prepare Commit ===");
        $output->writeln("");
        
        $autoMode = $input->getOption('auto');
        $skipWorkflows = $input->getOption('skip-workflows');
        $skipBuild = $input->getOption('skip-build');
        
        try {
            $repoPath = $input->getArgument('repo');
            if ($repoPath === null) {
                $repoPath = GitService::detectParentRepository($output);
            }
            
            // Initialize services
            $this->gitService = new GitService();
            $repoRoot = $this->gitService->validateAndGetRepoRoot($repoPath, $output);
            $this->buildNumberService = new BuildNumberService($this->gitService);
            $this->workflowService = new WorkflowService($repoRoot);
            
            $this->currentBranch = $this->gitService->getCurrentBranch();
            $output->writeln("Current branch: <info>{$this->currentBranch}</info>");
            
            // Check if there are any staged changes
            $hasStagedChanges = $this->gitService->hasStagedChanges();
            
            if (!$hasStagedChanges) {
                $output->writeln("<comment>⊘ No staged changes detected - skipping all updates</comment>");
                $output->writeln("");
                $output->writeln("=== Summary ===");
                $output->writeln("<info>✓ Empty commit - no preparation needed</info>");
                return Command::SUCCESS;
            }
            
            $output->writeln("");
            
            $workflowsUpdated = false;
            $buildUpdated = false;
            
            // Step 1: Update workflow files
            if (!$skipWorkflows && !in_array($this->currentBranch, ['develop', 'main', 'master'])) {
                $workflowsUpdated = $this->updateWorkflows($input, $output, $autoMode);
            } elseif (in_array($this->currentBranch, ['develop', 'main', 'master'])) {
                $output->writeln("<comment>⊘ Skipping workflow updates (main branch)</comment>");
                $output->writeln("");
            }
            
            // Step 2: Update build number
            if (!$skipBuild) {
                $buildUpdated = $this->updateBuildNumber($input, $output, $autoMode);
            }
            
            // Summary
            $output->writeln("");
            $output->writeln("=== Summary ===");
            
            if ($workflowsUpdated || $buildUpdated) {
                $output->writeln("<info>✓ Repository prepared for commit</info>");
                if ($workflowsUpdated) {
                    $output->writeln("  • Workflow files updated");
                }
                if ($buildUpdated) {
                    $output->writeln("  • Build number updated");
                }
                $output->writeln("");
                $output->writeln("<comment>Modified files will be automatically staged for commit.</comment>");
            } else {
                $output->writeln("<info>✓ No changes needed</info>");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>✗ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
    
    private function updateWorkflows(InputInterface $input, OutputInterface $output, $autoMode)
    {
        $output->writeln("<comment>→ Checking workflow files...</comment>");
        
        try {
            $originBranch = $this->gitService->getOriginBranch($this->currentBranch, $this->detectionMethod, $this->detectionLine);
            $output->writeln("  Origin branch: <info>{$originBranch}</info>");
            $output->writeln("  Detection: {$this->detectionMethod}");
            
            $gizmoUpdated = $this->workflowService->updateGizmoWorkflow($this->currentBranch, $originBranch);
            $kiuwanUpdated = $this->workflowService->updateKiuwanWorkflow($this->currentBranch);
            
            if ($gizmoUpdated || $kiuwanUpdated) {
                $output->writeln("<info>✓ Workflow files updated</info>");
                $output->writeln("");
                return true;
            } else {
                $output->writeln("<info>✓ Workflow files already up to date</info>");
                $output->writeln("");
                return false;
            }
            
        } catch (\Exception $e) {
            if (!$autoMode) {
                $output->writeln("<error>✗ Workflow update failed: " . $e->getMessage() . "</error>");
                $output->writeln("");
            }
            return false;
        }
    }
    
    private function updateBuildNumber(InputInterface $input, OutputInterface $output, $autoMode)
    {
        $output->writeln("<comment>→ Checking build number...</comment>");
        
        $localBuildNumber = $this->gitService->getLocalBuildTxt();
        
        if ($localBuildNumber === null) {
            $output->writeln("  No build.txt found");
            $output->writeln("");
            return false;
        }
        
        // Check remote
        $remoteBuildNumber = $this->gitService->getRemoteBuildTxt($this->currentBranch);
        
        if ($remoteBuildNumber === null) {
            $output->writeln("  No remote build.txt found");
            $output->writeln("  Local build: {$localBuildNumber}");
            $output->writeln("");
            
            // Always scan for available build numbers (even in auto mode)
            $output->writeln("<comment>→ Scanning for available build numbers...</comment>");
            $buildData = $this->buildNumberService->collectBuildData($output);
            
            if (!empty($buildData)) {
                $suggestions = $this->buildNumberService->suggestBuildNumbers($buildData, $this->currentBranch, $localBuildNumber);
                
                if (!empty($suggestions)) {
                    $output->writeln("");
                    $output->writeln("<info>Available build numbers (gaps):</info>");
                    
                    foreach (array_slice($suggestions, 0, 5) as $idx => $suggestion) {
                        $output->writeln(sprintf(
                            "  %d. <info>%d</info> (gap: %d, between %d and %d)",
                            $idx + 1,
                            $suggestion['suggested'],
                            $suggestion['gap'],
                            $suggestion['after'],
                            $suggestion['before']
                        ));
                    }
                    
                    $output->writeln("");
                    
                    // Extract suggested numbers
                    $suggestedNumbers = array_column($suggestions, 'suggested');
                    $newBuildNumber = null;

                    if ($autoMode) {
                        // Auto-select logic: try suggestions first
                        foreach ($suggestions as $suggestion) {
                             $candidate = $suggestion['suggested'];
                             $takenBranch = $this->buildNumberService->isBuildNumberTaken($candidate, $output);
                             
                             if (!$takenBranch) {
                                 $newBuildNumber = $candidate;
                                 break;
                             } else {
                                 $output->writeln("<comment>Auto-choice {$candidate} taken by {$takenBranch}, trying next...</comment>");
                             }
                        }
                        
                        // Fallback to sequential if all suggestions taken
                        if ($newBuildNumber === null) {
                             $output->writeln("<comment>All gaps taken. Falling back to sequential.</comment>");
                             $newBuildNumber = $this->buildNumberService->findNextAvailableBuildNumber($localBuildNumber + 1, $output);
                        }
                        
                        $output->writeln("<info>✓ Auto-selected build number {$newBuildNumber}</info>");
                    } else {
                        // Interactive logic
                        $helper = $this->getHelper('question');
                        
                        // Keep asking until user enters valid number or keeps current
                        while ($newBuildNumber === null) {
                            $question = new \Symfony\Component\Console\Question\Question("  Enter build number to claim (or press Enter to keep {$localBuildNumber}): ", $localBuildNumber);
                            
                            $answer = $helper->ask($input, $output, $question);
                            $chosenNumber = (int)$answer;
                            
                            // If user keeps current number, break
                            if ($chosenNumber === $localBuildNumber) {
                                $newBuildNumber = $localBuildNumber;
                                break;
                            }
                            
                            // Validate that chosen number is from suggested list
                            if (!in_array($chosenNumber, $suggestedNumbers)) {
                                $output->writeln("<error>✗ Build number {$chosenNumber} is not in the suggested list. Please choose from the suggested gaps.</error>");
                                $output->writeln("");
                                continue;
                            }
                            
                            // Double-check if the number is still available
                            $takenBranch = $this->buildNumberService->isBuildNumberTaken($chosenNumber, $output);
                            
                            if ($takenBranch) {
                                $output->writeln("<error>✗ Build number {$chosenNumber} is already taken by {$takenBranch}</error>");
                                $output->writeln("");
                                continue;
                            }
                            
                            $newBuildNumber = $chosenNumber;
                        }
                    }
                    
                    if ($newBuildNumber !== null && $newBuildNumber !== $localBuildNumber) {
                        // Update build.txt
                        file_put_contents('build.txt', $newBuildNumber);
                        $output->writeln("<info>✓ Build number updated to {$newBuildNumber}</info>");
                        $output->writeln("");
                        return true;
                    }
                } else {
                    $output->writeln("<comment>No gaps found. Using sequential build number.</comment>");
                    $newBuildNumber = $this->buildNumberService->findNextAvailableBuildNumber($localBuildNumber + 1, $output);
                    
                    if ($autoMode) {
                        file_put_contents('build.txt', $newBuildNumber);
                        $output->writeln("<info>✓ Build number updated to {$newBuildNumber}</info>");
                        $output->writeln("");
                        return true; 
                    }
                    
                    $helper = $this->getHelper('question');
                    $question = new \Symfony\Component\Console\Question\ConfirmationQuestion("  Use build number {$newBuildNumber}? (y/n): ", false);
                    
                    if ($helper->ask($input, $output, $question)) {
                        file_put_contents('build.txt', $newBuildNumber);
                        $output->writeln("<info>✓ Build number updated to {$newBuildNumber}</info>");
                        $output->writeln("");
                        return true;
                    }
                }
            }
            
            $output->writeln("");
            return false;
        }
        
        // Check if behind
        if ($remoteBuildNumber > $localBuildNumber) {
            $output->writeln("<error>  ⚠️  Local build ({$localBuildNumber}) is behind remote ({$remoteBuildNumber})</error>");
            
            if ($autoMode) {
                throw new \Exception("Cannot auto-update: local build is behind remote. Please pull changes first.");
            }
            
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('  Pull changes from remote? (y/n): ', false);
            
            if ($helper->ask($input, $output, $question)) {
                passthru("git pull origin {$this->currentBranch}", $returnCode);
                if ($returnCode !== 0) {
                    throw new \Exception("Failed to pull changes");
                }
                $output->writeln("<info>✓ Changes pulled</info>");
                $output->writeln("");
                return false;
            } else {
                throw new \Exception("Build number is behind remote. Cannot proceed.");
            }
        }
        
        // Check if ahead
        if ($localBuildNumber > $remoteBuildNumber) {
            $output->writeln("  Local build ({$localBuildNumber}) is ahead of remote ({$remoteBuildNumber})");
            $output->writeln("<info>✓ Build number is ready</info>");
            $output->writeln("");
            return false;
        }
        
        // Equal - need to increment
        $output->writeln("  Current build: {$localBuildNumber}");
        
        if (!$autoMode) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("  Auto-increment to " . ($localBuildNumber + 1) . "? (y/n): ", false);
            
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("<info>✓ Build number not changed</info>");
                $output->writeln("");
                return false;
            }
        }
        
        $newBuildNumber = $localBuildNumber + 1;
        
        // Validate the new number
        $output->writeln("  Validating build number {$newBuildNumber}...");
        $takenBy = $this->buildNumberService->isBuildNumberTaken($newBuildNumber, $output);
        
        if ($takenBy !== false) {
            $output->writeln("<error>  ⚠️  Build {$newBuildNumber} is taken by: {$takenBy}</error>");
            
            $nextAvailable = $this->buildNumberService->findNextAvailableBuildNumber($newBuildNumber + 1, $output);
            
            if (!$autoMode) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion("  Use {$nextAvailable} instead? (y/n): ", true);
                
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln("<info>✓ Build number not changed</info>");
                    $output->writeln("");
                    return false;
                }
            }
            
            $newBuildNumber = $nextAvailable;
        }
        
        // Write the new build number
        $this->gitService->writeBuildTxt($newBuildNumber);
        
        $output->writeln("<info>✓ Build number updated to {$newBuildNumber}</info>");
        $output->writeln("");
        
        return true;
    }
}
