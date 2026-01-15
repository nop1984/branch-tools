# Changelog

All notable changes to this project will be documented in this file.

## [1.1.0] - 2026-01-15

### Added
- **CleanupBranchCommand**: New command for pre-merge cleanup (`cleanup-branch`)
  - Restores `.github/workflows/set-gizmo-branch-var.yml` to parent branch state
  - Restores `.github/workflows/ci-build-with-kiuwan-analysis.yml` to parent branch state
  - Restores `build.txt` to parent branch value
  - Automatically detects parent branch using `GitService::getOriginBranch()`
  - Commits changes with `--no-verify` (bypasses hooks)
  - Pushes changes with `--no-verify`
  - Useful before merging feature branches to keep parent branch clean

- **Intelligent Build Increment**: Smart detection of when build number should be incremented
  - New method `GitService::hasRealChangesSinceLastTriggerBuild()` checks for real file changes
  - Compares local HEAD against last `[ci_build] Trigger build` commit on remote
  - Ignores trigger build commits inherited from parent branch (uses `git merge-base --is-ancestor`)
  - Only prompts for increment when actual code/file changes detected
  - Defaults to **YES** when real changes found (was NO before)
  - Skips increment entirely when no file changes detected (saves build numbers)
  - Uses `git diff --name-only <commit>..HEAD` to detect changed files

- **Pull-and-Retry Mechanism**: Automatic recovery when local build is behind remote
  - New method `PrepareCommitCommand::pullAndScheduleCommit()` handles auto-pull scenario
  - Detects when local build.txt < remote build.txt
  - Prompts user to pull changes (y/n)
  - Resets `build.txt` to HEAD before pull to avoid conflicts
  - Uses `git pull --rebase -X theirs` to auto-accept remote changes in conflicts
  - Schedules commit retry in background using `GitService::scheduleAsyncCommand()`
  - Reads commit message from `.git/COMMIT_EDITMSG` 
  - Filters out comment lines (starting with #) from commit message
  - Passes message to background commit using `-F .git/COMMIT_EDITMSG`
  - Uses `--no-verify` flag to skip hooks on retry (prevents infinite loops)
  - Logs output to `/tmp/git-async-<timestamp>.log`
  - Returns false to cancel current commit (allows pull to complete)

- **Background Process Improvements**:
  - New shared method `GitService::scheduleAsyncCommand()` for cross-platform async execution
  - Unix/Linux/Mac: Uses `nohup bash -c 'sleep N && command' > logfile 2>&1 &`
  - Windows: Uses `start /B cmd /c "timeout /T N && command"`
  - All background operations log to `/tmp/git-async-<timestamp>.log` for debugging
  - Displays log file path after scheduling
  - Exit code handling for proper error reporting

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
  - New constant `TRIGGER_BUILD_MESSAGE = '[ci_build] Trigger build'` for consistency
  - Detects if upstream branch is set using `git rev-parse --abbrev-ref @{upstream}`
  - Uses `--set-upstream origin <branch>` on first push (when no upstream)
  - Uses `--no-verify` on background push to skip pre-push hook recursion
  - Improved messaging: explains push is cancelled and will retry automatically
  - Returns FAILURE intentionally to cancel original push (allows trigger commit to be pushed)

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
- **PrepareCommitCommand**:
  - Default answer changed from NO to YES when real file changes detected
  - Pull command now uses `--rebase -X theirs` instead of plain pull
  - Commit retry uses `--no-verify` to avoid recursive hook execution
  - Added detailed user messaging about scheduled retries
  
- **TriggerBuildCommand**:
  - Refactored to use `TriggerBuildCommand::TRIGGER_BUILD_MESSAGE` constant throughout
  - Push command includes `--no-verify` flag
  - Uses shared `GitService::scheduleAsyncCommand()` method

- **GitService**:
  - Added `use TestrailTools\Command\TriggerBuildCommand` import for constant access
  - Added 128-line `hasRealChangesSinceLastTriggerBuild()` method
  - Added 32-line `scheduleAsyncCommand()` static method for DRY principle

- **Architecture**:
  - Eliminated duplicate async command code (DRY principle)
  - Centralized background process scheduling in GitService
  - Improved error messages for better user guidance
  - Better separation of concerns (pull logic extracted to separate method)

- BuildInfoCommand: Fixed data structure access patterns to work with simple [branch => buildNum] arrays
- All output methods now use calculateNeighbors() helper for consistent neighbor calculation
- Repository path detection: Centralized with recursive search functionality
- Improved prepare-commit workflows and install-hook logic

### Fixed
- **Background Operations**: Background push/commit processes no longer fail silently
  - Previously used `> /dev/null 2>&1 &` which hid all output and errors
  - Now logs to `/tmp/git-async-*.log` files for debugging
  - Users see log file path and can check status

- **Divergent Branches**: Fixed `git pull` failure with divergent branches
  - Previously failed with "Need to specify how to reconcile divergent branches"
  - Now uses `--rebase` flag to handle divergent branches automatically
  - Uses `-X theirs` strategy to auto-accept remote changes in conflicts

- **Commit Message Loss**: Fixed background commit losing original commit message
  - Previously didn't pass commit message to background retry
  - Now reads from `.git/COMMIT_EDITMSG` and uses `-F` flag
  - Filters comment lines to get clean message

- **Hook Recursion**: Fixed infinite hook execution in background operations
  - Background commit/push now use `--no-verify` to skip hooks
  - Prevents recursive hook invocation
  - Prevents TTY prompt errors in background processes

- **HEAD Lock Conflicts**: Fixed "cannot lock ref HEAD" errors
  - Previously tried to commit while pull was active
  - Now cancels current commit and schedules retry for after hook exits
  - Clean separation of pull and commit operations

- PHP warnings "Trying to access array offset on int" in BuildInfoCommand
- Incorrect data structure assumptions in suggestBuildNumbers()
- Build info crashes resolved with enhanced build number gap suggestion
- Explicit auto-mode and better gap handling
- Single note instead of duplicate warnings during repo detection
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
