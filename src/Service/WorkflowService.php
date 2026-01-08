<?php

namespace TestrailTools\Service;

/**
 * Service for updating GitHub workflow files
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
     */
    public function updateGizmoWorkflow($currentBranch, $originBranch)
    {
        if (!file_exists($this->gizmoWorkflowPath)) {
            return false;
        }
        
        $content = file_get_contents($this->gizmoWorkflowPath);
        
        // Check if branch already exists
        if (strpos($content, "{$currentBranch})") !== false) {
            return false;
        }
        
        $pattern = '/(\s+)(\*\))/';
        $newEntry = "\n            {$currentBranch})\n              echo \"{$originBranch}\";;\n            ";
        
        $updatedContent = preg_replace($pattern, $newEntry . '$2', $content, 1);
        
        if ($updatedContent === $content) {
            return false;
        }
        
        file_put_contents($this->gizmoWorkflowPath, $updatedContent);
        return true;
    }
    
    /**
     * Update ci-build-with-kiuwan-analysis.yml branches list
     */
    public function updateKiuwanWorkflow($currentBranch)
    {
        if (!file_exists($this->kiuwanWorkflowPath)) {
            return false;
        }
        
        $content = file_get_contents($this->kiuwanWorkflowPath);
        
        // Check if branch already exists
        if (preg_match("/^\s+- ['\"]?" . preg_quote($currentBranch, '/') . "['\"]?\s*$/m", $content)) {
            return false;
        }
        
        $pattern = "/(on:\s+push:\s+branches:.*?)((\n\s+- ['\"][^'\"]+['\"])+)/s";
        
        if (preg_match($pattern, $content, $matches)) {
            $newBranchEntry = "\n      - '{$currentBranch}'";
            $updatedContent = str_replace(
                $matches[0],
                $matches[1] . $matches[2] . $newBranchEntry,
                $content
            );
            
            file_put_contents($this->kiuwanWorkflowPath, $updatedContent);
            return true;
        }
        
        return false;
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
