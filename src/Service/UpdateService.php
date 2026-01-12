<?php
/**
 * UpdateService - Handles self-update functionality
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Service;

use Exception;

class UpdateService
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/nop1984/branch-tools/releases/latest';
    private const DOWNLOAD_TIMEOUT = 60;
    
    private string $currentVersion;
    private string $executablePath;
    
    public function __construct(string $currentVersion)
    {
        $this->currentVersion = $currentVersion;
        $this->executablePath = $this->findExecutablePath();
    }
    
    /**
     * Find the path to the branch-tools executable
     */
    private function findExecutablePath(): string
    {
        // Check common locations
        $paths = [
            __DIR__ . '/../../branch-tools',
            getcwd() . '/branch-tools',
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return realpath($path);
            }
        }
        
        throw new Exception('Could not locate branch-tools executable');
    }
    
    /**
     * Check if a new version is available
     *
     * @return array|null Returns release info with 'update_available' flag
     */
    public function checkForUpdate(): ?array
    {
        try {
            $releaseData = $this->fetchLatestRelease();
            
            if (!$releaseData) {
                return null;
            }
            
            $latestVersion = ltrim($releaseData['tag_name'] ?? '', 'v');
            $updateAvailable = version_compare($latestVersion, $this->currentVersion, '>');
            
            return [
                'version' => $latestVersion,
                'tag' => $releaseData['tag_name'],
                'url' => $releaseData['html_url'],
                'published_at' => $releaseData['published_at'] ?? null,
                'body' => $releaseData['body'] ?? null,
                'update_available' => $updateAvailable,
                'composer_changed' => $updateAvailable ? $this->hasComposerChanged($releaseData['tag_name']) : false,
            ];
        } catch (Exception $e) {
            throw new Exception("Failed to check for updates: " . $e->getMessage());
        }
    }
    
    /**
     * Fetch latest release information from GitHub API
     */
    private function fetchLatestRelease(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: branch-tools-updater',
                    'Accept: application/vnd.github.v3+json',
                ],
                'timeout' => 10,
            ],
        ]);
        
        $response = @file_get_contents(self::GITHUB_API_URL, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to fetch release information from GitHub');
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse GitHub API response');
        }
        
        return $data;
    }
    
    /**
     * Check if composer.json has changed between versions
     *
     * @param string $newTag The new version tag to compare against
     * @return bool True if composer.json has changed
     */
    private function hasComposerChanged(string $newTag): bool
    {
        try {
            // Get current composer.json content
            $currentComposerPath = dirname($this->executablePath) . '/composer.json';
            if (!file_exists($currentComposerPath)) {
                return false;
            }
            $currentComposer = file_get_contents($currentComposerPath);
            
            // Fetch new composer.json from GitHub
            $newComposerUrl = sprintf(
                'https://raw.githubusercontent.com/nop1984/branch-tools/%s/composer.json',
                $newTag
            );
            
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => 'User-Agent: branch-tools-updater',
                    'timeout' => 5,
                ],
            ]);
            
            $newComposer = @file_get_contents($newComposerUrl, false, $context);
            
            if ($newComposer === false) {
                // Can't determine, assume it might have changed
                return true;
            }
            
            // Compare relevant sections (require, require-dev)
            $current = json_decode($currentComposer, true);
            $new = json_decode($newComposer, true);
            
            if (!$current || !$new) {
                return true;
            }
            
            // Check if require or require-dev sections have changed
            $currentRequire = json_encode($current['require'] ?? []);
            $newRequire = json_encode($new['require'] ?? []);
            $currentRequireDev = json_encode($current['require-dev'] ?? []);
            $newRequireDev = json_encode($new['require-dev'] ?? []);
            
            return ($currentRequire !== $newRequire || $currentRequireDev !== $newRequireDev);
            
        } catch (Exception $e) {
            // If we can't determine, assume it might have changed
            return true;
        }
    }
    
    /**
     * Check for updates silently (used for background checks)
     * Returns update info without throwing exceptions
     */
    public function checkForUpdateSilently(): ?array
    {
        try {
            return $this->checkForUpdate();
        } catch (Exception $e) {
            // Silently fail - don't interrupt user's workflow
            return null;
        }
    }
    
    /**
     * Download and install the latest version
     *
     * @param bool $dryRun If true, only simulates the update
     * @return bool Success status
     */
    public function performUpdate(bool $dryRun = false): bool
    {
        $updateInfo = $this->checkForUpdate();
        
        if (!$updateInfo) {
            return false;
        }
        
        // Clone the repository to a temporary location
        $tempDir = sys_get_temp_dir() . '/branch-tools-update-' . uniqid();
        
        try {
            if ($dryRun) {
                return true;
            }
            
            // Clone the repository with the specific tag
            $cloneUrl = 'https://github.com/nop1984/branch-tools.git';
            $tag = $updateInfo['tag'];
            
            exec(sprintf(
                'git clone --depth 1 --branch %s %s %s 2>&1',
                escapeshellarg($tag),
                escapeshellarg($cloneUrl),
                escapeshellarg($tempDir)
            ), $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Failed to clone repository: ' . implode("\n", $output));
            }
            
            // Install dependencies
            $composerPath = $tempDir . '/composer.phar';
            if (!file_exists($composerPath)) {
                // Download composer
                exec(sprintf(
                    'cd %s && curl -sS https://getcomposer.org/installer | php 2>&1',
                    escapeshellarg($tempDir)
                ), $output, $returnCode);
                
                if ($returnCode !== 0) {
                    throw new Exception('Failed to download composer');
                }
            }
            
            exec(sprintf(
                'cd %s && php composer.phar install --no-dev --optimize-autoloader 2>&1',
                escapeshellarg($tempDir)
            ), $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception('Failed to install dependencies: ' . implode("\n", $output));
            }
            
            // Backup current installation
            $backupPath = $this->executablePath . '.backup';
            $installDir = dirname($this->executablePath);
            
            if (!$this->backupCurrentVersion($backupPath)) {
                throw new Exception('Failed to create backup');
            }
            
            // Copy new files
            if (!$this->copyNewVersion($tempDir, $installDir)) {
                // Restore backup
                $this->restoreBackup($backupPath);
                throw new Exception('Failed to copy new version');
            }
            
            // Cleanup
            $this->cleanup($tempDir);
            
            return true;
            
        } catch (Exception $e) {
            // Cleanup temp directory
            if (is_dir($tempDir)) {
                $this->cleanup($tempDir);
            }
            throw $e;
        }
    }
    
    /**
     * Backup the current version
     */
    private function backupCurrentVersion(string $backupPath): bool
    {
        $installDir = dirname($this->executablePath);
        $backupDir = dirname($backupPath);
        
        // Remove old backup if exists
        if (is_dir($backupPath)) {
            $this->cleanup($backupPath);
        }
        
        // Create backup directory
        if (!mkdir($backupPath, 0755, true)) {
            return false;
        }
        
        // Copy current installation
        exec(sprintf(
            'cp -r %s/* %s/ 2>&1',
            escapeshellarg($installDir),
            escapeshellarg($backupPath)
        ), $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    /**
     * Copy new version files
     */
    private function copyNewVersion(string $sourceDir, string $targetDir): bool
    {
        // Remove vendor directory to avoid conflicts
        $vendorPath = $targetDir . '/vendor';
        if (is_dir($vendorPath)) {
            $this->cleanup($vendorPath);
        }
        
        // Copy new files
        exec(sprintf(
            'cp -r %s/* %s/ 2>&1',
            escapeshellarg($sourceDir),
            escapeshellarg($targetDir)
        ), $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    /**
     * Restore from backup
     */
    private function restoreBackup(string $backupPath): bool
    {
        $installDir = dirname($this->executablePath);
        
        exec(sprintf(
            'rm -rf %s/* && cp -r %s/* %s/ 2>&1',
            escapeshellarg($installDir),
            escapeshellarg($backupPath),
            escapeshellarg($installDir)
        ), $output, $returnCode);
        
        return $returnCode === 0;
    }
    
    /**
     * Cleanup temporary directory
     */
    private function cleanup(string $path): void
    {
        if (is_dir($path)) {
            exec(sprintf('rm -rf %s 2>&1', escapeshellarg($path)));
        }
    }
    
    /**
     * Get current version
     */
    public function getCurrentVersion(): string
    {
        return $this->currentVersion;
    }
    
    /**
     * Get executable path
     */
    public function getExecutablePath(): string
    {
        return $this->executablePath;
    }
}
