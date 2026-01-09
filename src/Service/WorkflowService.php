<?php

namespace TestrailTools\Service;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service for managing GitHub workflow files
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class WorkflowService
{
    private $repoRoot;
    private $gizmoWorkflowPath;
    private $kiuwanWorkflowPath;
    
    public function __construct($repoRoot)
    {
        $this->repoRoot = $repoRoot;
        $this->gizmoWorkflowPath = $repoRoot . '/.github/workflows/set-gizmo-branch-var.yml';
        $this->kiuwanWorkflowPath = $repoRoot . '/.github/workflows/ci-build-with-kiuwan-analysis.yml';
    }
    
    /**
     * Update set-gizmo-branch-var.yml with new case entry
     * 
     * @return array [status => 'updated'|'skipped'|'error', 'reason' => '...', 'file' => '...']
     */
    public function updateGizmoWorkflow($currentBranch, $originBranch)
    {
        $fileName = basename($this->gizmoWorkflowPath);
        $result = ['file' => $fileName, 'status' => 'skipped', 'reason' => 'Unknown reason'];

        if (!file_exists($this->gizmoWorkflowPath)) {
            $result['status'] = 'error';
            $result['reason'] = 'File not found';
            return $result;
        }
        
        $content = file_get_contents($this->gizmoWorkflowPath);
        
        // Check for default case match
        $defaultPattern = '/\*\)\s+echo\s+"([^"]+)"\s*;;/';
        if (preg_match($defaultPattern, $content, $matches)) {
            $defaultBranch = $matches[1];
            if ($defaultBranch === $originBranch) {
                $result['reason'] = "No entry for {$currentBranch} necessary (matches default)";
                return $result;
            }
        }
        
        // Check if branch already exists
        if (strpos($content, "{$currentBranch})") !== false) {
            $result['reason'] = "Branch entry {$currentBranch} already exists";
            return $result;
        }
        
        $pattern = '/(\s+)(\*\))/';
        $newEntry = "\n            {$currentBranch})\n              echo \"{$originBranch}\";;\n            ";
        
        $updatedContent = preg_replace($pattern, $newEntry . '$2', $content, 1);
        
        if ($updatedContent === $content) {
            $result['status'] = 'error';
            $result['reason'] = 'Pattern mismatch';
            return $result;
        }
        
        if (file_put_contents($this->gizmoWorkflowPath, $updatedContent) !== false) {
            $result['status'] = 'updated';
            $result['reason'] = '';
        } else {
             $result['status'] = 'error';
             $result['reason'] = 'Write failed';
        }
        
        return $result;
    }
    
    /**
     * Update ci-build-with-kiuwan-analysis.yml branches list
     * 
     * @return array [status => 'updated'|'skipped'|'error', 'reason' => '...', 'file' => '...']
     */
    public function updateKiuwanWorkflow($currentBranch)
    {
        $fileName = basename($this->kiuwanWorkflowPath);
        $result = ['file' => $fileName, 'status' => 'skipped', 'reason' => 'Unknown reason'];

        if (!file_exists($this->kiuwanWorkflowPath)) {
            $result['status'] = 'error';
            $result['reason'] = 'File not found';
            return $result;
        }
        
        $content = file_get_contents($this->kiuwanWorkflowPath);
        
        // Check if branch already exists
        if (preg_match("/^\s+- ['\"]?" . preg_quote($currentBranch, '/') . "['\"]?\s*$/m", $content)) {
            $result['reason'] = "Branch entry {$currentBranch} already exists";
            return $result;
        }
        
        $pattern = "/(on:\s+push:\s+branches:.*?)((\n\s+- ['\"][^'\"]+['\"])+)/s";
        
        if (preg_match($pattern, $content, $matches)) {
            $newBranchEntry = "\n      - '{$currentBranch}'";
            $updatedContent = str_replace(
                $matches[0],
                $matches[1] . $matches[2] . $newBranchEntry,
                $content
            );
            
            if (file_put_contents($this->kiuwanWorkflowPath, $updatedContent) !== false) {
                $result['status'] = 'updated';
                $result['reason'] = '';
            } else {
                $result['status'] = 'error';
                $result['reason'] = 'Write failed';
            }
        } else {
             $result['status'] = 'error';
             $result['reason'] = 'Pattern mismatch';
        }
        
        return $result;
    }
    
    /**
     * Check if branch already exists in workflow files
     */
    public function branchExistsInWorkflows($currentBranch)
    {
        $existsInGizmo = false;
        $existsInKiuwan = false;
        
        if (file_exists($this->gizmoWorkflowPath)) {
            $content = file_get_contents($this->gizmoWorkflowPath);
            $existsInGizmo = strpos($content, "{$currentBranch})") !== false;
        }
        
        if (file_exists($this->kiuwanWorkflowPath)) {
            $content = file_get_contents($this->kiuwanWorkflowPath);
            $existsInKiuwan = preg_match("/^\s+- ['\"]?" . preg_quote($currentBranch, '/') . "['\"]?\s*$/m", $content);
        }
        
        return $existsInGizmo && $existsInKiuwan;
    }
}
