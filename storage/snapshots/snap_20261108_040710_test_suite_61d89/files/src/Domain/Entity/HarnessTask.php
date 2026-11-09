<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Enum\HarnessTaskStatus;

final readonly class HarnessTask
{
    /**
     * @param string[] $filesChanged
     */
    public function __construct(
        public ?int $id,
        public string $taskCode,
        public string $title,
        public string $specification,
        public string $actionType, // 'scaffold_module' | 'agent_prompt' | 'research_and_develop' | 'self_audit'
        public string $targetName,
        public string $agentBackend, // 'builtin-hexagonal' | 'claude' | 'codex' | 'gemini' | 'opencode'
        public HarnessTaskStatus $status,
        public int $attempts,
        public int $maxAttempts,
        public string $snapshotId,
        public array $filesChanged = [],
        public string $verificationOutput = '',
        public string $executionLog = '',
        public string $createdAt = '',
        public string $updatedAt = ''
    ) {
    }
}
