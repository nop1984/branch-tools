<?php
/**
 * CleanupBranchCommand - Clean branch-specific files before merge
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TestrailTools\Service\GitService;

class CleanupBranchCommand extends Command
{
    protected static $defaultName = 'cleanup-branch';
    protected static $defaultDescription = 'Restore workflow files and build.txt to parent branch state before merge';
    
    private $gitService;
    private $repoPath;
    
    protected function configure(): void
    {
        $this
            ->setName('cleanup-branch')
            ->setDescription('Restore workflow YAML files and build.txt to parent branch state')
            ->setHelp(
                'This command restores branch-specific files (workflow YAMLs and build.txt) ' .
                'to their parent branch state, then commits and pushes the changes. ' .
                'This keeps the parent branch clean from unnecessary branch-specific modifications ' .
                'when merging. The commit and push bypass hooks using --no-verify.'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Detect repository
            $this->repoPath = GitService::detectParentRepository($output);
            $this->gitService = new GitService($this->repoPath);
            
            $io->title('Branch Cleanup - Restore Files to Parent Branch State');
            
            // Get current branch
            $currentBranch = $this->gitService->getCurrentBranch();
            if (!$currentBranch) {
                $io->error('Could not detect current branch');
                return Command::FAILURE;
            }
            
            // Detect parent branch (origin branch)
            try {
                $detectionMethod = null;
                $detectionLine = null;
                $parentBranch = $this->gitService->getOriginBranch($currentBranch, $detectionMethod, $detectionLine);
            } catch (\Exception $e) {
                $io->error('Could not detect parent branch: ' . $e->getMessage());
                return Command::FAILURE;
            }
            
            $io->text([
                "Current branch: <info>{$currentBranch}</info>",
                "Parent branch: <info>{$parentBranch}</info>",
            ]);
            $io->newLine();
            
            // Check if files need restoration
            $filesToRestore = [
                '.github/workflows/set-gizmo-branch-var.yml',
                '.github/workflows/ci-build-with-kiuwan-analysis.yml',
                'build.txt'
            ];
            
            $changedFiles = [];
            foreach ($filesToRestore as $file) {
                $result = $this->checkIfFileDiffers($file, $parentBranch);
                if ($result) {
                    $changedFiles[] = $file;
                }
            }
            
            if (empty($changedFiles)) {
                $io->success('All files are already in sync with parent branch. Nothing to do!');
                return Command::SUCCESS;
            }
            
            $io->section('Files to restore:');
            $io->listing($changedFiles);
            $io->newLine();
            
            // Restore files from parent branch
            $io->text('Restoring files from parent branch...');
            foreach ($changedFiles as $file) {
                $restoreCmd = sprintf(
                    'git -C %s checkout origin/%s -- %s 2>&1',
                    escapeshellarg($this->repoPath),
                    escapeshellarg($parentBranch),
                    escapeshellarg($file)
                );
                
                exec($restoreCmd, $restoreOutput, $returnCode);
                
                if ($returnCode !== 0) {
                    $io->error("Failed to restore {$file}: " . implode("\n", $restoreOutput));
                    return Command::FAILURE;
                }
                
                $io->text("  ✓ Restored: {$file}");
            }
            
            $io->newLine();
            
            // Commit changes
            $io->text('Committing changes...');
            $commitMessage = "chore: restore workflow files and build.txt to parent branch state\n\nPrepare branch for merge by reverting branch-specific changes\nto workflow YAML files and build number to match parent branch.";
            
            $commitCmd = sprintf(
                'git -C %s commit --no-verify -m %s 2>&1',
                escapeshellarg($this->repoPath),
                escapeshellarg($commitMessage)
            );
            
            exec($commitCmd, $commitOutput, $returnCode);
            
            if ($returnCode !== 0) {
                $io->error('Failed to commit changes: ' . implode("\n", $commitOutput));
                return Command::FAILURE;
            }
            
            $io->success('Changes committed successfully (hooks bypassed)');
            $io->newLine();
            
            // Push changes
            $io->text('Pushing changes to remote...');
            
            $pushCmd = sprintf(
                'git -C %s push --no-verify 2>&1',
                escapeshellarg($this->repoPath)
            );
            
            exec($pushCmd, $pushOutput, $returnCode);
            
            if ($returnCode !== 0) {
                $io->error('Failed to push changes: ' . implode("\n", $pushOutput));
                $io->text('You can manually push with: git push --no-verify');
                return Command::FAILURE;
            }
            
            $io->success('Changes pushed successfully!');
            $io->newLine();
            
            $io->block([
                '✓ Branch cleanup complete!',
                '',
                "Your branch is now ready to merge into {$parentBranch}.",
                'The workflow files and build.txt have been restored to match the parent branch.',
            ], null, 'fg=green', '  ', true);
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    /**
     * Check if a file differs from parent branch version
     */
    private function checkIfFileDiffers(string $file, string $parentBranch): bool
    {
        $diffCmd = sprintf(
            'git -C %s diff --quiet origin/%s -- %s 2>&1',
            escapeshellarg($this->repoPath),
            escapeshellarg($parentBranch),
            escapeshellarg($file)
        );
        
        exec($diffCmd, $output, $returnCode);
        
        // git diff --quiet returns 1 if there are differences, 0 if identical
        return $returnCode !== 0;
    }
}
