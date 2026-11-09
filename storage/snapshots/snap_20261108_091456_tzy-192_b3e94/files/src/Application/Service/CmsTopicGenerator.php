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
    private const MAX_TOPICS_PER_DISCOVERY = 10;

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
            $this->findFilesWithoutStrictTypes($existingTargets),
            $this->findPotentiallyMutatingAdminGetRoutes($existingTargets),
            $this->findUntestedServices($existingTargets),
            $this->findRepositoryPortsWithoutSqliteAdapters($existingTargets),
            $this->findLargeSourceFiles($existingTargets)
        );

        $priorityOrder = ['P0' => 0, 'P1' => 1, 'P2' => 2];
        usort($topics, static fn(array $a, array $b): int =>
            ($priorityOrder[$a['priority']] ?? 9) <=> ($priorityOrder[$b['priority']] ?? 9)
                ?: strcmp($a['title'], $b['title'])
        );

        // Defer lower-ranked targets to later discovery cycles instead of flooding the backlog.
        return array_slice($topics, 0, self::MAX_TOPICS_PER_DISCOVERY);
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnfinishedMarkers(array $existingTargets): array
    {
        $topics = [];
        $seenTargets = $existingTargets;
        foreach ($this->collectSourceFiles(['src', 'public', 'config'], ['php', 'js', 'css']) as $file) {
            $relativePath = $this->relativePath($file);
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            foreach ($this->extractComments($source, $extension) as $comment) {
                preg_match_all('/\b(TODO|FIXME|HACK|XXX)\b\s*:?\s*([^\r\n*]*)/i', $comment['text'], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $match) {
                    $marker = strtoupper($match[1][0]);
                    $note = trim(preg_replace('/\s+/', ' ', $match[2][0]) ?? $match[2][0]);
                    $lineNumber = $comment['line'] + substr_count(substr($comment['text'], 0, $match[0][1]), "\n");
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
    private function findPotentiallyMutatingAdminGetRoutes(array $existingTargets): array
    {
        $routeFile = $this->basePath . '/public/index.php';
        $source = is_file($routeFile) ? file_get_contents($routeFile) : false;
        if (!is_string($source)) {
            return [];
        }

        // Method-name heuristics deliberately favor review candidates over conclusions:
        // a handler body must still be inspected before treating a route as unsafe.
        $mutatingActionPattern = '/(?:add|approve|change|create|delete|disable|enable|logout|publish|remove|restore|rollback|run|save|set|submit|toggle|update|upload)(?:[A-Z_]|$)/';
        preg_match_all(
            '~\$router\s*->\s*get\s*\(\s*([\'\"])(/admin/[^\'\"]*)\1\s*,\s*\[\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*,\s*([\'\"])([a-zA-Z_][a-zA-Z0-9_]*)\3\s*\]~s',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $topics = [];
        foreach ($matches as $match) {
            $routePath = $match[2];
            $action = $match[4];
            if (preg_match($mutatingActionPattern, $action) !== 1) {
                continue;
            }

            $identity = strtolower($routePath . ':' . $action);
            $targetName = 'CmsUnsafeGet' . substr(sha1($identity), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Xác minh route GET quản trị %s → %s', $routePath, $action),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    "AutoTopic phát hiện route GET quản trị %s được gắn với handler %s. Tên handler gợi ý đây có thể là thao tác đổi trạng thái, nhưng cần đọc implementation để xác minh. GET phải giữ semantics chỉ đọc; nếu handler thay đổi dữ liệu/session, chuyển route sang POST (hoặc method mutation phù hợp), bảo vệ theo CSRF guard tập trung, cập nhật form/link và thêm hồi quy. Nếu handler chỉ đọc, ghi rõ lý do và không sửa runtime behavior. Chạy ./bin/tuzygent verify.",
                    $routePath,
                    $action
                ),
            ];
        }

        return $topics;
    }

    /**
     * @return array<int, array{text: string, line: int}>
     */
    private function extractComments(string $source, string $extension): array
    {
        if ($extension === 'php') {
            $comments = [];
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $comments[] = ['text' => $token[1], 'line' => $token[2]];
                    continue;
                }
                if ($token[0] === T_INLINE_HTML) {
                    preg_match_all('/<!--.*?-->/s', $token[1], $matches, PREG_OFFSET_CAPTURE);
                    foreach ($matches[0] as [$commentText, $offset]) {
                        $comments[] = [
                            'text' => $commentText,
                            'line' => $token[2] + substr_count(substr($token[1], 0, $offset), "\n"),
                        ];
                    }
                }
            }
            return $comments;
        }

        // JavaScript and CSS asset markers are intentionally recognized only in
        // standalone comments. This avoids interpreting comment-like text inside
        // literals as unfinished work without needing a full language parser.
        $comments = [];
        $length = strlen($source);
        $line = 1;
        $lineStart = 0;
        $quote = '';
        $escaped = false;
        /** @var array<int, array{mode: 'text'|'expression', depth: int}> $templates */
        $templates = [];
        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];

            // Template text is literal content. Only `${...}` switches back into
            // JavaScript code, where comments can contain actionable markers.
            if ($templates !== [] && $templates[array_key_last($templates)]['mode'] === 'text') {
                if ($char === "\n") {
                    $line++;
                    $lineStart = $index + 1;
                    continue;
                }
                if ($char === '\\') {
                    if (($source[$index + 1] ?? '') === "\n") {
                        $index++;
                        $line++;
                        $lineStart = $index + 1;
                        continue;
                    }
                    $index++;
                    continue;
                }
                if ($char === '`') {
                    array_pop($templates);
                    continue;
                }
                if (substr($source, $index, 2) === '${') {
                    $templates[array_key_last($templates)]['mode'] = 'expression';
                    $templates[array_key_last($templates)]['depth'] = 1;
                    $index++;
                }
                continue;
            }

            if ($char === "\n") {
                $line++;
                $lineStart = $index + 1;
                continue;
            }

            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($extension === 'js' && $char === '`') {
                $templates[] = ['mode' => 'text', 'depth' => 0];
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($extension === 'js' && $templates !== []) {
                $templateIndex = array_key_last($templates);
                if ($char === '{') {
                    $templates[$templateIndex]['depth']++;
                } elseif ($char === '}' && --$templates[$templateIndex]['depth'] === 0) {
                    $templates[$templateIndex]['mode'] = 'text';
                    continue;
                }
            }

            $isLineComment = $extension === 'js' && substr($source, $index, 2) === '//';
            $isBlockComment = substr($source, $index, 2) === '/*';
            if (!$isLineComment && !$isBlockComment) {
                continue;
            }
            if ($extension === 'js' && trim(substr($source, $lineStart, $index - $lineStart)) !== '') {
                continue;
            }

            $commentLine = $line;
            if ($isLineComment) {
                $end = strpos($source, "\n", $index);
                $end = $end === false ? $length : $end;
                $comments[] = ['text' => substr($source, $index, $end - $index), 'line' => $commentLine];
                $index = $end - 1;
                continue;
            }

            $end = strpos($source, '*/', $index + 2);
            $end = $end === false ? $length : $end + 2;
            $text = substr($source, $index, $end - $index);
            $comments[] = ['text' => $text, 'line' => $commentLine];
            $line += substr_count($text, "\n");
            $lastNewline = strrpos($text, "\n");
            $lineStart = $lastNewline === false ? $lineStart : $index + $lastNewline + 1;
            $index = $end - 1;
        }

        return $comments;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findFilesWithoutStrictTypes(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['config', 'src', 'public', 'tests']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source) || $this->hasStrictTypesDeclaration($source)) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsStrictTypes' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Bật strict types cho %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    "AutoTopic phát hiện PHP file %s không có declare(strict_types=1). Xác minh đây là source PHP thuộc repository và directive chưa có trong phần khai báo đầu file; sau đó thêm khai báo theo quy ước dự án, bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.",
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** Detect a file-level directive in the first statement, ignoring comments and whitespace. */
    private function hasStrictTypesDeclaration(string $source): bool
    {
        $tokens = token_get_all($source);
        $index = 0;
        $count = count($tokens);
        while ($index < $count && is_array($tokens[$index]) && $tokens[$index][0] === T_INLINE_HTML) {
            // Text outside PHP tags (including a BOM) executes before the PHP declaration.
            return false;
        }

        if ($index >= $count || !is_array($tokens[$index]) || $tokens[$index][0] !== T_OPEN_TAG) {
            return false;
        }

        $index++;
        $statement = $this->nextSignificantToken($tokens, $index);
        if ($statement === null || !is_array($tokens[$statement]) || $tokens[$statement][0] !== T_DECLARE) {
            return false;
        }

        $directive = '';
        $closingParenthesis = null;
        for ($cursor = $statement + 1; $cursor < $count; $cursor++) {
            $part = $tokens[$cursor];
            $text = is_array($part) ? $part[1] : $part;
            if ($text === ')') {
                $closingParenthesis = $cursor;
                break;
            }
            if (is_array($part) && in_array($part[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $directive .= $text;
        }

        if ($closingParenthesis === null || preg_match('/^\(\s*strict_types\s*=\s*1\s*$/i', $directive) !== 1) {
            return false;
        }

        $terminator = $this->nextSignificantToken($tokens, $closingParenthesis + 1);
        return $terminator !== null && $tokens[$terminator] === ';';
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
            $testedServiceNames += $this->findServiceClassReferences(token_get_all($source));
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
    private function findRepositoryPortsWithoutSqliteAdapters(array $existingTargets): array
    {
        $portDirectory = $this->basePath . '/src/Domain/Port/Outbound';
        $adapterDirectory = $this->basePath . '/src/Infrastructure/Persistence/Sqlite';
        if (!is_dir($portDirectory)) {
            return [];
        }

        $topics = [];
        foreach (glob($portDirectory . '/*RepositoryPort.php') ?: [] as $portFile) {
            $portName = basename($portFile, '.php');
            $entityName = substr($portName, 0, -strlen('RepositoryPort'));
            if ($entityName === '') {
                continue;
            }

            $adapterFile = $adapterDirectory . '/Sqlite' . $entityName . 'Repository.php';
            if (is_file($adapterFile)) {
                continue;
            }

            $relativePath = $this->relativePath($portFile);
            $targetName = 'CmsAdapter' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát SQLite adapter còn thiếu cho %s', $portName),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    "AutoTopic tìm thấy outbound repository port %s nhưng không có adapter theo quy ước tại src/Infrastructure/Persistence/Sqlite/Sqlite%sRepository.php. Xác minh port này thực sự cần persistence SQLite trong kiến trúc hiện tại; nếu cần, triển khai adapter và wiring theo pattern hiện có, bổ sung kiểm thử tích hợp. Nếu port được phục vụ bởi adapter khác hoặc không cần SQLite, ghi rõ lý do và không tạo adapter thừa. Chạy ./bin/tuzygent verify.",
                    $relativePath,
                    $entityName
                ),
            ];
        }

        return $topics;
    }

    /**
     * Find actual class references in test code and resolve file-local class aliases.
     * Comments, strings, import declarations, and unrelated identifiers are ignored.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, bool> Lowercase service class names referenced by the test
     */
    private function findServiceClassReferences(array $tokens): array
    {
        $aliases = [];
        $importTokenIndexes = [];
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_USE) {
                continue;
            }

            $first = $this->nextSignificantToken($tokens, $index + 1);
            if ($first === null || $this->tokenText($tokens[$first]) === '(') {
                continue; // Closure capture, not a namespace import.
            }

            $end = $first;
            $import = '';
            while ($end < $tokenCount && $this->tokenText($tokens[$end]) !== ';') {
                $importTokenIndexes[$end] = true;
                $import .= $this->tokenText($tokens[$end]);
                $end++;
            }
            if ($end < $tokenCount) {
                $importTokenIndexes[$end] = true;
                $index = $end;
            }

            if (preg_match('/^\s*(?:function|const)\s+/i', $import) === 1) {
                continue;
            }

            $importSpecs = [];
            if (preg_match('/^\s*(.*?)\\\\?\s*\{(.*)\}\s*$/', $import, $groupMatch) === 1) {
                $prefix = rtrim(trim($groupMatch[1]), '\\');
                foreach (explode(',', $groupMatch[2]) as $groupItem) {
                    $importSpecs[] = $prefix . '\\' . trim($groupItem);
                }
            } else {
                $importSpecs = explode(',', trim($import));
            }

            foreach ($importSpecs as $importSpec) {
                $parts = preg_split('/\s+as\s+/i', trim($importSpec), 2);
                $className = ltrim(trim($parts[0] ?? ''), '\\');
                if ($className === '') {
                    continue;
                }
                $alias = trim($parts[1] ?? substr($className, strrpos('\\' . $className, '\\')));
                $aliases[strtolower($alias)] = strtolower($className);
            }
        }

        $references = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || isset($importTokenIndexes[$index])) {
                continue;
            }

            $tokenId = $token[0];
            $name = ltrim($token[1], '\\');
            if (in_array($tokenId, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $shortName = substr($name, strrpos('\\' . $name, '\\'));
                if (str_starts_with(strtolower($name), 'app\\application\\service\\') && $this->isClassReference($tokens, $index)) {
                    $references[strtolower($shortName)] = true;
                }
                continue;
            }

            if ($tokenId !== T_STRING) {
                continue;
            }
            $normalized = strtolower($name);
            $importedName = $aliases[$normalized] ?? null;
            // An unqualified class token is not enough to identify an application service:
            // PHP resolves it through the current namespace/import table, and an unrelated
            // class may have the same short name. Only a verified service import counts here.
            $isServiceClass = $importedName !== null
                && str_starts_with($importedName, 'app\\application\\service\\');
            if (!$isServiceClass || !$this->isClassReference($tokens, $index)) {
                continue;
            }

            if ($importedName !== null) {
                $shortName = substr($importedName, strrpos('\\' . $importedName, '\\'));
                $references[strtolower($shortName)] = true;
            } else {
                $references[$normalized] = true;
            }
        }

        return $references;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function isClassReference(array $tokens, int $index): bool
    {
        $previous = $this->previousSignificantToken($tokens, $index - 1);
        $next = $this->nextSignificantToken($tokens, $index + 1);
        if ($previous === null || $next === null) {
            return false;
        }

        $previousToken = $tokens[$previous];
        if (is_array($previousToken) && in_array($previousToken[0], [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_CATCH, T_INSTEADOF], true)) {
            return true;
        }

        return is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON
            && ($classToken = $this->nextSignificantToken($tokens, $next + 1)) !== null
            && is_array($tokens[$classToken])
            && $tokens[$classToken][0] === T_CLASS;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function nextSignificantToken(array $tokens, int $index): ?int
    {
        for ($count = count($tokens); $index < $count; $index++) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $index;
        }
        return null;
    }

    /** @param array<int, array{0: int, 1: string, 2: int}|string> $tokens */
    private function previousSignificantToken(array $tokens, int $index): ?int
    {
        for (; $index >= 0; $index--) {
            if (is_array($tokens[$index]) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $index;
        }
        return null;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function tokenText(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findLargeSourceFiles(array $existingTargets): array
    {
        $files = [];
        foreach ($this->collectPhpFiles(['src', 'public', 'config']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }
            $lineCount = $this->countSignificantLines($source);
            $files[] = ['path' => $file, 'lines' => $lineCount];
        }
        if (count($files) < 5) {
            return [];
        }

        $sizes = array_column($files, 'lines');
        sort($sizes, SORT_NUMERIC);
        // Use the discrete nearest-rank definition: rank = ceil(p * N), converted to zero-based.
        $p90Index = min(count($sizes) - 1, max(0, (int) ceil(count($sizes) * 0.90) - 1));
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

    /** Count lines containing PHP tokens or inline markup, excluding whitespace and comments. */
    private function countSignificantLines(string $source): int
    {
        $lines = [];
        $currentLine = 1;
        foreach (token_get_all($source) as $token) {
            $tokenText = is_array($token) ? $token[1] : $token;
            $tokenId = is_array($token) ? $token[0] : null;
            $ignored = in_array($tokenId, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            $tokenLines = explode("\n", $tokenText);

            if (!$ignored) {
                foreach ($tokenLines as $offset => $lineText) {
                    if (trim($lineText) !== '') {
                        $lines[$currentLine + $offset] = true;
                    }
                }
            }

            $currentLine += count($tokenLines) - 1;
        }

        return count($lines);
    }

    /** @param string[] $relativeDirs
     *  @return string[]
     */
    private function collectPhpFiles(array $relativeDirs): array
    {
        return $this->collectSourceFiles($relativeDirs, ['php']);
    }

    /**
     * @param string[] $relativeDirs
     * @param string[] $extensions
     * @return string[]
     */
    private function collectSourceFiles(array $relativeDirs, array $extensions): array
    {
        $repositoryRoot = realpath($this->basePath);
        if ($repositoryRoot === false) {
            return [];
        }

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
                if ($fileInfo->isFile() && in_array(strtolower($fileInfo->getExtension()), $extensions, true)) {
                    $path = $fileInfo->getRealPath();
                    if ($path !== false && $this->isWithinRepository($path, $repositoryRoot)) {
                        $files[] = $path;
                    }
                }
            }
        }
        sort($files);
        return $files;
    }

    private function isWithinRepository(string $path, string $repositoryRoot): bool
    {
        $rootPrefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $rootPrefix);
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(substr($absolutePath, strlen($this->basePath)), '/');
    }
}
