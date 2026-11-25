<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;

final class FeatureContext implements Context
{
    private string $lastOutput = '';
    private int $lastExitCode = 0;

    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../../../..');
        if ($root === false) {
            throw new RuntimeException('Could not resolve repository root.');
        }
        return $root;
    }

    private function runHelper(string ...$args): void
    {
        $helper = $this->repoRoot() . '/tests/behat/bin/quanta-test.php';
        $parts = array_merge([PHP_BINARY, $helper], $args);
        $command = implode(' ', array_map('escapeshellarg', $parts)) . ' 2>&1';
        $lines = [];
        $code = 0;
        exec($command, $lines, $code);
        $this->lastOutput = implode("\n", $lines);
        $this->lastExitCode = $code;
    }

    /** @Given the Quanta Behat site is installed */
    public function theQuantaBehatSiteIsInstalled(): void
    {
        $site = getenv('QUANTA_BEHAT_SITE') ?: 'behat.local';
        $index = $this->repoRoot() . '/sites/' . $site . '/index.html';
        if (!is_file($index)) {
            throw new RuntimeException("Test site is not installed: $index");
        }
    }

    /** @Then the site file :path exists */
    public function theSiteFileExists(string $path): void
    {
        $site = getenv('QUANTA_BEHAT_SITE') ?: 'behat.local';
        $file = $this->repoRoot() . '/sites/' . $site . '/' . ltrim($path, '/');
        if (!is_file($file)) {
            throw new RuntimeException("Expected site file does not exist: $file");
        }
    }

    /** @Then the Doctor log contains :text */
    public function theDoctorLogContains(string $text): void
    {
        $log = getenv('QUANTA_BEHAT_DOCTOR_LOG') ?: '';
        $contents = $log !== '' && is_file($log) ? file_get_contents($log) : false;
        if ($contents === false || !str_contains($contents, $text)) {
            throw new RuntimeException("Doctor log does not contain '$text'.");
        }
    }

    /** @When I render :path */
    public function iRender(string $path): void
    {
        $this->runHelper('render', $path);
    }

    /** @When I log in as administrator with the test password */
    public function iLogInWithTheTestPassword(): void
    {
        $password = getenv('QUANTA_BEHAT_PASSWORD') ?: 'behat-test-pass';
        $this->runHelper('login', $password);
    }

    /** @When I log in as administrator with password :password */
    public function iLogInWithPassword(string $password): void
    {
        $this->runHelper('login', $password);
    }

    /** @Then the login is accepted */
    public function theLoginIsAccepted(): void
    {
        if (!str_contains($this->lastOutput, 'LOGIN_OK')) {
            throw new RuntimeException("Expected successful login. Output:\n" . $this->lastOutput);
        }
    }

    /** @Then the login is rejected */
    public function theLoginIsRejected(): void
    {
        if (!str_contains($this->lastOutput, 'LOGIN_FAIL')) {
            throw new RuntimeException("Expected rejected login. Output:\n" . $this->lastOutput);
        }
    }

    /** @When I create node :name titled :title */
    public function iCreateNodeTitled(string $name, string $title): void
    {
        $this->runHelper('create-node', $name, $title);
    }

    /** @Then node :name has title :title */
    public function nodeHasTitle(string $name, string $title): void
    {
        $this->runHelper('node-title', $name);
        if ($this->lastExitCode !== 0 || !str_contains($this->lastOutput, $title)) {
            throw new RuntimeException("Node title mismatch. Output:\n" . $this->lastOutput);
        }
    }

    /** @Then the command succeeds */
    public function theCommandSucceeds(): void
    {
        if ($this->lastExitCode !== 0) {
            throw new RuntimeException("Command failed with exit {$this->lastExitCode}. Output:\n" . $this->lastOutput);
        }
    }

    /** @Then the output contains :text */
    public function theOutputContains(string $text): void
    {
        if (!str_contains($this->lastOutput, $text)) {
            throw new RuntimeException("Output does not contain '$text'. Output:\n" . $this->lastOutput);
        }
    }

    /** @Then the output has no unresolved Qtags */
    public function theOutputHasNoUnresolvedQtags(): void
    {
        if (preg_match('/\\[[A-Z][^\\[\\]]+\\]/', $this->lastOutput, $match)) {
            throw new RuntimeException('Unresolved Qtag found: ' . $match[0]);
        }
    }
}
