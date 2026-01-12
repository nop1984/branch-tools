# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-01-12

### Added
- **BuildInfoCommand**: Enhanced all output formats with left/right neighbor build information
  - Table format: Shows neighbor build numbers with gap indicators
  - JSON format: Includes nested neighbor objects with build_number, branch, and gap
  - CSV format: Added 4 new columns for left/right neighbor data
  - List format: Displays detailed neighbor information with branch names
  - Suggest format: Validates minimum 20-space gaps for recommendations
  
- **TriggerBuildCommand**: New pre-push hook command for CI/CD build triggering
  - Interactive prompt asking if user wants to trigger CI/CD build
  - Creates empty `[ci_build]` commit to trigger CI/CD pipeline
  - Automatically pushes commits in background after user confirmation
  - Cross-platform support (Windows, Linux, macOS)
  - `--auto` flag to skip prompt and automatically trigger
  - `--skip` flag to bypass the check entirely
  - Detects and skips if last commit was already empty or trigger commit exists

- **SelfUpdateCommand**: Enhanced with dependency change detection
  - Detects changes in composer.json between versions
  - Shows warning when `composer install` is required after update
  - Displays note in `--check` mode about dependency changes
  
- **Test Suite**: Comprehensive PHPUnit test coverage
  - 8 test cases for all BuildInfoCommand output formats
  - 574 assertions covering edge cases and validation
  - PHPUnit configuration with coverage settings
  - Test scripts in composer.json: `composer test` and `composer test-coverage`

### Changed
- BuildInfoCommand: Fixed data structure access patterns to work with simple [branch => buildNum] arrays
- All output methods now use calculateNeighbors() helper for consistent neighbor calculation
- Repository path detection: Centralized with recursive search functionality
- Improved prepare-commit workflows and install-hook logic

### Fixed
- PHP warnings "Trying to access array offset on int" in BuildInfoCommand
- Incorrect data structure assumptions in suggestBuildNumbers()
- Build info crashes resolved with enhanced build number gap suggestion
- Explicit auto-mode and better gap handling
- Single note instead of duplicate warnings during repo detection

## [1.0.0] - 2026-01-08

### Added
- **CLI Commands**:
  - `build:info` - Display build numbers and branch information across all remote branches
  - `workflow:update` - Update GitHub workflow files with current branch configuration
  - `prepare-commit` - Pre-commit automation for workflow updates and build validation
  - `install-hook` - Install git pre-commit hook for automatic execution

- **Service Classes**:
  - `GitService` - Git operations and branch management (308 lines)
  - `BuildNumberService` - Build number validation and conflict detection (145 lines)
  - `WorkflowService` - GitHub workflow YAML file management (103 lines)

- **Core Features**:
  - Symfony Console framework integration
  - DRY compliant architecture (98% code reuse)
  - Automatic branch origin detection with HEAD resolution
  - Build number validation across remote branches
  - Git pre-commit hook automation
  - Workflow file updates:
    - `.github/workflows/ci-build-with-kiuwan-analysis.yml` - Branch trigger list
    - `.github/workflows/set-gizmo-branch-var.yml` - Branch to origin mapping

- **Documentation**:
  - Comprehensive README.md with installation and usage instructions
  - Pre-commit hook sample file
  - Composer configuration with Symfony Console dependency
  
- **Developer Tools**:
  - `.gitignore` for PHP projects
  - Executable `branch-tools` CLI entry point
  - PSR-4 autoloading configuration
