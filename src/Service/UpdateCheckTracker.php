<?php
/**
 * UpdateCheckTracker - Tracks update check frequency and skip preferences
 *
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */

namespace TestrailTools\Service;

class UpdateCheckTracker
{
    private string $trackerFile;
    
    public function __construct()
    {
        $this->trackerFile = sys_get_temp_dir() . '/.branch-tools-update-check';
    }
    
    /**
     * Check if we should check for updates today
     */
    public function shouldCheckForUpdates(): bool
    {
        if (!file_exists($this->trackerFile)) {
            return true;
        }
        
        $data = $this->loadTrackerData();
        
        // If skipped today, don't check again
        if (isset($data['skip_until'])) {
            $skipUntil = strtotime($data['skip_until']);
            if ($skipUntil && $skipUntil > time()) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Mark that update check was performed
     */
    public function markChecked(): void
    {
        $data = [
            'last_check' => date('Y-m-d H:i:s'),
            'skip_until' => null,
        ];
        
        $this->saveTrackerData($data);
    }
    
    /**
     * Skip update checks until tomorrow
     */
    public function skipUntilTomorrow(): void
    {
        $data = [
            'last_check' => date('Y-m-d H:i:s'),
            'skip_until' => date('Y-m-d 23:59:59'),
        ];
        
        $this->saveTrackerData($data);
    }
    
    /**
     * Load tracker data from file
     */
    private function loadTrackerData(): array
    {
        $content = @file_get_contents($this->trackerFile);
        
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        
        return is_array($data) ? $data : [];
    }
    
    /**
     * Save tracker data to file
     */
    private function saveTrackerData(array $data): void
    {
        file_put_contents($this->trackerFile, json_encode($data, JSON_PRETTY_PRINT));
    }
    
    /**
     * Get the path to the tracker file
     */
    public function getTrackerFile(): string
    {
        return $this->trackerFile;
    }
}
