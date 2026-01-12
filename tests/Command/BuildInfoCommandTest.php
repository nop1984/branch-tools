<?php

namespace TestrailTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TestrailTools\Command\BuildInfoCommand;

/**
 * Unit tests for BuildInfoCommand output formats
 * 
 * @author Mykola Dolynskyi aka nop1984 <gospodin.p.zh@gmail.com>
 */
class BuildInfoCommandTest extends TestCase
{
    private $command;
    private $commandTester;
    
    protected function setUp(): void
    {
        // Note: These tests require a real git repository environment
        // They are integration tests rather than pure unit tests
        $application = new Application();
        $application->add(new BuildInfoCommand());
        
        $this->command = $application->find('build:info');
        $this->commandTester = new CommandTester($this->command);
    }
    
    /**
     * Test table output format with neighbor information
     */
    public function testTableOutputFormat()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'table',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Assert table headers are present
        $this->assertStringContainsString('Branch', $output);
        $this->assertStringContainsString('Build', $output);
        $this->assertStringContainsString('Left Neighbor', $output);
        $this->assertStringContainsString('Right Neighbor', $output);
        
        // Assert table separator is present
        $this->assertStringContainsString('+---', $output);
        $this->assertStringContainsString('|', $output);
        
        // Assert neighbor format: "buildNumber (±gap)"
        $this->assertMatchesRegularExpression('/\d+\s+\([+\-]\d+\)/', $output);
        
        // Assert success
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
    
    /**
     * Test JSON output format with neighbor information
     */
    public function testJsonOutputFormat()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'json',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Extract JSON from output (skip progress messages)
        $lines = explode("\n", $output);
        $jsonOutput = '';
        $jsonStarted = false;
        
        foreach ($lines as $line) {
            if (trim($line) === '[' || $jsonStarted) {
                $jsonStarted = true;
                $jsonOutput .= $line . "\n";
            }
        }
        
        // Decode JSON
        $data = json_decode(trim($jsonOutput), true);
        
        // Assert JSON is valid
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        
        // Check structure of first item
        $firstItem = $data[0];
        $this->assertArrayHasKey('branch', $firstItem);
        $this->assertArrayHasKey('build_number', $firstItem);
        $this->assertArrayHasKey('left_neighbor', $firstItem);
        $this->assertArrayHasKey('right_neighbor', $firstItem);
        
        // Check left_neighbor structure (when present)
        if ($firstItem['left_neighbor'] !== null) {
            $this->assertArrayHasKey('build_number', $firstItem['left_neighbor']);
            $this->assertArrayHasKey('branch', $firstItem['left_neighbor']);
            $this->assertArrayHasKey('gap', $firstItem['left_neighbor']);
        }
        
        // Check right_neighbor structure (when present)
        if ($firstItem['right_neighbor'] !== null) {
            $this->assertArrayHasKey('build_number', $firstItem['right_neighbor']);
            $this->assertArrayHasKey('branch', $firstItem['right_neighbor']);
            $this->assertArrayHasKey('gap', $firstItem['right_neighbor']);
        }
        
        // Assert success
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
    
    /**
     * Test CSV output format with neighbor information
     */
    public function testCsvOutputFormat()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'csv',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Find CSV content (skip progress messages)
        $lines = explode("\n", $output);
        $csvLines = [];
        
        foreach ($lines as $line) {
            if (strpos($line, ',') !== false && !strpos($line, 'Processing:')) {
                $csvLines[] = $line;
            }
        }
        
        $this->assertNotEmpty($csvLines);
        
        // Check CSV header
        $header = $csvLines[0];
        $this->assertStringContainsString('Branch', $header);
        $this->assertStringContainsString('Build Number', $header);
        $this->assertStringContainsString('Left Neighbor', $header);
        $this->assertStringContainsString('Left Gap', $header);
        $this->assertStringContainsString('Right Neighbor', $header);
        $this->assertStringContainsString('Right Gap', $header);
        
        // Check that we have data rows
        $this->assertGreaterThan(1, count($csvLines));
        
        // Parse first data row to verify structure
        if (count($csvLines) > 1) {
            $dataRow = str_getcsv($csvLines[1]);
            $this->assertCount(6, $dataRow); // branch, build, left_neighbor, left_gap, right_neighbor, right_gap
        }
        
        // Assert success
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
    
    /**
     * Test list output format with neighbor information
     */
    public function testListOutputFormat()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'list',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Assert branch labels are present
        $this->assertStringContainsString('Branch:', $output);
        
        // Assert build number is shown
        $this->assertStringContainsString('Build Number:', $output);
        
