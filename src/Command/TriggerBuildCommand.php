<?php
/**
 * TriggerBuildCommand - Optionally trigger CI/CD build before push
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use TestrailTools\Service\GitService;

class TriggerBuildCommand extends Command
{
    protected static $defaultName = 'trigger-build';
    protected static $defaultDescription = 'Optionally trigger CI/CD build with an empty commit before push';
    
    // Constant for the trigger build commit message
    public const TRIGGER_BUILD_MESSAGE = '[ci_build] Trigger build';
    
    private $gitService;
    private $repoPath;
    
    protected function configure(): void
    {
        $this
            ->setName('trigger-build')
            ->setDescription('Ask user if they want to trigger a CI/CD build before pushing')
            ->setHelp('This command is designed to run as a pre-push hook. It checks if recent commits had changes and asks if the user wants to trigger a CI/CD build by adding an empty commit.')
            ->addOption(
                'auto',
                'a',
                InputOption::VALUE_NONE,
                'Skip prompt and automatically trigger build'
            )
            ->addOption(
                'skip',
                's',
                InputOption::VALUE_NONE,
                'Skip this check entirely (for hook bypass)'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Skip if requested
        if ($input->getOption('skip')) {
            return Command::SUCCESS;
        }
        
        try {
            // Detect repository
            $this->repoPath = GitService::detectParentRepository($output);
            $this->gitService = new GitService($this->repoPath);
            
            // Check if last commit was empty
            if ($this->isLastCommitEmpty()) {
                // Last commit was already empty (might be the trigger commit itself)
                return Command::SUCCESS;
            }
            
            // Check if we already have a trigger commit right after the last real commit
            if ($this->hasRecentTriggerCommit()) {
                // Already has trigger commit, no need to ask
                return Command::SUCCESS;
            }
            
            $io = new SymfonyStyle($input, $output);
            
            // Auto mode - just create trigger commit
            if ($input->getOption('auto')) {
                return $this->createTriggerCommit($io);
            }
            
            // Ask user if they want to trigger build
            $io->newLine();
            $io->text('� You are about to push commits with changes.');
            $io->newLine();
            
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'Would you like to trigger a CI/CD build?',
                ['No', 'Yes'],
                0  // Default to 'No'
            );
            $question->setErrorMessage('Choice %s is invalid.');
            
            $answer = $helper->ask($input, $output, $question);
            
            if ($answer === 'Yes') {
                return $this->createTriggerCommit($io);
            } else {
                $io->text('<comment>Skipping CI/CD trigger.</comment>');
                return Command::SUCCESS;
            }
            
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
    
    /**
     * Check if the last commit was empty (no file changes)
     */
    private function isLastCommitEmpty(): bool
    {
        // Get stats of last commit
        exec('git -C ' . escapeshellarg($this->repoPath) . ' show --stat HEAD 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return false;
        }
        
        $statsOutput = implode("\n", $output);
        
        // Empty commits show "0 files changed" or no file stats at all
        // Check if there's any "files changed" line
        if (preg_match('/(\d+) files? changed/', $statsOutput, $matches)) {
            $filesChanged = (int)$matches[1];
            return $filesChanged === 0;
        }
        
        // If no "files changed" found, it might be empty
        // Check if there are any diff stats (insertions/deletions)
        return !preg_match('/\d+ insertion|\\d+ deletion/', $statsOutput);
    }
    
    /**
     * Check if there's already a recent trigger commit
     */
    private function hasRecentTriggerCommit(): bool
    {
        // Get last commit message
        exec('git -C ' . escapeshellarg($this->repoPath) . ' log -1 --pretty=%B 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return false;
        }
        
        $lastMessage = trim(implode("\n", $output));
        
        // Check if last commit is already a trigger commit
        return strpos($lastMessage, self::TRIGGER_BUILD_MESSAGE) !== false;
    }
    
    /**
     * Create the trigger commit and schedule automatic push
     */
    private function createTriggerCommit(SymfonyStyle $io): int
    {
        $io->text('Creating trigger commit...');
        
        exec('git -C ' . escapeshellarg($this->repoPath) . ' commit --allow-empty -m ' . escapeshellarg(self::TRIGGER_BUILD_MESSAGE) . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $io->error('Failed to create trigger commit: ' . implode("\n", $output));
            return Command::FAILURE;
        }
        
        $io->success([
            'CI/CD trigger commit created!',
            'Commit message: ' . self::TRIGGER_BUILD_MESSAGE,
        ]);
        
        // Schedule push to run after hook exits (background process)
        $io->text('Scheduling automatic push...');
        
        // Build push command with upstream if needed
        // Use --no-verify to skip pre-push hook since we already created the trigger commit
        $currentBranch = exec('git -C ' . escapeshellarg($this->repoPath) . ' rev-parse --abbrev-ref HEAD 2>&1');
        $hasUpstream = exec('git -C ' . escapeshellarg($this->repoPath) . ' rev-parse --abbrev-ref @{upstream} 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            // No upstream, set it
            $gitPushCmd = 'git -C ' . escapeshellarg($this->repoPath) . ' push --no-verify --set-upstream origin ' . escapeshellarg($currentBranch);
        } else {
            // Has upstream, just push
            $gitPushCmd = 'git -C ' . escapeshellarg($this->repoPath) . ' push --no-verify';
        }
        
        GitService::scheduleAsyncCommand($gitPushCmd, 'Push completed! You still have to create MR/PR manually', 1);
        
        $io->newLine();
        $io->success('Automatic push scheduled!');
        $io->text('<comment>Note: The current push will be cancelled to allow the trigger commit to be pushed.</comment>');
        $io->text('<comment>The push will execute automatically in a moment...</comment>');
        
        // Return FAILURE to abort the original push (this is intentional)
        return Command::FAILURE;
    }
}
