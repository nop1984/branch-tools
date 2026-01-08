<?php

namespace TestrailTools\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service for Git operations
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class GitService
{
    private $repoRoot;
    private $remote;
    
    public function __construct($repoRoot = null, $remote = 'origin')
    {
        $this->repoRoot = $repoRoot;
        $this->remote = $remote;
    }
    
    public function setRepoRoot($repoRoot)
    {
        $this->repoRoot = $repoRoot;
    }
    
    public function getRepoRoot()
    {
        return $this->repoRoot;
    }
    
    public function setRemote($remote)
    {
        $this->remote = $remote;
    }
    
    /**
     * Detect the parent repository path when running from .git/branch-tools
     * 
     * This method recursively searches upwards from the current directory until it finds
     * a git repository. It validates that the detected path is not branch-tools itself.
     * 
     * @param OutputInterface $output Output interface for warnings
     * @return string Path to the parent repository
     * @throws \Exception if parent repository cannot be detected
     */
    public static function detectParentRepository(OutputInterface $output = null)
    {
        // Get the directory of the calling command
        $backtrace = debug_backtrace();
        $callerFile = $backtrace[0]['file'] ?? __FILE__;
        $currentDir = dirname($callerFile);
        
        $originalDir = getcwd();
        $searchPath = $currentDir;
        $maxLevels = 10; // Safety limit to prevent infinite loops
        $level = 0;
        
        while ($level < $maxLevels) {
            // Check if this directory is a git repository
            if (is_dir($searchPath)) {
                chdir($searchPath);
                $checkOutput = [];
                exec('git rev-parse --show-toplevel 2>&1', $checkOutput, $returnCode);
                
                if ($returnCode === 0) {
                    $detectedRepo = trim($checkOutput[0]);
                    chdir($originalDir);
                    
                    // Warn if we detected branch-tools itself (improper setup)
                    $repoBasename = basename($detectedRepo);
                    $isInBranchTools = strpos($detectedRepo, '.git/branch-tools') !== false;
                    
                    if ($isInBranchTools || $repoBasename === 'branch-tools') {
                        if ($output) {
                            $output->writeln("<error>⚠ Warning: Detected branch-tools repository itself!</error>");
                            $output->writeln("<comment>This is not a proper setup. branch-tools should be installed in .git/branch-tools/ of the target repository.</comment>");
                            $output->writeln("<comment>Continuing search for parent repository...</comment>");
                            $output->writeln("");
                        }
                        // Continue searching upwards
                    } else {
                        // Found a valid parent repository
                        return $detectedRepo;
                    }
                }
            }
            
            // Move up one level
            $parentDir = dirname($searchPath);
            
            // Check if we've reached the filesystem root
            if ($parentDir === $searchPath) {
                chdir($originalDir);
                throw new \Exception("Could not detect parent repository after searching {$level} levels up. Please specify the repository path explicitly.");
            }
            
            $searchPath = $parentDir;
            $level++;
        }
        
        chdir($originalDir);
        throw new \Exception("Could not detect parent repository after {$maxLevels} levels. Please specify the repository path explicitly.");
    }
    
    /**
     * Validate and get repository root
     */
    public function validateAndGetRepoRoot($path, OutputInterface $output)
    {
        $path = realpath($path);
        
        if ($path === false || !is_dir($path)) {
            throw new \Exception("Path does not exist or is not a directory: {$path}");
        }
        
        $originalDir = getcwd();
        chdir($path);
        
        $checkOutput = [];
        exec('git rev-parse --show-toplevel 2>&1', $checkOutput, $returnCode);
        
        if ($returnCode !== 0) {
            chdir($originalDir);
            throw new \Exception("Not a git repository: {$path}");
        }
        
        $repoRoot = trim($checkOutput[0]);
        $output->writeln("<info>Repository: {$repoRoot}</info>");
        
        chdir($repoRoot);
        $this->repoRoot = $repoRoot;
        
        return $repoRoot;
    }
    
    /**
     * Get current branch name
     */
    public function getCurrentBranch()
    {
        $output = [];
        exec('git rev-parse --abbrev-ref HEAD 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return null;
        }
        
        return trim($output[0]);
    }
    
    /**
     * Get all remote branches
     */
    public function getRemoteBranches()
    {
        $output = [];
        exec("git ls-remote --heads {$this->remote} 2>&1", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception("Failed to fetch remote branches");
        }
        
        $branches = [];
        foreach ($output as $line) {
            if (preg_match('/refs\/heads\/(.+)$/', $line, $matches)) {
                $branches[] = $matches[1];
            }
        }
        
        return $branches;
    }
    
    /**
     * Get build.txt content from a remote branch
     */
    public function getBuildTxtContent($branch)
    {
        $output = [];
        exec("git show {$this->remote}/{$branch}:build.txt 2>&1", $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            return trim($output[0]);
        }
        
        return null;
    }
    
    /**
     * Get local build.txt content
     */
    public function getLocalBuildTxt()
    {
        $buildTxtPath = $this->repoRoot . '/build.txt';
        
        if (!file_exists($buildTxtPath)) {
            return null;
        }
        
        return (int)trim(file_get_contents($buildTxtPath));
    }
    
    /**
     * Get remote build.txt for current branch
     */
    public function getRemoteBuildTxt($branch = null)
    {
        if ($branch === null) {
            $branch = $this->getCurrentBranch();
        }
        
        $commandOutput = [];
        exec("git show {$this->remote}/{$branch}:build.txt 2>&1", $commandOutput, $returnCode);
        
        if ($returnCode === 0 && !empty($commandOutput)) {
            return (int)trim($commandOutput[0]);
        }
        
        return null;
    }
    
    /**
     * Write build number to build.txt
     */
    public function writeBuildTxt($buildNumber)
    {
        $buildTxtPath = $this->repoRoot . '/build.txt';
        
        if (file_put_contents($buildTxtPath, $buildNumber . "\n") === false) {
            throw new \Exception("Failed to write to build.txt");
        }
        
        return $buildTxtPath;
    }
    
    /**
     * Check if a string is a valid branch name (not HEAD, SHA, etc.)
     */
    private function isValidBranchName($name)
    {
        // Exclude special refs and SHAs
        if (in_array($name, ['HEAD', 'FETCH_HEAD', 'ORIG_HEAD', 'MERGE_HEAD'])) {
            return false;
        }
        
        // Check if it's a SHA (40 hex chars or abbreviated)
        if (preg_match('/^[0-9a-f]{7,40}$/i', $name)) {
            return false;
        }
        
        // Verify the branch exists
        exec("git rev-parse --verify --quiet {$name} 2>&1", $output, $returnCode);
        if ($returnCode !== 0) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Resolve HEAD or symbolic ref to actual branch name
     * Can optionally provide a commit SHA to find which branch was at that commit
     */
    private function resolveToActualBranch($ref, $commitSha = null)
    {
        // If it's HEAD, we need to find which branch it was pointing to
        if ($ref === 'HEAD') {
            // If we have a commit SHA from reflog, find which branch points to it
            if ($commitSha !== null) {
                $output = [];
                exec("git branch --contains {$commitSha} 2>&1", $output, $returnCode);
                if ($returnCode === 0 && !empty($output)) {
                    // Look for develop, main, master first (common base branches)
                    $commonBases = ['develop', 'main', 'master', 'release/10.0.0'];
                    foreach ($output as $line) {
                        $branch = trim(str_replace('*', '', $line));
                        if (in_array($branch, $commonBases)) {
                            return $branch;
                        }
                    }
                    
                    // Otherwise, return the first non-current branch
                    foreach ($output as $line) {
                        $branch = trim(str_replace('*', '', $line));
                        if (!empty($branch) && $branch !== 'HEAD' && $this->isValidBranchName($branch)) {
                            return $branch;
                        }
                    }
                }
            }
            
            // Fallback: get current HEAD branch
            $output = [];
            exec("git rev-parse --abbrev-ref HEAD 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                $resolved = trim($output[0]);
                if ($resolved !== 'HEAD') {
                    return $resolved;
                }
            }
        }
        
        return $ref;
    }
    
    /**
     * Determine origin branch using multiple methods
     */
    public function getOriginBranch($currentBranch, &$detectionMethod = null, &$detectionLine = null)
    {
        // Method 1: Check reflog for branch creation
        $output = [];
        exec("git reflog show {$currentBranch} 2>&1", $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            // Get the last line (oldest entry)
            $reflogLine = end($output);
            
            if (preg_match('/branch: Created from (.+)/', $reflogLine, $matches)) {
                $originCandidate = trim($matches[1]);
                
                // Extract commit SHA from reflog line (format: SHA branch@{n}: action)
                $commitSha = null;
                if (preg_match('/^([0-9a-f]+)\s/', $reflogLine, $shaMatch)) {
                    $commitSha = $shaMatch[1];
                }
                
                // Resolve HEAD or symbolic refs to actual branch names
                $resolvedOrigin = $this->resolveToActualBranch($originCandidate, $commitSha);
                
                // Validate it's a real branch name
                if ($this->isValidBranchName($resolvedOrigin)) {
                    $detectionMethod = 'git reflog (branch creation history)';
                    $detectionLine = $reflogLine;
                    return $resolvedOrigin;
                }
            }
        }
        
        // Method 2: Use git merge-base to find closest common ancestor
        $commonBranches = ['develop', 'release/10.0.0', 'main', 'master'];
        $bestMatch = null;
        $minDistance = PHP_INT_MAX;
        
        foreach ($commonBranches as $baseBranch) {
            exec("git rev-parse --verify {$baseBranch} 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                continue;
            }
            
            $output = [];
            exec("git merge-base {$baseBranch} {$currentBranch} 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                continue;
            }
            
            $mergeBase = trim($output[0]);
            
            $output = [];
            exec("git rev-list --count {$mergeBase}..{$baseBranch} 2>&1", $output, $returnCode);
            if ($returnCode === 0) {
                $distance = (int)trim($output[0]);
                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $bestMatch = $baseBranch;
                }
            }
        }
        
        if ($bestMatch) {
            $detectionMethod = 'git merge-base (common ancestor)';
            $detectionLine = "Closest: {$bestMatch} (distance: {$minDistance})";
            return $bestMatch;
        }
        
        throw new \Exception("Unable to determine origin branch for '{$currentBranch}'");
    }
}