        // Assert neighbor information is shown
        $this->assertStringContainsString('Left Neighbor:', $output);
        $this->assertStringContainsString('Right Neighbor:', $output);
        
        // Assert gap information is shown in format: "buildNum (branch: branchName, gap: N)"
        $this->assertMatchesRegularExpression('/\d+\s+\(branch:.*?,\s+gap:\s+\d+\)/', $output);
        
        // Assert separator lines between branches
        $this->assertStringContainsString('------', $output);
        
        // Assert success
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
    
    /**
     * Test suggest output format
     */
    public function testSuggestOutputFormat()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'suggest',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Assert suggestion header is present
        $this->assertStringContainsString('Available Build Number Suggestions', $output);
        
        // Assert current branch info is shown
        $this->assertStringContainsString('Current branch:', $output);
        $this->assertStringContainsString('Current build number:', $output);
        
        // Assert recommended option is highlighted
        $this->assertStringContainsString('RECOMMENDED:', $output);
        
        // Assert gap analysis message
        $this->assertStringContainsString('Looking for gaps of at least', $output);
        
        // Assert suggestions table has headers
        $this->assertStringContainsString('Suggested', $output);
        $this->assertStringContainsString('After', $output);
        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('Gap', $output);
        
        // Assert top recommendations section
        $this->assertStringContainsString('Top 5 Recommendations', $output);
        $this->assertStringContainsString('BEST:', $output);
        
        // Assert success
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
    
    /**
     * Test neighbor calculation with edge cases
     */
    public function testNeighborCalculationEdgeCases()
    {
        // This test verifies neighbor calculation logic
        // by checking the JSON output for specific patterns
        
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'json',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Extract JSON
        $lines = explode("\n", $output);
        $jsonOutput = '';
        $jsonStarted = false;
        
        foreach ($lines as $line) {
            if (trim($line) === '[' || $jsonStarted) {
                $jsonStarted = true;
                $jsonOutput .= $line . "\n";
            }
        }
        
        $data = json_decode(trim($jsonOutput), true);
        $this->assertIsArray($data);
        
        // Find first and last items to check edge cases
        $firstItem = $data[0];
        $lastItem = $data[count($data) - 1];
        
        // First item should have no left neighbor or a valid left neighbor
        if ($firstItem['left_neighbor'] === null) {
            // This is expected for the lowest build number
            $this->assertNull($firstItem['left_neighbor']);
        } else {
            // If it has a left neighbor, verify the gap is positive
            $this->assertGreaterThan(0, $firstItem['left_neighbor']['gap']);
        }
        
        // Last item should have no right neighbor or a valid right neighbor
        if ($lastItem['right_neighbor'] === null) {
            // This is expected for the highest build number
            $this->assertNull($lastItem['right_neighbor']);
        } else {
            // If it has a right neighbor, verify the gap is positive
            $this->assertGreaterThan(0, $lastItem['right_neighbor']['gap']);
        }
        
        // Verify gap calculations are consistent
        foreach ($data as $item) {
            if ($item['left_neighbor'] !== null) {
                $expectedGap = $item['build_number'] - $item['left_neighbor']['build_number'];
                $this->assertEquals($expectedGap, $item['left_neighbor']['gap']);
            }
            
            if ($item['right_neighbor'] !== null) {
                $expectedGap = $item['right_neighbor']['build_number'] - $item['build_number'];
                $this->assertEquals($expectedGap, $item['right_neighbor']['gap']);
            }
        }
    }
    
    /**
     * Test that all formats handle empty neighbor cases (N/A)
     */
    public function testEmptyNeighborHandling()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'table',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Check if N/A is used for missing neighbors
        // (should appear for first/last build numbers)
        $this->assertStringContainsString('N/A', $output);
    }
    
    /**
     * Test gap minimum validation in suggest format
     */
    public function testSuggestGapValidation()
    {
        $this->commandTester->execute([
            'command' => $this->command->getName(),
            '--output' => 'suggest',
            '--scan-all' => true,
            'repo' => '/ageng/testrail-installation/repositories/testrail-core'
        ]);
        
        $output = $this->commandTester->getDisplay();
        
        // Parse suggestions to verify minimum gap requirement
        // All suggested gaps should meet minimum requirement (20 on each side = 40 total)
        preg_match_all('/gap of (\d+)/', $output, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $gap) {
                // Each gap should be at least 20 (minimum requirement)
                // Note: The total gap must be > 40, so suggested positions should have
                // at least 20 on each side
                $this->assertGreaterThanOrEqual(20, (int)$gap);
            }
        }
    }
}
