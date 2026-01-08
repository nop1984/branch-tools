<?php

namespace TestrailTools\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service for build number operations
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class BuildNumberService
{
    const MIN_BUILD_NUMBER = 5000;
    
    private $gitService;
    
    public function __construct(GitService $gitService)
    {
        $this->gitService = $gitService;
    }
    
    /**
     * Check if a build number is taken in any remote branch
     */
    public function isBuildNumberTaken($buildNumber, OutputInterface $output)
    {
        $output->writeln("");
        $output->writeln("<comment>🔍 Checking if build number {$buildNumber} is already taken...</comment>");
        
        $branches = $this->gitService->getRemoteBranches();
        $totalBranches = count($branches);
        
        foreach ($branches as $index => $branch) {
            $output->write("\r   Scanning: [" . ($index + 1) . "/{$totalBranches}] " . substr($branch, 0, 60) . str_repeat(' ', 20));
            
            $buildContent = $this->gitService->getBuildTxtContent($branch);
            if ($buildContent !== null && (int)$buildContent === (int)$buildNumber) {
                $output->writeln("");
                $output->writeln("<error>⚠️  Build number {$buildNumber} is ALREADY TAKEN!</error>");
                $output->writeln("   Found in branch: {$branch}");
                return $branch;
            }
        }
        
        $output->writeln("");
        $output->writeln("<info>✓ Build number {$buildNumber} is available!</info>");
        return false;
    }
    
    /**
     * Find next available build number starting from a specific number
     */
    public function findNextAvailableBuildNumber($startFrom, OutputInterface $output)
    {
        $output->writeln("<comment>🔍 Finding next available build number starting from {$startFrom}...</comment>");
        
        $branches = $this->gitService->getRemoteBranches();
        $takenNumbers = [];
        
        foreach ($branches as $index => $branch) {
            $output->write("\r   Scanning: [" . ($index + 1) . "/" . count($branches) . "] " . substr($branch, 0, 60) . str_repeat(' ', 20));
            
            $buildContent = $this->gitService->getBuildTxtContent($branch);
            if ($buildContent !== null) {
                $buildNum = (int)$buildContent;
                if ($buildNum >= self::MIN_BUILD_NUMBER) {
                    $takenNumbers[$buildNum] = $branch;
                }
            }
        }
        
        $output->writeln("");
        
        $candidate = $startFrom;
        while (isset($takenNumbers[$candidate])) {
            $candidate++;
        }
        
        $output->writeln("<info>✓ Next available build number: {$candidate}</info>");
        return $candidate;
    }
    
    /**
     * Collect all build data from remote branches
     */
    public function collectBuildData(OutputInterface $output)
    {
        $branches = $this->gitService->getRemoteBranches();
        $buildData = [];
        $processed = 0;
        $found = 0;
        
        foreach ($branches as $branch) {
            $processed++;
            $output->write("\r[{$processed}/" . count($branches) . "] Processing: " . substr($branch, 0, 80) . str_repeat(' ', 30));
            
            $content = $this->gitService->getBuildTxtContent($branch);
            
            if ($content !== null) {
                $buildNumber = (int)$content;
                
                if ($buildNumber >= self::MIN_BUILD_NUMBER) {
                    $buildData[$branch] = $buildNumber;
                    $found++;
                }
            }
        }
        
        $output->writeln("");
        $output->writeln("Found build.txt in {$found} out of " . count($branches) . " branches");
        
        return $buildData;
    }
    
    /**
     * Suggest available build numbers with gaps
     */
    public function suggestBuildNumbers(array $buildData, $currentBranch, $currentBuild, $minGap = 20)
    {
        $suggestions = [];
        
        // Sort build numbers
        $sortedBuilds = array_values($buildData);
        sort($sortedBuilds);
        
        // Find gaps
        for ($i = 0; $i < count($sortedBuilds) - 1; $i++) {
            $current = $sortedBuilds[$i];
            $next = $sortedBuilds[$i + 1];
            $gap = $next - $current;
            
            if ($gap >= $minGap) {
                $suggestions[] = [
                    'suggested' => $current + (int)($gap / 2),
                    'after' => $current,
                    'before' => $next,
                    'gap' => $gap,
                    'after_branch' => array_search($current, $buildData),
                    'before_branch' => array_search($next, $buildData),
                ];
            }
        }
        
        return $suggestions;
    }
}
