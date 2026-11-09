<?php

declare(strict_types=1);

namespace App\Application\Service;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds source-grounded CMS maintenance topics instead of relying only on a fixed module list.
 * The signals are intentionally conservative and produce review candidates for source risks,
 * missing integration-test references, and source files that are statistical size outliers.
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
            $this->findDomainPurityViolations($existingTargets),
            $this->findDirectDatabaseAccessOutsideAdapters($existingTargets),
            $this->findUnescapedRichHtmlOutputs($existingTargets),
            $this->findImagesWithoutAltText($existingTargets),
            $this->findFileInputsWithoutMultipartForms($existingTargets),
            $this->findDuplicateHtmlIds($existingTargets),
            $this->findUnresolvedLocalFragments($existingTargets),
            $this->findUnassociatedFormControls($existingTargets),
            $this->findInteractiveElementsWithoutNames($existingTargets),
            $this->findFormControlsWithoutNames($existingTargets),
            $this->findFormButtonsWithoutExplicitType($existingTargets),
            $this->findFormsWithoutCsrfTokens($existingTargets),
            $this->findPotentiallyMutatingGetRoutes($existingTargets),
            $this->findPredictableOrderCodes($existingTargets),
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
    private function findPotentiallyMutatingGetRoutes(array $existingTargets): array
    {
        $routeFile = $this->basePath . '/public/index.php';
        $source = is_file($routeFile) ? file_get_contents($routeFile) : false;
        if (!is_string($source)) {
            return [];
        }

        // Method-name heuristics deliberately favor review candidates over conclusions:
        // a handler body must still be inspected before treating a route as unsafe.
        // `create` is omitted because this application uses it for GET form pages and
        // performs writes in separate POST `save` handlers.
        $mutatingActionPattern = '/(?:add|approve|change|delete|disable|enable|logout|publish|remove|restore|rollback|run|save|set|submit|toggle|update|upload)(?:[A-Z_]|$)/';
        preg_match_all(
            '~\$router\s*->\s*get\s*\(\s*([\'\"])(/[^\'\"]*)\1\s*,\s*\[\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*,\s*([\'\"])([a-zA-Z_][a-zA-Z0-9_]*)\3\s*\]~s',
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
                'title' => sprintf('[CMS Topic P0] Xác minh route GET %s → %s', $routePath, $action),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    "AutoTopic phát hiện route GET %s được gắn với handler %s. Tên handler gợi ý đây có thể là thao tác đổi trạng thái, nhưng cần đọc implementation để xác minh. GET phải giữ semantics chỉ đọc; nếu handler thay đổi dữ liệu/session, chuyển route sang POST (hoặc method mutation phù hợp), bảo vệ theo CSRF guard tập trung, cập nhật form/link và thêm hồi quy. Nếu handler chỉ đọc, ghi rõ lý do và không sửa runtime behavior. Chạy ./bin/tuzygent verify.",
                    $routePath,
                    $action
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findPredictableOrderCodes(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Application/Service']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Keep this heuristic scoped to order identifiers so deterministic
            // hashes and non-security uses of pseudo-random numbers are ignored.
            preg_match_all(
                '/\$orderCode\s*=\s*([^;]+);/i',
                $source,
                $assignments,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            );
            $weakAssignments = [];
            foreach ($assignments as $assignment) {
                $expression = $assignment[1][0];
                preg_match_all('/\b(uniqid|mt_rand|rand)\s*\(/i', $expression, $weakCalls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                if ($weakCalls === []) {
                    continue;
                }

                foreach ($weakCalls as $weakCall) {
                    $weakCallOffset = $assignment[1][1] + $weakCall[0][1];
                    $weakAssignments[] = [
                        'line' => substr_count(substr($source, 0, $weakCallOffset), "\n") + 1,
                        'function' => strtolower($weakCall[1][0]),
                    ];
                }
            }
            if ($weakAssignments === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsWeakOrderCode' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $lines = array_column($weakAssignments, 'line');
            $functions = array_values(array_unique(array_column($weakAssignments, 'function')));
            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát entropy mã đơn hàng tại %s:%d', $relativePath, $lines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic phát hiện biểu thức gán $orderCode dùng %s tại %s (dòng: %s). Các hàm này không cung cấp entropy mật mã đáng tin cậy; mã đơn hàng hiện là identifier tham chiếu, detector không kết luận đây là credential hay lỗ hổng truy cập. Kiểm tra yêu cầu uniqueness, khả năng va chạm/dò đoán và các kiểm soát truy cập; nếu cần mã khó đoán hơn, dùng CSPRNG như random_bytes() và giữ xử lý collision phù hợp. Đây là source heuristic, xác minh ngữ cảnh runtime trước khi sửa. Thêm hồi quy nếu phù hợp rồi chạy ./bin/tuzygent verify.',
                    implode(', ', $functions),
                    $relativePath,
                    implode(', ', $lines)
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

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findDomainPurityViolations(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Domain']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $violations = [];
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }

                $tokenType = $token[0];
                $tokenText = $token[1];
                if ($tokenType === T_VARIABLE && in_array($tokenText, ['$_POST', '$_SESSION'], true)) {
                    $violations[$tokenText] = true;
                    continue;
                }

                $nameTokenTypes = [T_STRING];
                foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $nameToken) {
                    if (defined($nameToken)) {
                        $nameTokenTypes[] = constant($nameToken);
                    }
                }
                if (!in_array($tokenType, $nameTokenTypes, true)) {
                    continue;
                }

                $normalizedName = ltrim($tokenText, '\\');
                if (strcasecmp($normalizedName, 'PDO') === 0) {
                    $violations['PDO'] = true;
                } elseif (preg_match('/^App\\\\(?:Infrastructure|Presentation)(?:\\\\|$)/i', $normalizedName) === 1) {
                    $violations[str_starts_with(strtolower($normalizedName), 'app\\infrastructure') ? 'App\\Infrastructure' : 'App\\Presentation'] = true;
                }
            }

            if ($violations === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsDomainPurity' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $signals = implode(', ', array_keys($violations));
            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Xem xét phụ thuộc ngoài Domain tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic phát hiện tham chiếu mã PHP thuộc src/Domain tại %s có tín hiệu bị cấm: %s. Xác minh ngữ cảnh và chuyển phụ thuộc sang outbound port/adapter phù hợp; Domain không được phụ thuộc PDO, App\\Infrastructure, App\\Presentation, $_POST hoặc $_SESSION. Bổ sung hồi quy và chạy ./bin/tuzygent verify.',
                    $relativePath,
                    $signals
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findDirectDatabaseAccessOutsideAdapters(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Application', 'src/Presentation']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $signals = [];
            foreach ($tokens as $index => $token) {
                if (!is_array($token)) {
                    continue;
                }

                $tokenType = $token[0];
                $tokenText = ltrim($token[1], '\\');
                $isNameToken = $tokenType === T_STRING;
                foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $nameToken) {
                    if (defined($nameToken) && $tokenType === constant($nameToken)) {
                        $isNameToken = true;
                        break;
                    }
                }

                if ($isNameToken && strcasecmp($tokenText, 'PDO') === 0) {
                    $signals['PDO'] = true;
                    continue;
                }

                if ($tokenType !== T_STRING || !in_array(strtolower($tokenText), ['query', 'prepare', 'exec'], true)) {
                    continue;
                }
                $previous = $this->previousSignificantToken($tokens, $index - 1);
                if ($previous !== null && in_array($this->tokenText($tokens[$previous]), ['->', '?->'], true)) {
                    $signals['->' . strtolower($tokenText) . '()'] = true;
                }
            }

            if ($signals === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsDirectDatabase' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Xác minh truy cập database ngoài adapter tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic tìm thấy dấu hiệu truy cập database trong %s: %s. Quy tắc repository yêu cầu mọi thao tác database đi qua outbound port trong src/Domain/Port/Outbound/ và SQLite adapter trong src/Infrastructure/Persistence/Sqlite/. Xác minh kiểu đối tượng và ngữ cảnh trước khi sửa; nếu đây là truy cập database trực tiếp, chuyển thao tác qua port/adapter và bổ sung hồi quy. Nếu không phải database access, ghi rõ lý do và không đổi behavior. Chạy ./bin/tuzygent verify.',
                    $relativePath,
                    implode(', ', array_keys($signals))
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnescapedRichHtmlOutputs(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $richHtmlProperties = [];
            for ($index = 0, $count = count($tokens); $index < $count; $index++) {
                if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_OPEN_TAG_WITH_ECHO) {
                    continue;
                }

                $expression = [];
                for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                    if (is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_CLOSE_TAG) {
                        break;
                    }
                    $expression[] = $tokens[$cursor];
                }

                $escapeScopes = [];
                $pendingEscapeCall = false;
                for ($cursor = 0, $expressionCount = count($expression); $cursor < $expressionCount; $cursor++) {
                    $token = $expression[$cursor];
                    $tokenText = $this->tokenText($token);
                    if ($tokenText === '(') {
                        $escapeScopes[] = ($escapeScopes !== [] && $escapeScopes[array_key_last($escapeScopes)]) || $pendingEscapeCall;
                        $pendingEscapeCall = false;
                        continue;
                    }
                    if ($tokenText === ')') {
                        array_pop($escapeScopes);
                        $pendingEscapeCall = false;
                        continue;
                    }

                    if (!is_array($token)) {
                        continue;
                    }

                    $isNameToken = $token[0] === T_STRING;
                    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $nameToken) {
                        if (defined($nameToken) && $token[0] === constant($nameToken)) {
                            $isNameToken = true;
                            break;
                        }
                    }
                    if (!$isNameToken) {
                        continue;
                    }

                    $name = strtolower(ltrim($token[1], '\\'));
                    $next = $this->nextSignificantToken($expression, $cursor + 1);
                    if (in_array($name, ['htmlspecialchars', 'htmlentities'], true)
                        && $next !== null
                        && $this->tokenText($expression[$next]) === '('
                    ) {
                        $pendingEscapeCall = true;
                        continue;
                    }

                    if ($token[0] !== T_STRING) {
                        continue;
                    }
                    $property = strtolower($token[1]);
                    $previous = $this->previousSignificantToken($expression, $cursor - 1);
                    if (!in_array($property, ['content', 'description'], true)
                        || $previous === null
                        || !in_array($this->tokenText($expression[$previous]), ['->', '?->'], true)
                        || in_array(true, $escapeScopes, true)
                    ) {
                        continue;
                    }
                    $richHtmlProperties[$property][$token[2] ?? 0] = true;
                }
            }

            if ($richHtmlProperties === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $properties = array_keys($richHtmlProperties);
            sort($properties);
            $targetName = 'CmsRawHtml' . substr(sha1($relativePath . ':' . implode(',', $properties)), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $locations = [];
            foreach ($richHtmlProperties as $property => $lines) {
                foreach (array_keys($lines) as $line) {
                    $locations[] = sprintf('$…->%s tại dòng %d', $property, $line);
                }
            }
            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát render rich HTML động tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic phát hiện thuộc tính nội dung HTML được render trực tiếp trong template %s (%s). Xác minh nguồn dữ liệu, ngữ cảnh HTML và chính sách nội dung. Nếu giá trị là text, dùng HTML escaping phù hợp; nếu cần cho phép HTML biên tập, dùng sanitizer được duy trì và kiểm thử payload nguy hiểm. Không kết luận lỗ hổng chỉ từ tín hiệu tĩnh và không làm mất định dạng nội dung hợp lệ. Bổ sung hồi quy, rồi chạy ./bin/tuzygent verify.',
                    $relativePath,
                    implode(', ', $locations)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnassociatedFormControls(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= str_repeat("\n", substr_count($token[1], "\n"));
                }
            }

            $unassociatedCount = 0;
            preg_match_all('/<form\b[^>]*>(.*?)<\/form\s*>/is', $html, $forms);
            foreach ($forms[1] as $formHtml) {
                preg_match_all('/<label\b([^>]*)>(.*?)<\/label\s*>/is', $formHtml, $labels, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                $labelRanges = [];
                $labelForValues = [];
                foreach ($labels as $label) {
                    $attributes = $this->parseHtmlAttributes($label[1][0]);
                    if (isset($attributes['for']) && $attributes['for'] !== '') {
                        $labelForValues[$attributes['for']] = true;
                    }
                    $labelRanges[] = [$label[0][1], $label[0][1] + strlen($label[0][0])];
                }

                preg_match_all('/<(input|select|textarea)\b([^>]*)>/is', $formHtml, $controls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($controls as $control) {
                    $tagName = strtolower($control[1][0]);
                    $attributes = $this->parseHtmlAttributes($control[2][0]);
                    if ($tagName === 'input' && in_array(strtolower($attributes['type'] ?? 'text'), ['hidden', 'submit', 'reset', 'button', 'image'], true)) {
                        continue;
                    }
                    if (($attributes['aria-label'] ?? '') !== '' || ($attributes['aria-labelledby'] ?? '') !== '') {
                        continue;
                    }
                    if (($attributes['id'] ?? '') !== '' && isset($labelForValues[$attributes['id']])) {
                        continue;
                    }

                    $controlOffset = $control[0][1];
                    $hasWrappingLabel = false;
                    foreach ($labelRanges as [$start, $end]) {
                        if ($controlOffset > $start && $controlOffset < $end) {
                            $hasWrappingLabel = true;
                            break;
                        }
                    }
                    if (!$hasWrappingLabel) {
                        $unassociatedCount++;
                    }
                }
            }

            if ($unassociatedCount === 0) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsFormLabels' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát nhãn cho control biểu mẫu tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d control nhập liệu trong form tại %s chưa có liên kết label[for]/id, label bao quanh, aria-label hoặc aria-labelledby trong source tĩnh. Xác minh markup sau khi render và cung cấp nhãn hiển thị cùng quan hệ truy cập phù hợp; có thể dùng label[for] trỏ tới id hoặc label bao quanh control. Detector không kết luận vi phạm WCAG và không tự sửa template. Bổ sung hồi quy nếu cần, rồi chạy ./bin/tuzygent verify.',
                    $unassociatedCount,
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findInteractiveElementsWithoutNames(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Keep literal template HTML while making PHP expressions explicit so
            // dynamic labels are not mistaken for empty accessible names.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= '__TUZY_DYNAMIC__' . str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $unnamed = [];
            preg_match_all('/<(a|button)\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $elements, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($elements as $element) {
                $tagName = strtolower($element[1][0]);
                $openingTag = $element[0][0];
                $openingOffset = $element[0][1];
                if (str_contains($openingTag, '__TUZY_DYNAMIC__')) {
                    continue;
                }
                $attributes = $this->parseHtmlAttributes(substr($openingTag, strlen($tagName) + 1, -1));
                if ($tagName === 'a' && !array_key_exists('href', $attributes)) {
                    continue;
                }

                $closingOffset = stripos($html, '</' . $tagName, $openingOffset + strlen($openingTag));
                if ($closingOffset === false) {
                    continue;
                }
                $closingEnd = strpos($html, '>', $closingOffset);
                if ($closingEnd === false) {
                    continue;
                }
                $innerHtml = substr($html, $openingOffset + strlen($openingTag), $closingOffset - ($openingOffset + strlen($openingTag)));
                if (str_contains($innerHtml, '__TUZY_DYNAMIC__')) {
                    continue;
                }

                $hasExplicitName = false;
                foreach (['aria-label', 'aria-labelledby', 'title'] as $nameAttribute) {
                    if (isset($attributes[$nameAttribute]) && trim($attributes[$nameAttribute]) !== '') {
                        $hasExplicitName = true;
                        break;
                    }
                }
                if ($hasExplicitName) {
                    continue;
                }

                preg_match_all('/<img\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $innerHtml, $images);
                foreach ($images[0] as $imageTag) {
                    $imageAttributes = $this->parseHtmlAttributes(substr($imageTag, 4, -1));
                    if (isset($imageAttributes['alt']) && trim($imageAttributes['alt']) !== '') {
                        $hasExplicitName = true;
                        break;
                    }
                }
                if ($hasExplicitName) {
                    continue;
                }

                $textContent = html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($textContent) !== '') {
                    continue;
                }

                $unnamed[] = substr_count(substr($html, 0, $openingOffset), "\n") + 1;
            }

            if ($unnamed === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsInteractiveName' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát tên truy cập được tại %s:%d', $relativePath, $unnamed[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d liên kết hoặc nút có nội dung literal rỗng trong template %s (dòng: %s) và không thấy aria-label, aria-labelledby, title hoặc alt ảnh tĩnh làm nguồn tên. Kiểm tra markup sau khi render và bảo đảm mỗi điều khiển có accessible name mô tả hành động/đích đến. Tín hiệu source tĩnh này không kết luận WCAG conformance; detector bỏ qua PHP expression động và không đánh giá chất lượng của tên hiện hữu. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($unnamed),
                    $relativePath,
                    implode(', ', $unnamed)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findFormButtonsWithoutExplicitType(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= str_repeat("\n", substr_count($token[1], "\n"));
                }
            }

            $lineNumbers = [];
            preg_match_all('~<form\b[^>]*>(.*?)</form\s*>~is', $html, $forms, PREG_OFFSET_CAPTURE);
            foreach ($forms[1] as $formMatch) {
                [$formBody, $formOffset] = $formMatch;
                preg_match_all('~<button\b([^>]*)>~is', $formBody, $buttons, PREG_OFFSET_CAPTURE);
                foreach ($buttons[1] as $buttonMatch) {
                    [$attributes, $buttonOffset] = $buttonMatch;
                    if (preg_match('/(?:^|\s)type\s*=/i', $attributes) === 1) {
                        continue;
                    }
                    $lineNumbers[] = substr_count(substr($html, 0, $formOffset + $buttonOffset), "\n") + 1;
                }
            }

            if ($lineNumbers === []) {
                continue;
            }
            $relativePath = $this->relativePath($file);
            $targetName = 'CmsButtonType' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }
            $lineNumbers = array_values(array_unique($lineNumbers));
            sort($lineNumbers);
            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát button trong form thiếu type tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic phát hiện %d thẻ <button> trong markup form tại %s (dòng: %s) không có thuộc tính type tường minh. Theo HTML, button liên kết với form mặc định có hành vi submit. Xác minh ý định từng nút; thêm type="button" cho điều khiển không submit hoặc type="submit" nếu chủ ý gửi form. Kiểm tra markup động/điều kiện render và bổ sung hồi quy phù hợp. Đây là tín hiệu source tĩnh, không phải kết luận lỗi chức năng; không đổi behavior nếu chưa xác minh. Chạy ./bin/tuzygent verify.',
                    count($lineNumbers),
                    $relativePath,
                    implode(', ', $lineNumbers)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findFormControlsWithoutNames(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Keep literal HTML, and preserve dynamic attributes as opaque markers
            // so a PHP-built name is not reported as an empty one.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= '__TUZY_DYNAMIC__' . str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $missingNameLines = [];
            preg_match_all('~<form\\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)</form\\s*>~is', $html, $forms, PREG_OFFSET_CAPTURE);
            foreach ($forms[1] as [$formHtml, $formOffset]) {
                preg_match_all('~<(input|select|textarea)\\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~is', $formHtml, $controls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($controls as $control) {
                    $tagName = strtolower($control[1][0]);
                    $attributes = $this->parseHtmlAttributes($control[2][0]);
                    if ($tagName === 'input' && in_array(strtolower($attributes['type'] ?? 'text'), ['submit', 'reset', 'button', 'image'], true)) {
                        continue;
                    }

                    $name = $attributes['name'] ?? null;
                    if ($name !== null && (trim($name) !== '' || str_contains($name, '__TUZY_DYNAMIC__'))) {
                        continue;
                    }

                    $offset = $formOffset + $control[0][1];
                    $missingNameLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
                }
            }

            if ($missingNameLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsFormNames' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $missingNameLines = array_values(array_unique($missingNameLines));
            sort($missingNameLines);
            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát form control thiếu name tại %s:%d', $relativePath, $missingNameLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input/select/textarea trong form tại %s (dòng: %s) không có name tĩnh khác rỗng. Theo quy tắc HTML form data, control cần gửi phải có tên để tạo cặp name=value. Xác minh từng trường có thực sự cần gửi hay được JavaScript bổ sung; thêm name phù hợp nếu thiếu thật. Bỏ qua nút điều khiển submit/reset/button/image, thuộc tính name động và liên kết form="id" ngoài form bao quanh. Đây là tín hiệu source tĩnh, không phải kết luận lỗi runtime; không tự sửa template. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($missingNameLines),
                    $relativePath,
                    implode(', ', $missingNameLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findImagesWithoutAltText(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect only template HTML, leaving PHP expressions and strings out of
            // the scan. Keep line breaks from PHP tokens so locations remain useful.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            preg_match_all('/<img\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>/is', $html, $images, PREG_OFFSET_CAPTURE);
            $missingAltLines = [];
            foreach ($images[0] as [$imageTag, $offset]) {
                if (preg_match('/\s+alt(?=\s|=|\/?>)/i', $imageTag) === 1) {
                    continue;
                }
                $missingAltLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
            }

            if ($missingAltLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsImageAlt' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát ảnh thiếu alt tại %s:%d', $relativePath, $missingAltLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d thẻ img trong template %s không khai báo thuộc tính alt (dòng: %s). Kiểm tra markup sau khi render và mục đích từng ảnh: cung cấp mô tả ngắn cho ảnh truyền đạt thông tin/chức năng, hoặc alt="" cho ảnh trang trí. Không tự tạo nội dung alt; detector chỉ kiểm tra sự hiện diện của thuộc tính, không đánh giá chất lượng hay kết luận tuân thủ WCAG. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($missingAltLines),
                    $relativePath,
                    implode(', ', $missingAltLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findFileInputsWithoutMultipartForms(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Preserve literal template markup and line positions while masking PHP
            // expressions so dynamic form settings are left for manual review.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $invalidForms = [];
            preg_match_all('/<form\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)<\/form\s*>/is', $html, $forms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($forms as $form) {
                $attributes = $this->parseHtmlAttributes($form[1][0]);
                $method = strtoupper(trim($attributes['method'] ?? ''));
                $enctype = strtolower(trim($attributes['enctype'] ?? ''));

                // PHP-generated method/enctype values may be valid at runtime; do not
                // create a finding from incomplete static evidence.
                if (str_contains($method, "\x01") || str_contains($enctype, "\x01")) {
                    continue;
                }

                preg_match_all('/<input\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $form[2][0], $inputs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($inputs as $input) {
                    $inputAttributes = $this->parseHtmlAttributes($input[1][0]);
                    if (strtolower(trim($inputAttributes['type'] ?? '')) !== 'file') {
                        continue;
                    }

                    if ($method === 'POST' && $enctype === 'multipart/form-data') {
                        continue;
                    }

                    $lineOffset = $form[2][1] + $input[0][1];
                    $invalidForms[] = substr_count(substr($html, 0, $lineOffset), "\n") + 1;
                }
            }

            if ($invalidForms === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsFileForm' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát form tải tệp tại %s:%d', $relativePath, $invalidForms[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input type="file" trong form tại %s (dòng: %s) nhưng markup tĩnh không xác nhận đồng thời method="POST" và enctype="multipart/form-data". Kiểm tra form sau khi render và cấu hình phương thức/encoding theo luồng tải tệp của ứng dụng; xác minh JavaScript hoặc cấu hình động không bổ sung hành vi còn thiếu. Detector chỉ ghép control với form bao quanh trong cùng template, bỏ qua form/input liên kết ngoài bằng thuộc tính form và không phân tích upload JavaScript. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($invalidForms),
                    $relativePath,
                    implode(', ', $invalidForms)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findDuplicateHtmlIds(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Retain literal template markup and line positions while marking PHP
            // expressions so a partially dynamic ID is never treated as static.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            /** @var array<string, int[]> $idLines */
            $idLines = [];
            preg_match_all('/<[a-z][a-z0-9:-]*\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                $attributes = $this->parseHtmlAttributes($tag[1][0]);
                if (!array_key_exists('id', $attributes)) {
                    continue;
                }

                $id = $attributes['id'];
                if ($id === '' || str_contains($id, "\x01") || preg_match('/[\x00-\x20\x7f]/', $id) === 1) {
                    continue;
                }

                $line = substr_count(substr($html, 0, $tag[0][1]), "\n") + 1;
                $idLines[$id][] = $line;
            }

            foreach ($idLines as $id => $lines) {
                if (count($lines) < 2) {
                    continue;
                }

                $relativePath = $this->relativePath($file);
                $targetName = 'CmsDuplicateId' . substr(sha1($relativePath . ':' . $id), 0, 12);
                if (isset($existingTargets[strtolower($targetName)])) {
                    continue;
                }

                $topics[] = [
                    'title' => sprintf('[CMS Topic P2] Rà soát id HTML trùng "%s" tại %s:%d', $id, $relativePath, $lines[1]),
                    'target_name' => $targetName,
                    'priority' => 'P2',
                    'specification' => sprintf(
                        'AutoTopic tìm thấy id literal "%s" lặp %d lần trong template %s (dòng: %s). Kiểm tra điều kiện render và trang sau khi ghép layout; nếu các phần tử cùng xuất hiện, đổi ID phù hợp và cập nhật mọi tham chiếu label[for], ARIA, fragment, CSS hoặc JavaScript. Detector bỏ qua giá trị có PHP expression, comment HTML và không đối chiếu giữa nhiều template; đây là gợi ý rà soát source, không phải xác nhận HTML runtime không hợp lệ. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                        $id,
                        count($lines),
                        $relativePath,
                        implode(', ', $lines)
                    ),
                ];
            }
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnresolvedLocalFragments(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Keep only literal template markup. Mask PHP expressions to avoid
            // treating partially dynamic hrefs or IDs as reliable evidence.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            /** @var array<string, true> $ids */
            $ids = [];
            /** @var array<string, int[]> $fragmentLines */
            $fragmentLines = [];
            preg_match_all('/<[a-z][a-z0-9:-]*\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($tags as $tag) {
                $attributes = $this->parseHtmlAttributes($tag[1][0]);
                $id = $attributes['id'] ?? '';
                if ($id !== '' && !str_contains($id, "\x01") && preg_match('/[\x00-\x20\x7f]/', $id) !== 1) {
                    $ids[$id] = true;
                }

                $href = $attributes['href'] ?? '';
                if (!str_starts_with($href, '#') || strlen($href) < 2 || str_contains($href, "\x01")
                    || preg_match('/[\x00-\x20\x7f%]/', substr($href, 1)) === 1
                ) {
                    continue;
                }

                $fragment = substr($href, 1);
                $line = substr_count(substr($html, 0, $tag[0][1]), "\n") + 1;
                $fragmentLines[$fragment][] = $line;
            }

            $missingFragments = [];
            foreach ($fragmentLines as $fragment => $lines) {
                if (!isset($ids[$fragment])) {
                    $missingFragments[(string) $fragment] = $lines;
                }
            }
            if ($missingFragments === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            foreach ($missingFragments as $fragment => $lines) {
                $targetName = 'CmsMissingFragment' . substr(sha1($relativePath . ':' . $fragment), 0, 12);
                if (isset($existingTargets[strtolower($targetName)])) {
                    continue;
                }

                $topics[] = [
                    'title' => sprintf('[CMS Topic P2] Kiểm tra fragment #%s không có đích tại %s:%d', $fragment, $relativePath, $lines[0]),
                    'target_name' => $targetName,
                    'priority' => 'P2',
                    'specification' => sprintf(
                        'AutoTopic tìm thấy href fragment literal "#%s" tại %s (dòng: %s), nhưng không thấy id literal tương ứng trong cùng template. Kiểm tra DOM sau khi render và layout/partial được ghép vào trang; nếu đích thực sự thiếu, sửa href hoặc thêm đích phù hợp và cập nhật các tham chiếu liên quan. Detector bỏ qua PHP động, HTML comments, fragment mã hóa phần trăm và các template khác; đây là gợi ý rà soát source, không phải kết luận trang runtime bị hỏng. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                        $fragment,
                        $relativePath,
                        implode(', ', $lines)
                    ),
                ];
            }
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findFormsWithoutCsrfTokens(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $missingTokenCount = 0;
            preg_match_all('/<form\\b([^>]*)>(.*?)<\\/form\\s*>/is', $source, $forms, PREG_SET_ORDER);
            foreach ($forms as $form) {
                $attributes = $this->parseHtmlAttributes($form[1]);
                $method = strtoupper(trim($attributes['method'] ?? ''));
                if ($method === '' || $method === 'GET') {
                    continue;
                }

                // The application convention emits a hidden _csrf_token field either
                // directly or through Csrf::field(). Dynamic partials remain a review case.
                $formBody = $form[2];
                $hasTokenField = preg_match('/\\bname\\s*=\\s*(["\\\']?)_csrf_token\\1/i', $formBody) === 1;
                $usesCsrfHelper = preg_match('/\\bCsrf\\s*::\\s*field\\s*\\(/i', $formBody) === 1;
                if (!$hasTokenField && !$usesCsrfHelper) {
                    $missingTokenCount++;
                }
            }

            if ($missingTokenCount === 0) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsCsrfForms' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Xác minh CSRF token cho form tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic phát hiện %d form có method khác GET tại %s nhưng source của form không chứa hidden field _csrf_token hoặc lời gọi Csrf::field(). Router hiện từ chối request non-GET nếu thiếu token. Xác minh markup sau khi render và kiểm tra partial/helper hoặc cơ chế header có thể không hiện diện trong file; nếu form thực sự thiếu token, thêm token theo convention và kiểm thử hồi quy. Đây là heuristic source scan, không phải kết luận lỗ hổng. Chạy ./bin/tuzygent verify.',
                    $missingTokenCount,
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** @return array<string, string> */
    private function parseHtmlAttributes(string $source): array
    {
        preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $source, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as $match) {
            $value = ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            $attributes[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        }
        return $attributes;
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
