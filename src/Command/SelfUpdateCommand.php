<?php
/**
 * SelfUpdateCommand - Check and install updates for branch-tools
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TestrailTools\Service\UpdateService;

class SelfUpdateCommand extends Command
{
    protected static $defaultName = 'self-update';
    protected static $defaultDescription = 'Update branch-tools to the latest version';
    
    protected function configure(): void
    {
        $this
            ->setName('self-update')
            ->setDescription('Check for updates and install the latest version')
            ->setHelp('This command checks GitHub releases and updates the tool to the latest version')
            ->addOption(
                'check',
                'c',
                InputOption::VALUE_NONE,
                'Only check for updates without installing'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force update even if already on latest version'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate the update without making changes'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Get current version from application
        $currentVersion = $this->getApplication()->getVersion();
        
        try {
            $updateService = new UpdateService($currentVersion);
            
            $io->title('Branch Tools Self-Update');
            $io->text('Current version: <info>' . $currentVersion . '</info>');
            $io->newLine();
            
            // Check for updates
            $io->text('Checking for updates...');
            $updateInfo = $updateService->checkForUpdate();
            
            if (!$updateInfo) {
                $io->error('Failed to fetch release information from GitHub');
                return Command::FAILURE;
            }
            
            $io->text('Latest remote version: <info>' . $updateInfo['version'] . '</info>');
            $io->newLine();
            
            // Check if update is available
            if (!$updateInfo['update_available']) {
                $io->success('You are already using the latest version!');
                return Command::SUCCESS;
            }
            
            // Display update information
            $io->section('Update Available');
            $io->text([
                'New version: <info>' . $updateInfo['version'] . '</info>',
                'Current version: <comment>' . $currentVersion . '</comment>',
                'Release URL: ' . $updateInfo['url'],
            ]);
            
            if (!empty($updateInfo['published_at'])) {
                $publishedDate = date('Y-m-d H:i:s', strtotime($updateInfo['published_at']));
                $io->text('Published: ' . $publishedDate);
            }
            
            if (!empty($updateInfo['body'])) {
                $io->newLine();
                $io->section('Release Notes');
                $io->text($updateInfo['body']);
            }
            
            // Check-only mode
            if ($input->getOption('check')) {
                if (!empty($updateInfo['composer_changed'])) {
                    $io->note([
                        'Note: This release includes dependency changes.',
                        'After updating, you will need to run: composer install',
                    ]);
                }
                $io->note('Use "self-update" without --check to install this update');
                return Command::SUCCESS;
            }
            
            $io->newLine();
            
            // Dry-run mode
            if ($input->getOption('dry-run')) {
                $io->note('Dry-run mode enabled - no actual changes will be made');
                $io->success('Update simulation completed successfully');
                return Command::SUCCESS;
            }
            
            // Confirm update
            if (!$input->getOption('force')) {
                if (!$io->confirm('Do you want to update to version ' . $updateInfo['version'] . '?', true)) {
                    $io->warning('Update cancelled');
                    return Command::SUCCESS;
                }
            }
            
            // Perform update
            $io->newLine();
            $io->section('Installing Update');
            $io->text('Downloading version ' . $updateInfo['tag'] . '...');
            
            $updateService->performUpdate($input->getOption('dry-run'));
            
            $successMessages = [
                'Successfully updated to version ' . $updateInfo['version'] . '!',
                'Please verify the installation with: ./branch-tools --version',
            ];
            
            // Check if composer.json changed and show hint
            if (!empty($updateInfo['composer_changed'])) {
                $io->success($successMessages);
                $io->newLine();
                $io->warning([
                    'Dependencies have changed in this release!',
                    'Please run: composer install',
                    'This will update required packages to ensure compatibility.',
                ]);
            } else {
                $io->success($successMessages);
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Update failed: ' . $e->getMessage());
            $io->note([
                'If you continue to experience issues:',
                '1. Check your internet connection',
                '2. Verify GitHub access: https://github.com/nop1984/branch-tools',
                '3. Manually download the latest release',
                '4. Check file permissions on the installation directory',
            ]);
            return Command::FAILURE;
        }
    }
}
