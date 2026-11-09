<?php

declare(strict_types=1);

namespace App\Application\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds source-grounded CMS maintenance topics instead of relying only on a fixed module list.
 * The signals are intentionally conservative: unfinished markers, services missing from the
 * current integration-test corpus, and source files that are statistical size outliers.
 */
final readonly class CmsTopicGenerator
{
    public function __construct(private string $basePath)
    {
    }

    /**
     * @param array<string, bool> $existingTargets Lowercase target names already present in task history
     * @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    public function generate(array $existingTargets = []): array
    {
        $topics = array_merge(
            $this->findUnfinishedMarkers($existingTargets),
            $this->findUntestedServices($existingTargets),
            $this->findLargeSourceFiles($existingTargets)
        );

        $priorityOrder = ['P0' => 0, 'P1' => 1, 'P2' => 2];
        usort($topics, static fn(array $a, array $b): int =>
            ($priorityOrder[$a['priority']] ?? 9) <=> ($priorityOrder[$b['priority']] ?? 9)
                ?: strcmp($a['title'], $b['title'])
        );

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnfinishedMarkers(array $existingTargets): array
    {
        $topics = [];
        $seenTargets = $existingTargets;
        foreach ($this->collectPhpFiles(['src', 'public', 'config']) as $file) {
            $relativePath = $this->relativePath($file);
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b\s*:?\s*([^\r\n*]*)/i', $token[1], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $match) {
                    $marker = strtoupper($match[1][0]);
                    $note = trim(preg_replace('/\s+/', ' ', $match[2][0]) ?? $match[2][0]);
                    $lineNumber = $token[2] + substr_count(substr($token[1], 0, $match[0][1]), "\n");
                    $targetName = 'CmsTodo' . substr(sha1($relativePath . ':' . $marker . ':' . strtolower($note)), 0, 12);
                    if (isset($seenTargets[strtolower($targetName)])) {
                        continue;
                    }
                    $seenTargets[strtolower($targetName)] = true;
                    $topics[] = [
                        'title' => sprintf('[CMS Topic P0] Xử lý %s tại %s:%d', $marker, $relativePath, $lineNumber),
                        'target_name' => $targetName,
                        'priority' => 'P0',
                        'specification' => sprintf(
                            "Bộ phát hiện mã nguồn tìm thấy marker %s tại %s:%d.%s\n\nKhông xóa marker nếu chưa giải quyết vấn đề mà nó đại diện. Xác minh ngữ cảnh trước khi sửa, hoàn thiện hoặc thay thế marker bằng xử lý cụ thể, bổ sung kiểm thử hồi quy, rồi chạy ./bin/tuzygent verify.",
                            $marker,
                            $relativePath,
                            $lineNumber,
                            $note !== '' ? ' Ghi chú: ' . $note : ''
                        ),
                    ];
                }
            }
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUntestedServices(array $existingTargets): array
    {
        $testedServiceNames = [];
        foreach ($this->collectPhpFiles(['tests']) as $testFile) {
            $source = file_get_contents($testFile);
            if (!is_string($source)) {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                $shortName = substr($name, strrpos('\\' . $name, '\\'));
                $testedServiceNames[strtolower($shortName)] = true;
            }
        }

        $topics = [];
        $serviceDir = $this->basePath . '/src/Application/Service';
        foreach (glob($serviceDir . '/*Service.php') ?: [] as $serviceFile) {
            $className = basename($serviceFile, '.php');
            if (isset($testedServiceNames[strtolower($className)])) {
                continue;
            }

            $targetName = 'CmsCoverage' . $className;
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $relativePath = $this->relativePath($serviceFile);
            $topics[] = [
                'title' => "[CMS Topic P1] Bổ sung kiểm thử hồi quy cho {$className}",
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    "Bộ phát hiện mã nguồn tìm thấy Application Service %s nhưng không thấy tên lớp trong các tệp tests/*.php. Kiểm tra các use case công khai và adapter liên quan, sau đó bổ sung kiểm thử hồi quy có assertion thực chất cho %s. Đảm bảo kiểm thử được gọi bởi tests/hexagon_test.php hoặc được tích hợp vào cổng Gate hiện tại. Không thay đổi hành vi sản phẩm nếu chưa cần thiết. Chạy ./bin/tuzygent verify.",
                    $relativePath,
                    $className
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findLargeSourceFiles(array $existingTargets): array
    {
        $files = [];
        foreach ($this->collectPhpFiles(['src', 'public', 'config']) as $file) {
            $lineCount = count(file($file, FILE_IGNORE_NEW_LINES) ?: []);
            $files[] = ['path' => $file, 'lines' => $lineCount];
        }
        if (count($files) < 5) {
            return [];
        }

        $sizes = array_column($files, 'lines');
        sort($sizes, SORT_NUMERIC);
        $p90Index = min(count($sizes) - 1, (int) floor(count($sizes) * 0.90));
        $threshold = max(180, $sizes[$p90Index]);

        usort($files, static fn(array $a, array $b): int => $b['lines'] <=> $a['lines']);
        $topics = [];
        foreach ($files as $file) {
            if ($file['lines'] <= $threshold || count($topics) >= 5) {
                continue;
            }

            $relativePath = $this->relativePath($file['path']);
            $targetName = 'CmsRefactor' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát tách trách nhiệm cho %s (%d dòng)', $relativePath, $file['lines']),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    "Phân tích tệp %s có %d dòng, nằm trên ngưỡng P90 hiện tại của source PHP (%d dòng). Xác định các trách nhiệm/use case độc lập có thể tách an toàn theo Hexagonal Architecture. Chỉ refactor khi có ranh giới rõ; giữ nguyên public behavior, thêm hoặc cập nhật kiểm thử hồi quy, không tạo lớp chỉ để giảm số dòng. Chạy ./bin/tuzygent verify.",
                    $relativePath,
                    $file['lines'],
                    $threshold
                ),
            ];
        }

        return $topics;
    }

    /** @param string[] $relativeDirs
     *  @return string[]
     */
    private function collectPhpFiles(array $relativeDirs): array
    {
        $files = [];
        foreach ($relativeDirs as $relativeDir) {
            $directory = $this->basePath . '/' . trim($relativeDir, '/');
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            /** @var SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    $path = $fileInfo->getRealPath();
                    if ($path !== false) {
                        $files[] = $path;
                    }
                }
            }
        }
        sort($files);
        return $files;
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($this->basePath)), '/');
    }
}
