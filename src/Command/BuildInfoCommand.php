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

/**
 * Command to collect and analyze build.txt values from remote branches
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class BuildInfoCommand extends Command
{
    const MIN_BUILD_NUMBER = 5000;
    
    private $gitService;
    private $buildNumberService;
    private $buildData = [];
    private $output;
    
    protected function configure()
    {
        $this
            ->setName('build:info')
            ->setDescription('Collect and analyze build.txt values from remote branches')
            ->addArgument('repo', InputArgument::OPTIONAL, 'Path to git repository', null)
            ->addOption('remote', 'r', InputOption::VALUE_OPTIONAL, 'Remote name', 'origin')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output format (table, json, csv, list, suggest)', 'table')
            ->addOption('scan-all', 's', InputOption::VALUE_NONE, 'Scan all remote branches (default: only current branch)')
            ->setHelp('This command checks build.txt value for the current branch. Use --scan-all to collect build.txt from all remote branches and find gaps.');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $outputFormat = $input->getOption('output');
        
        try {
            $repoPath = $input->getArgument('repo');
            if ($repoPath === null) {
                $repoPath = GitService::detectParentRepository($output);
            }
            
            // Initialize services
            $this->gitService = new GitService(null, $input->getOption('remote'));
            $repoRoot = $this->gitService->validateAndGetRepoRoot($repoPath, $output);
            $this->buildNumberService = new BuildNumberService($this->gitService);
            
            // Check if local branch needs to pull changes
            if (!$this->checkLocalVsRemoteBuild($input, $output)) {
                return Command::FAILURE;
            }
            
            $scanAll = $input->getOption('scan-all');
            
            // Auto-enable scan-all for formats that require it
            if (in_array($outputFormat, ['json', 'csv', 'list', 'suggest'])) {
                $scanAll = true;
            }
            
            // Only scan all branches if requested
            if ($scanAll) {
                // Collect build info from all branches
                $this->buildData = $this->buildNumberService->collectBuildData($output);
                
                // Output based on format
                switch ($outputFormat) {
                    case 'json':
                        $this->outputJson($output);
                        break;
                    case 'csv':
                        $this->outputCsv($output);
                        break;
                    case 'list':
                        $this->outputList($output);
                        break;
                    case 'suggest':
                        $this->suggestBuildNumbers($output);
                        break;
                    case 'table':
                    default:
                        $this->outputTable($output);
                        break;
                }
            } else {
                $output->writeln("");
                $output->writeln("<info>✓ Current branch check complete.</info>");
                $output->writeln("<comment>Use --scan-all to scan all remote branches and find build number gaps.</comment>");
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>✗ Error: " . $e->getMessage() . "</error>");
            return Command::FAILURE;
        }
    }
    
    /**
     * Calculate neighbors for each build number
     * Returns array with 'left' and 'right' neighbors and gaps
     */
    private function calculateNeighbors()
    {
        $buildNumbers = [];
        $buildToBranch = [];
        
        // buildData is [branch => buildNumber]
        foreach ($this->buildData as $branch => $buildNum) {
            $buildNumbers[$buildNum] = $branch;
            $buildToBranch[$buildNum] = $branch;
        }
        
        ksort($buildNumbers);
        $sorted = array_keys($buildNumbers);
        $neighbors = [];
        
        foreach ($sorted as $index => $buildNum) {
            $left = $index > 0 ? $sorted[$index - 1] : null;
            $right = $index < count($sorted) - 1 ? $sorted[$index + 1] : null;
            
            $neighbors[$buildNum] = [
                'left' => $left,
                'left_branch' => $left ? $buildNumbers[$left] : null,
                'left_gap' => $left ? ($buildNum - $left) : null,
                'right' => $right,
                'right_branch' => $right ? $buildNumbers[$right] : null,
                'right_gap' => $right ? ($right - $buildNum) : null,
            ];
        }
        
        return $neighbors;
    }
    
    private function checkLocalVsRemoteBuild(InputInterface $input, OutputInterface $output)
    {
        $currentBranch = $this->gitService->getCurrentBranch();
        
        if (!$currentBranch) {
            return true;
        }
        
        $localBuildNumber = $this->gitService->getLocalBuildTxt();
        
        if ($localBuildNumber === null) {
            return true;
        }
        
        // Check if remote branch exists
        $remoteBuildNumber = $this->gitService->getRemoteBuildTxt($currentBranch);
        
        if ($remoteBuildNumber === null) {
            $output->writeln("");
            $output->writeln("<info>ℹ️  No remote branch found or remote has no build.txt.</info>");
            $output->writeln("   Current branch: {$currentBranch}");
            $output->writeln("   Local build.txt: {$localBuildNumber}");
            $output->writeln("");
            return true;
        }
        
        // Compare local vs remote
        if ($remoteBuildNumber > $localBuildNumber) {
            $output->writeln("");
            $output->writeln("<error>⚠️  WARNING: Remote build.txt is newer!</error>");
            $output->writeln("   Current branch: {$currentBranch}");
            $output->writeln("   Local build.txt:  {$localBuildNumber}");
            $output->writeln("   Remote build.txt: {$remoteBuildNumber}");
            $output->writeln("");
            
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Your local branch is behind the remote. Pull changes? (y/n): ', false);
            
            if ($helper->ask($input, $output, $question)) {
                $output->writeln("");
                $output->writeln("Pulling changes...");
                passthru("git pull origin {$currentBranch}", $returnCode);
                
                if ($returnCode !== 0) {
                    throw new \Exception("Failed to pull changes from remote");
                }
                
                $output->writeln("");
                $output->writeln("<info>✓ Successfully pulled changes</info>");
                $output->writeln("");
                return true;
            } else {
                $output->writeln("");
                $output->writeln("<error>✗ Pull rejected. Cannot continue with outdated local branch.</error>");
                return false;
            }
        }
        
        // Local is ahead
        if ($localBuildNumber > $remoteBuildNumber) {
            $output->writeln("");
            $output->writeln("<info>✓ Local build.txt is ahead of remote</info>");
            $output->writeln("   Current branch: {$currentBranch}");
            $output->writeln("   Local build.txt:  {$localBuildNumber}");
            $output->writeln("   Remote build.txt: {$remoteBuildNumber}");
            $output->writeln("");
            $output->writeln("Proceeding with current local build number ({$localBuildNumber})...");
            $output->writeln("");
            return true;
        }
        
        // Local equals remote - offer to autoincrement
        $output->writeln("");
        $output->writeln("<info>✓ Local build.txt is up to date</info>");
        $output->writeln("   Current branch: {$currentBranch}");
        $output->writeln("   Local build.txt:  {$localBuildNumber}");
        $output->writeln("   Remote build.txt: {$remoteBuildNumber}");
        $output->writeln("");
        
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion("Do you want to auto-increment the build number to " . ($localBuildNumber + 1) . "? (y/n): ", false);
        
        if ($helper->ask($input, $output, $question)) {
            $newBuildNumber = $localBuildNumber + 1;
            
            // Double-check if the new build number is taken
            $takenBy = $this->buildNumberService->isBuildNumberTaken($newBuildNumber, $output);
            
            if ($takenBy !== false) {
                $output->writeln("");
                $output->writeln("<error>Cannot use build number {$newBuildNumber} - it's already taken by: {$takenBy}</error>");
                
                // Find next available
                $nextAvailable = $this->buildNumberService->findNextAvailableBuildNumber($newBuildNumber + 1, $output);
                
                $output->writeln("");
                $question = new ConfirmationQuestion("Use build number {$nextAvailable} instead? (y/n): ", false);
                
                if ($helper->ask($input, $output, $question)) {
                    $newBuildNumber = $nextAvailable;
                } else {
                    $output->writeln("");
                    $output->writeln("<info>✓ Build number not changed</info>");
                    $output->writeln("");
                    return true;
                }
            }
            
            $this->gitService->writeBuildTxt($newBuildNumber);
            
            $output->writeln("");
            $output->writeln("<info>✓ Successfully updated build number to {$newBuildNumber}</info>");
            $output->writeln("   Updated: " . $this->gitService->getRepoRoot() . "/build.txt");
            $output->writeln("");
        } else {
            $output->writeln("");
            $output->writeln("<info>✓ Build number not changed</info>");
            $output->writeln("");
        }
        
        return true;
    }
    
    private function outputTable(OutputInterface $output)
    {
        if (empty($this->buildData)) {
            $output->writeln("No build.txt files found in any branch.");
            return;
        }
        
        $neighbors = $this->calculateNeighbors();
        
        // buildData is [branch => buildNumber]
        $branchLengths = array_map('strlen', array_keys($this->buildData));
        $maxBranchLen = !empty($branchLengths) ? max($branchLengths) : 0;
        $maxBranchLen = max($maxBranchLen, strlen('Branch'));
        
        $separator = '+' . str_repeat('-', $maxBranchLen + 2) . '+' . str_repeat('-', 12) . '+' . str_repeat('-', 25) . '+' . str_repeat('-', 25) . '+';
        $output->writeln($separator);
        $output->writeln('| ' . str_pad('Branch', $maxBranchLen) . ' | ' . str_pad('Build', 10) . ' | ' . str_pad('Left Neighbor', 23) . ' | ' . str_pad('Right Neighbor', 23) . " |");
        $output->writeln($separator);
        
        foreach ($this->buildData as $branch => $buildNum) {
            $leftInfo = 'N/A';
            $rightInfo = 'N/A';
            
            if (isset($neighbors[$buildNum])) {
                $n = $neighbors[$buildNum];
                if ($n['left'] !== null) {
                    $leftInfo = $n['left'] . ' (-' . $n['left_gap'] . ')';
                }
                if ($n['right'] !== null) {
                    $rightInfo = $n['right'] . ' (+' . $n['right_gap'] . ')';
                }
            }
            
            $output->writeln('| ' . str_pad($branch, $maxBranchLen) . ' | ' . str_pad($buildNum, 10) . ' | ' . str_pad($leftInfo, 23) . ' | ' . str_pad($rightInfo, 23) . " |");
        }
        
        $output->writeln($separator);
    }
    
    private function outputJson(OutputInterface $output)
    {
        $neighbors = $this->calculateNeighbors();
        $jsonData = [];
        
        // buildData is [branch => buildNumber]
        foreach ($this->buildData as $branch => $buildNum) {
            $item = [
                'branch' => $branch,
                'build_number' => $buildNum,
                'left_neighbor' => null,
                'right_neighbor' => null,
            ];
            
            if (isset($neighbors[$buildNum])) {
                $n = $neighbors[$buildNum];
                if ($n['left'] !== null) {
                    $item['left_neighbor'] = [
                        'build_number' => $n['left'],
                        'branch' => $n['left_branch'],
                        'gap' => $n['left_gap']
                    ];
                }
                if ($n['right'] !== null) {
                    $item['right_neighbor'] = [
                        'build_number' => $n['right'],
                        'branch' => $n['right_branch'],
                        'gap' => $n['right_gap']
                    ];
                }
            }
            
            $jsonData[] = $item;
        }
        
        $output->writeln(json_encode($jsonData, JSON_PRETTY_PRINT));
    }
    
    private function outputCsv(OutputInterface $output)
    {
        $neighbors = $this->calculateNeighbors();
        $output->writeln("Branch,Build Number,Left Neighbor,Left Gap,Right Neighbor,Right Gap");
        
        // buildData is [branch => buildNumber]
        foreach ($this->buildData as $branch => $buildNum) {
            $leftNeighbor = 'N/A';
            $leftGap = 'N/A';
            $rightNeighbor = 'N/A';
            $rightGap = 'N/A';
            
            if (isset($neighbors[$buildNum])) {
                $n = $neighbors[$buildNum];
                if ($n['left'] !== null) {
                    $leftNeighbor = $n['left'];
                    $leftGap = $n['left_gap'];
                }
                if ($n['right'] !== null) {
                    $rightNeighbor = $n['right'];
                    $rightGap = $n['right_gap'];
                }
            }
            
            $output->writeln('"' . $branch . '",' . $buildNum . ',' . $leftNeighbor . ',' . $leftGap . ',' . $rightNeighbor . ',' . $rightGap);
        }
    }
    
    private function outputList(OutputInterface $output)
    {
        $neighbors = $this->calculateNeighbors();
        
        // buildData is [branch => buildNumber]
        foreach ($this->buildData as $branch => $buildNum) {
            $output->writeln("Branch: {$branch}");
            $output->writeln(str_repeat('-', 70));
            $output->writeln("Build Number: {$buildNum}");
            
            if (isset($neighbors[$buildNum])) {
                $n = $neighbors[$buildNum];
                if ($n['left'] !== null) {
                    $output->writeln("Left Neighbor: {$n['left']} (branch: {$n['left_branch']}, gap: {$n['left_gap']})");
                } else {
                    $output->writeln("Left Neighbor: N/A");
                }
                
                if ($n['right'] !== null) {
                    $output->writeln("Right Neighbor: {$n['right']} (branch: {$n['right_branch']}, gap: {$n['right_gap']})");
                } else {
                    $output->writeln("Right Neighbor: N/A");
                }
            }
            
            $output->writeln("");
        }
    }
    
    private function suggestBuildNumbers(OutputInterface $output)
    {
        if (empty($this->buildData)) {
            $output->writeln("No build numbers found.");
            return;
        }
        
        $buildNumbers = [];
        $buildToBranch = [];
        foreach ($this->buildData as $branch => $buildNum) {
            $buildNum = (int)trim($buildNum);
            $buildNumbers[] = $buildNum;
            if (!isset($buildToBranch[$buildNum])) {
                $buildToBranch[$buildNum] = $branch;
            }
        }
        sort($buildNumbers);
        
        $currentBranch = $this->gitService->getCurrentBranch();
        $currentBranchBuildNumber = null;
        if ($currentBranch && isset($this->buildData[$currentBranch])) {
            $currentBranchBuildNumber = (int)trim($this->buildData[$currentBranch]);
        }
        
        $output->writeln("=== Available Build Number Suggestions ===");
        $output->writeln("");
        
        if ($currentBranchBuildNumber !== null) {
            $output->writeln("Current branch: {$currentBranch}");
            $output->writeln("Current build number: {$currentBranchBuildNumber}");
            $output->writeln("");
            $output->writeln("<info>📍 RECOMMENDED: " . ($currentBranchBuildNumber + 1) . " (current branch increment)</info>");
            $output->writeln("");
        }
        
        $output->writeln("Looking for gaps of at least 20 between existing build numbers...");
        $output->writeln("");
        
        $suggestions = [];
        
        for ($i = 0; $i < count($buildNumbers); $i++) {
            $current = $buildNumbers[$i];
            $next = isset($buildNumbers[$i + 1]) ? $buildNumbers[$i + 1] : PHP_INT_MAX;
            
            $suggested = $current + 1;
            $suggested = ceil($suggested / 10) * 10;
            
            $requiredNext = $suggested + 20;
            
            if ($next >= $requiredNext) {
                $gap = $next - $suggested;
                $suggestions[] = [
                    'number' => $suggested,
                    'after' => $current,
                    'after_branch' => $buildToBranch[$current],
                    'before' => $next == PHP_INT_MAX ? 'END' : $next,
                    'before_branch' => $next == PHP_INT_MAX ? '' : $buildToBranch[$next],
                    'gap' => $gap
                ];
            }
        }
        
        if (empty($suggestions)) {
            $output->writeln("No available build numbers found with required gap of 20.");
            return;
        }
        
        $output->writeln("Found " . count($suggestions) . " available build numbers:");
        $output->writeln("");
        
        $output->writeln("+------------+--------------+--------------+---------+");
        $output->writeln("| Suggested  | After        | Before       | Gap     |");
        $output->writeln("+------------+--------------+--------------+---------+");
        
        foreach ($suggestions as $s) {
            $output->writeln(sprintf("| %-10s | %-12s | %-12s | %-7s |",
                $s['number'],
                $s['after'],
                $s['before'],
                $s['gap']
            ));
        }
        
        $output->writeln("+------------+--------------+--------------+---------+");
        
        $output->writeln("");
        $output->writeln("Top 5 Recommendations (lowest numbers):");
        if ($currentBranchBuildNumber !== null) {
            $output->writeln("<info>  💡 BEST: " . ($currentBranchBuildNumber + 1) . " (increment from current branch '{$currentBranch}' with build {$currentBranchBuildNumber})</info>");
            $output->writeln("");
            $output->writeln("Alternative options:");
        }
        for ($i = 0; $i < min(5, count($suggestions)); $i++) {
            $s = $suggestions[$i];
            $beforeInfo = $s['before'] === 'END' ? 'END' : "{$s['before_branch']} {$s['before']}";
            $output->writeln("  " . ($i + 1) . ". " . $s['number'] . " (gap of " . $s['gap'] . " from {$s['after_branch']} {$s['after']} to {$beforeInfo})");
        }
    }
}
