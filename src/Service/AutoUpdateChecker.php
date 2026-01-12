<?php
/**
 * AutoUpdateChecker - Silently checks for updates before command execution
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Service;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;

class AutoUpdateChecker
{
    private UpdateService $updateService;
    private UpdateCheckTracker $tracker;
    
    public function __construct(string $currentVersion)
    {
        $this->updateService = new UpdateService($currentVersion);
        $this->tracker = new UpdateCheckTracker();
    }
    
    /**
     * Check for updates and prompt user if available
     * This runs silently before each command
     *
     * @return bool Returns true if command should continue, false to abort
     */
    public function checkAndPrompt(InputInterface $input, OutputInterface $output, QuestionHelper $questionHelper): bool
    {
        // Skip check if we shouldn't check today
        if (!$this->tracker->shouldCheckForUpdates()) {
            return true;
        }
        
        // Silently check for updates
        $updateInfo = $this->updateService->checkForUpdateSilently();
        
        // Mark that we checked (even if it failed or no update available)
        $this->tracker->markChecked();
        
        // If no update info or no update available, continue normally
        if (!$updateInfo || !$updateInfo['update_available']) {
            return true;
        }
        
        // New version available - prompt user
        return $this->promptForUpdate($input, $output, $questionHelper, $updateInfo);
    }
    
    /**
     * Prompt user with options to update or skip
     */
    private function promptForUpdate(
        InputInterface $input,
        OutputInterface $output,
        QuestionHelper $questionHelper,
        array $updateInfo
    ): bool {
        $output->writeln('');
        $output->writeln('<fg=yellow>╔════════════════════════════════════════════════════════════╗</>');
        $output->writeln('<fg=yellow>║</> <fg=cyan;options=bold>A new version of Branch Tools is available!</>              <fg=yellow>║</>');
        $output->writeln('<fg=yellow>╠════════════════════════════════════════════════════════════╣</>');
        $output->writeln(sprintf(
            '<fg=yellow>║</> Current version:  <comment>%-35s</comment> <fg=yellow>║</>',
            $this->updateService->getCurrentVersion()
        ));
        $output->writeln(sprintf(
            '<fg=yellow>║</> New version:      <info>%-35s</info> <fg=yellow>║</>',
            $updateInfo['version']
        ));
        $output->writeln('<fg=yellow>╚════════════════════════════════════════════════════════════╝</>');
        $output->writeln('');
        
        // Create choice question with arrow key navigation
        $question = new ChoiceQuestion(
            'What would you like to do?',
            [
                'update' => 'Update now',
                'skip' => 'Skip for today',
            ],
            'skip'
        );
        
        $question->setErrorMessage('Choice %s is invalid.');
        
        // Ask the question
        $choice = $questionHelper->ask($input, $output, $question);
        
        if ($choice === 'Update now') {
            return $this->performUpdate($output, $updateInfo);
        }
        
        // User chose to skip
        $this->tracker->skipUntilTomorrow();
        $output->writeln('<comment>Update skipped. You won\'t be reminded again today.</comment>');
        $output->writeln('');
        
        return true; // Continue with the command
    }
    
    /**
     * Perform the update
     */
    private function performUpdate(OutputInterface $output, array $updateInfo): bool
    {
        $output->writeln('');
        $output->writeln('<info>Downloading and installing version ' . $updateInfo['version'] . '...</info>');
        
        try {
            $this->updateService->performUpdate();
            
            $output->writeln('');
            $output->writeln('<fg=green>╔════════════════════════════════════════════════════════════╗</>');
            $output->writeln('<fg=green>║</> <fg=green;options=bold>Successfully updated to version ' . str_pad($updateInfo['version'], 20) . '</> <fg=green>║</>');
            $output->writeln('<fg=green>╠════════════════════════════════════════════════════════════╣</>');
            $output->writeln('<fg=green>║</> Resuming your command with the new version...          <fg=green>║</>');
            $output->writeln('<fg=green>╚════════════════════════════════════════════════════════════╝</>');
            $output->writeln('');
            
            // Re-execute the same command with updated version
            $this->reExecuteCurrentCommand();
            
            return false; // Stop current execution (new process started)
            
        } catch (\Exception $e) {
            $output->writeln('');
            $output->writeln('<error>Update failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Continuing with current version...</comment>');
            $output->writeln('');
            
            return true; // Continue with the command despite failure
        }
    }
    
    /**
     * Re-execute the current command after update
     */
    private function reExecuteCurrentCommand(): void
    {
        global $argv;
        
        // Get the path to the updated executable
        $executable = $this->updateService->getExecutablePath();
        
        // Re-run with the same arguments
        pcntl_exec($executable, array_slice($argv, 1));
        
        // Fallback if pcntl_exec is not available
        $command = escapeshellcmd($executable);
        foreach (array_slice($argv, 1) as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        
        passthru($command, $exitCode);
        exit($exitCode);
    }
}
