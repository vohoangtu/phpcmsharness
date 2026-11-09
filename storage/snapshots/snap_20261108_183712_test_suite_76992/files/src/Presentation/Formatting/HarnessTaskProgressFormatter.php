<?php

declare(strict_types=1);

namespace App\Presentation\Formatting;

use App\Domain\Entity\HarnessTask;

final class HarnessTaskProgressFormatter
{
    /**
     * @param HarnessTask[] $tasks
     * @return array<int, array{id: int, status: string, status_label: string, badge_class: string, attempts: int, max_attempts: int, summary: string, files_changed: string[], snapshot_id: string}>
     */
    public function format(array $tasks): array
    {
        $formatted = [];
        foreach ($tasks as $task) {
            $files = $task->filesChanged;
            $summary = match ($task->status->value) {
                'backlog' => 'Đang chờ được thực thi.',
                'running' => $this->latestProgressLine($task->executionLog)
                    ?? ($files !== [] ? sprintf('Đang làm việc trên %d tệp.', count($files)) : 'Agent đang phân tích yêu cầu.'),
                'completed' => sprintf('Hoàn tất; đã cập nhật %d tệp và vượt qua 3/3 cổng kiểm định.', count($files)),
                'rolled_back' => sprintf('Không hoàn tất sau %d lần thử; source đã được khôi phục về snapshot.', $task->attempts),
                default => 'Tác vụ chưa hoàn tất.',
            };
            $formatted[(int) $task->id] = [
                'id' => (int) $task->id,
                'status' => $task->status->value,
                'status_label' => $task->status->label(),
                'badge_class' => $task->status->badgeClass(),
                'attempts' => $task->attempts,
                'max_attempts' => $task->maxAttempts,
                'summary' => $summary,
                'files_changed' => $files,
                'snapshot_id' => $task->snapshotId,
            ];
        }

        return $formatted;
    }

    private function latestProgressLine(string $executionLog): ?string
    {
        $lines = array_reverse(explode("\n", $executionLog));
        foreach ($lines as $line) {
            if (!preg_match('/^\[\d{2}:\d{2}:\d{2}\] (?:🔧|🧪|✅|📸|🔄|🚀)\s*(.*)$/u', trim($line), $matches)) {
                continue;
            }
            return trim($matches[1]);
        }

        return null;
    }
}
