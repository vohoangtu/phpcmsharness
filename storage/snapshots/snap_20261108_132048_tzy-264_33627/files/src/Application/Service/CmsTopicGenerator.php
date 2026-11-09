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
            $this->findUnserializeCalls($existingTargets),
            $this->findFilesWithoutStrictTypes($existingTargets),
            $this->findFocusRulesWithoutIndicators($existingTargets),
            $this->findDomainPurityViolations($existingTargets),
            $this->findDirectDatabaseAccessOutsideAdapters($existingTargets),
            $this->findUnescapedRichHtmlOutputs($existingTargets),
            $this->findDirectRequestOutput($existingTargets),
            $this->findImagesWithoutAltText($existingTargets),
            $this->findImagesWithoutDimensions($existingTargets),
            $this->findStylesheetsWithoutReducedMotion($existingTargets),
            $this->findSkippedHeadingRanks($existingTargets),
            $this->findHtmlDocumentsWithoutLanguage($existingTargets),
            $this->findBlankTargetsWithoutNoopener($existingTargets),
            $this->findJavascriptOpenersWithoutNoopener($existingTargets),
            $this->findJavascriptHtmlInjectionSinks($existingTargets),
            $this->findFileInputsWithoutMultipartForms($existingTargets),
            $this->findDuplicateHtmlIds($existingTargets),
            $this->findUnresolvedLocalFragments($existingTargets),
            $this->findInternalLinksWithoutRoutes($existingTargets),
            $this->findUnassociatedFormControls($existingTargets),
            $this->findLabelsWithoutMatchingControls($existingTargets),
            $this->findInteractiveElementsWithoutNames($existingTargets),
            $this->findGenericLinkText($existingTargets),
            $this->findAriaHiddenFocusableElements($existingTargets),
            $this->findFormControlsWithoutNames($existingTargets),
            $this->findFormButtonsWithoutExplicitType($existingTargets),
            $this->findFormsWithoutCsrfTokens($existingTargets),
            $this->findPasswordFormsUsingGet($existingTargets),
            $this->findPersonalDataFieldsWithoutAutocomplete($existingTargets),
            $this->findIdentityFieldsWithoutAutocomplete($existingTargets),
            $this->findPotentiallyMutatingGetRoutes($existingTargets),
            $this->findPrepopulatedPasswordFields($existingTargets),
            $this->findWeakSecurityTokenRandomness($existingTargets),
            $this->findWeakPasswordHashing($existingTargets),
            $this->findPasswordFieldsWithoutAutocomplete($existingTargets),
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
    private function findFocusRulesWithoutIndicators(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectSourceFiles(['public/assets/css'], ['css']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect simple CSS rules only; comments and strings cannot create a finding.
            $sourceWithoutComments = preg_replace('~/\\*.*?\\*/~s', '', $source) ?? $source;
            $sourceWithoutStrings = preg_replace('/("(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')/s', '""', $sourceWithoutComments) ?? $sourceWithoutComments;
            preg_match_all('/([^{}]+)\\{([^{}]*)\\}/s', $sourceWithoutStrings, $rules, PREG_SET_ORDER);

            $parsedRules = [];
            foreach ($rules as $rule) {
                $declarations = [];
                foreach (explode(';', $rule[2]) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (count($parts) === 2) {
                        $declarations[strtolower(trim($parts[0]))] = strtolower(trim(preg_replace('/\\s*!important\\s*$/i', '', $parts[1]) ?? $parts[1]));
                    }
                }
                $parsedRules[] = ['selector' => trim($rule[1]), 'declarations' => $declarations];
            }

            $hasUnindicatedFocusRule = false;
            foreach ($parsedRules as $rule) {
                $selector = $rule['selector'];
                if (str_starts_with($selector, '@') || preg_match('/:(?:focus|focus-visible)\\b/i', $selector) !== 1) {
                    continue;
                }

                if ($this->cssRuleRemovesOutline($rule['declarations']) && !$this->cssRuleHasFocusReplacement($rule['declarations'])) {
                    $hasUnindicatedFocusRule = true;
                    break;
                }
            }

            // Content editors can lose the browser's focus ring through a base
            // rule, even when the stylesheet has no :focus selector to inspect.
            if (!$hasUnindicatedFocusRule) {
                foreach ($parsedRules as $rule) {
                    $selector = $rule['selector'];
                    if (str_starts_with($selector, '@')
                        || preg_match('/(?:contenteditable|\\beditor\\b)/i', $selector) !== 1
                        || !$this->cssRuleRemovesOutline($rule['declarations'])) {
                        continue;
                    }

                    $selectorTokens = [];
                    preg_match_all('/\\.[a-zA-Z_][a-zA-Z0-9_-]*|#[a-zA-Z_][a-zA-Z0-9_-]*/', $selector, $selectorTokens);
                    $identityTokens = $selectorTokens[0];
                    if ($identityTokens === []) {
                        $identityTokens = ['contenteditable'];
                    }

                    $hasFocusReplacement = false;
                    foreach ($parsedRules as $focusRule) {
                        if (preg_match('/:(?:focus|focus-visible)\\b/i', $focusRule['selector']) !== 1
                            || !$this->cssRuleHasFocusReplacement($focusRule['declarations'])) {
                            continue;
                        }
                        foreach ($identityTokens as $identityToken) {
                            if (preg_match('/(?<![a-zA-Z0-9_-])' . preg_quote($identityToken, '/') . '(?![a-zA-Z0-9_-])/i', $focusRule['selector']) === 1) {
                                $hasFocusReplacement = true;
                                break 2;
                            }
                        }
                    }

                    if (!$hasFocusReplacement) {
                        $hasUnindicatedFocusRule = true;
                        break;
                    }
                }
            }

            if (!$hasUnindicatedFocusRule) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsFocusIndicator' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát CSS ẩn dấu focus tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic phát hiện rule focus hoặc selector gợi ý content editor trong stylesheet %s đặt outline thành none/0 nhưng không thấy declaration chỉ báo thay thế quen thuộc. W3C WCAG 2.2 SC 2.4.7 yêu cầu có chế độ vận hành mà keyboard focus indicator nhìn thấy được; :focus-visible là một kỹ thuật, không phải cách duy nhất. Kiểm tra giao diện đã render và cascade CSS; một declaration như border hoặc box-shadow chưa tự chứng minh chỉ báo đủ nhìn thấy, và detector không phân tích rule lồng nhau, import, contrast, trạng thái runtime hay JavaScript. Đây là gợi ý rà soát source, không phải kết luận WCAG. Chỉ sửa khi xác nhận vấn đề; bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify. File: %s.',
                    $relativePath,
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, string> $declarations */
    private function cssRuleRemovesOutline(array $declarations): bool
    {
        return in_array($declarations['outline'] ?? '', ['none', '0', '0px', '0em', '0rem'], true)
            || in_array($declarations['outline-style'] ?? '', ['none'], true)
            || in_array($declarations['outline-width'] ?? '', ['0', '0px', '0em', '0rem'], true);
    }

    /** @param array<string, string> $declarations */
    private function cssRuleHasFocusReplacement(array $declarations): bool
    {
        foreach (['outline', 'outline-style', 'outline-width', 'box-shadow', 'border', 'border-color', 'border-width', 'background', 'background-color', 'text-decoration'] as $property) {
            if (isset($declarations[$property]) && !in_array($declarations[$property], ['none', 'transparent', '0', '0px'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findStylesheetsWithoutReducedMotion(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectSourceFiles(['public/assets/css'], ['css']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $sourceWithoutComments = preg_replace('~/\\*.*?\\*/~s', '', $source) ?? $source;
            if (preg_match('/(?:^|[;{])\\s*(?:-[a-z0-9]+-)?(?:transition|animation)\\s*:/i', $sourceWithoutComments) !== 1
                || preg_match('/@media\\s*\\([^)]*prefers-reduced-motion\\s*:\\s*reduce\\b[^)]*\\)/i', $sourceWithoutComments) === 1) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsReducedMotion' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát CSS giảm chuyển động tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic phát hiện khai báo transition/animation trong stylesheet %s nhưng không tìm thấy @media (prefers-reduced-motion: reduce). Đây chỉ là gợi ý rà soát file, không chứng minh lỗi WCAG hay ảnh hưởng người dùng: kiểm tra các chuyển động do tương tác, giảm/tắt chuyển động phi thiết yếu theo tùy chọn hệ điều hành khi phù hợp, và giữ chuyển động thiết yếu. Chuyển màu/opacity hoặc thời lượng rất ngắn có thể không cần thay đổi. Detector không phân tích selector, imports, keyframes hay animation JavaScript; xác minh stylesheet thực sự được tải. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findSkippedHeadingRanks(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectSourceFiles(['src/Presentation/Views'], ['php']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // PHP comments and HTML comments are not rendered headings.
            $renderableSource = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $renderableSource .= is_array($token) ? $token[1] : $token;
            }
            $renderableSource = preg_replace('/<!--.*?-->/s', '', $renderableSource) ?? $renderableSource;
            preg_match_all('/<h([1-6])\b[^>]*>/i', $renderableSource, $matches);

            $previousRank = null;
            $hasSkippedRank = false;
            foreach ($matches[1] as $rank) {
                $currentRank = (int) $rank;
                if ($previousRank !== null && $currentRank > $previousRank + 1) {
                    $hasSkippedRank = true;
                    break;
                }
                $previousRank = $currentRank;
            }
            if (!$hasSkippedRank) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsHeadingRank' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát thứ bậc heading tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic phát hiện một heading tăng cấp hơn một bậc trong template %s. Đây chỉ là gợi ý rà soát cấu trúc: kiểm tra HTML cuối sau khi ghép layout/partial và xác nhận các heading biểu đạt phân cấp có ý nghĩa. W3C khuyến cáo tránh nhảy cấp khi tạo subsection, nhưng việc bỏ cấp có thể hợp lý khi kết thúc subsection và các vùng cố định như sidebar có thể dùng rank nhất quán. Không đổi heading chỉ vì kiểu hiển thị; không coi tín hiệu này là kết luận vi phạm WCAG. Detector không dựng DOM, không phân tích nhánh PHP hoặc ngữ cảnh trang. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    $relativePath
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findHtmlDocumentsWithoutLanguage(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Keep PHP expressions as an opaque placeholder so dynamic lang values
            // are not mistaken for empty literals, while excluding PHP strings/comments.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', 'X', $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            preg_match_all('/<html\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>/is', $html, $matches, PREG_OFFSET_CAPTURE);
            $missingLanguageLines = [];
            foreach ($matches[0] as [$htmlTag, $offset]) {
                $hasValuedLanguage = preg_match('/\s+lang\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', $htmlTag) === 1;
                $hasEmptyLanguage = preg_match('/\s+lang\s*=\s*(?:"\s*"|\'\s*\')/i', $htmlTag) === 1;
                if (!$hasValuedLanguage || $hasEmptyLanguage) {
                    $missingLanguageLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
                }
            }

            if ($missingLanguageLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsPageLanguage' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát ngôn ngữ trang tại %s:%d', $relativePath, $missingLanguageLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d thẻ <html> trong template %s thiếu thuộc tính lang hoặc có giá trị literal rỗng (dòng: %s). WCAG 2.2 SC 3.1.1 yêu cầu ngôn ngữ mặc định của trang xác định được bằng chương trình. Xác minh tài liệu sau khi render và đặt đúng ngôn ngữ nội dung chính nếu phù hợp; không tự suy ra ngôn ngữ từ văn bản hay coi heuristic source là kết luận vi phạm WCAG. Detector không phân tích markup sinh động, các template ghép với nhau hoặc giá trị ngôn ngữ động. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($missingLanguageLines),
                    $relativePath,
                    implode(', ', $missingLanguageLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findUnserializeCalls(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src', 'public', 'config']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $lines = [];
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !$this->isUnserializeFunctionName($token)) {
                    continue;
                }

                $previousIndex = $this->previousSignificantToken($tokens, $index - 1);
                $nextIndex = $this->nextSignificantToken($tokens, $index + 1);
                if ($previousIndex === null || $nextIndex === null) {
                    continue;
                }

                $previous = $tokens[$previousIndex];
                $next = $tokens[$nextIndex];
                $methodOperatorTokens = [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION];
                if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
                    $methodOperatorTokens[] = T_NULLSAFE_OBJECT_OPERATOR;
                }
                if ((is_array($previous) && in_array($previous[0], $methodOperatorTokens, true))
                    || in_array($previous, ['->', '?->', '::', '\\'], true)
                    || $next !== '(') {
                    continue;
                }

                $lines[$token[2]] = true;
            }

            if ($lines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsUnserialize' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $lineNumbers = implode(', ', array_keys($lines));
            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Rà soát unserialize() tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic phát hiện %d lời gọi hàm unserialize() tại %s (dòng: %s). Đây là tín hiệu source tĩnh, không chứng minh dữ liệu đầu vào không tin cậy. Truy vết nguồn dữ liệu và xác minh callsite: PHP Manual cảnh báo không truyền dữ liệu người dùng không tin cậy vào unserialize(), kể cả khi đặt allowed_classes; cân nhắc JSON cho dữ liệu trao đổi và xác minh tính toàn vẹn nếu đọc dữ liệu serialized lưu ngoài. Không coi allowed_classes tự nó là bằng chứng an toàn. Nếu dữ liệu được kiểm soát và định dạng cần thiết, ghi rõ lý do giữ nguyên. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($lines),
                    $relativePath,
                    $lineNumbers
                ),
            ];
        }

        return $topics;
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private function isUnserializeFunctionName(array|string $token): bool
    {
        if (!is_array($token)) {
            return false;
        }

        $name = strtolower(ltrim($token[1], '\\'));
        if ($token[0] === T_STRING) {
            return $name === 'unserialize';
        }

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $nameToken) {
            if (defined($nameToken) && $token[0] === constant($nameToken)) {
                $lastSeparator = strrpos($name, '\\');
                $shortName = $lastSeparator === false ? $name : substr($name, $lastSeparator + 1);
                return $shortName === 'unserialize';
            }
        }

        return false;
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

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findWeakSecurityTokenRandomness(array $existingTargets): array
    {
        $topics = [];
        $sensitiveNamePattern = '/(?:token|secret|nonce|csrf|password|passwd|reset|verification|session|api[_]?key|access[_]?key|private[_]?key|auth(?:entication)?(?:code|token|key))/i';
        $weakFunctions = ['uniqid', 'mt_rand', 'rand'];

        foreach ($this->collectPhpFiles(['config', 'src', 'public']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $weakAssignments = [];
            $count = count($tokens);
            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];
                if (!is_array($token) || $token[0] !== T_VARIABLE
                    || preg_match($sensitiveNamePattern, substr($token[1], 1)) !== 1) {
                    continue;
                }

                $operatorIndex = $this->nextSignificantToken($tokens, $index + 1);
                if ($operatorIndex === null || $tokens[$operatorIndex] !== '=') {
                    continue;
                }

                $expressionTokens = [];
                $depth = 0;
                for ($cursor = $operatorIndex + 1; $cursor < $count; $cursor++) {
                    $expressionToken = $tokens[$cursor];
                    if ($expressionToken === ';' && $depth === 0) {
                        break;
                    }

                    $expressionTokens[] = $expressionToken;
                    $text = $this->tokenText($expressionToken);
                    if (in_array($text, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($text, [')', ']', '}'], true)) {
                        $depth = max(0, $depth - 1);
                    }
                }

                for ($expressionIndex = 0, $expressionCount = count($expressionTokens); $expressionIndex < $expressionCount; $expressionIndex++) {
                    $expressionToken = $expressionTokens[$expressionIndex];
                    if (!is_array($expressionToken) || $expressionToken[0] !== T_STRING
                        || !in_array(strtolower($expressionToken[1]), $weakFunctions, true)) {
                        continue;
                    }

                    $previousIndex = $this->previousSignificantToken($expressionTokens, $expressionIndex - 1);
                    $nextIndex = $this->nextSignificantToken($expressionTokens, $expressionIndex + 1);
                    if (($previousIndex !== null && in_array($this->tokenText($expressionTokens[$previousIndex]), ['->', '?->', '::', '\\'], true))
                        || $nextIndex === null || $expressionTokens[$nextIndex] !== '(') {
                        continue;
                    }

                    $weakAssignments[] = [
                        'line' => $expressionToken[2],
                        'function' => strtolower($expressionToken[1]),
                    ];
                }
            }

            if ($weakAssignments === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsWeakSecurityRandom' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $lines = array_values(array_unique(array_column($weakAssignments, 'line')));
            $functions = array_values(array_unique(array_column($weakAssignments, 'function')));
            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát random cho giá trị bảo mật tại %s:%d', $relativePath, $lines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic phát hiện phép gán vào biến có tên gợi ý giá trị bảo mật tại %s, dùng %s (dòng: %s). Đây là heuristic theo tên biến và lời gọi hàm, không chứng minh giá trị là credential hoặc có thể khai thác; truy vết cách dùng, nguồn dữ liệu và yêu cầu khó đoán trước khi sửa. PHP Manual xác nhận các hàm này không phù hợp khi cần giá trị khó đoán; cân nhắc random_bytes() cho token/secret dạng byte hoặc random_int() cho số nguyên và giữ kiểm soát collision/encoding thích hợp. Không tự thay đổi runtime behavior nếu chưa xác minh. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    $relativePath,
                    implode(', ', $functions),
                    implode(', ', $lines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findWeakPasswordHashing(array $existingTargets): array
    {
        $topics = [];
        $passwordNamePattern = '/(?:^|_)(?:password|passwd|pwd)(?:$|_)/i';

        foreach ($this->collectPhpFiles(['config', 'src', 'public']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $weakAssignments = [];
            $count = count($tokens);
            for ($index = 0; $index < $count; $index++) {
                $token = $tokens[$index];
                if (!is_array($token) || $token[0] !== T_VARIABLE) {
                    continue;
                }
                $variableName = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', substr($token[1], 1)) ?? substr($token[1], 1);
                if (preg_match($passwordNamePattern, trim($variableName, '_')) !== 1) {
                    continue;
                }

                $operatorIndex = $this->nextSignificantToken($tokens, $index + 1);
                if ($operatorIndex === null || $tokens[$operatorIndex] !== '=') {
                    continue;
                }

                $expressionTokens = [];
                $depth = 0;
                for ($cursor = $operatorIndex + 1; $cursor < $count; $cursor++) {
                    $expressionToken = $tokens[$cursor];
                    if ($expressionToken === ';' && $depth === 0) {
                        break;
                    }

                    $expressionTokens[] = $expressionToken;
                    $text = $this->tokenText($expressionToken);
                    if (in_array($text, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($text, [')', ']', '}'], true)) {
                        $depth = max(0, $depth - 1);
                    }
                }

                for ($expressionIndex = 0, $expressionCount = count($expressionTokens); $expressionIndex < $expressionCount; $expressionIndex++) {
                    $expressionToken = $expressionTokens[$expressionIndex];
                    if (!is_array($expressionToken)) {
                        continue;
                    }

                    $functionName = strtolower(ltrim($expressionToken[1], '\\'));
                    if ($expressionToken[0] !== T_STRING) {
                        if (!defined('T_NAME_FULLY_QUALIFIED') || $expressionToken[0] !== T_NAME_FULLY_QUALIFIED) {
                            continue;
                        }
                    }
                    $function = $functionName;
                    if (!in_array($function, ['md5', 'sha1', 'hash'], true)) {
                        continue;
                    }

                    $previousIndex = $this->previousSignificantToken($expressionTokens, $expressionIndex - 1);
                    $nextIndex = $this->nextSignificantToken($expressionTokens, $expressionIndex + 1);
                    if (($previousIndex !== null && in_array($this->tokenText($expressionTokens[$previousIndex]), ['->', '?->', '::', '\\'], true))
                        || $nextIndex === null || $expressionTokens[$nextIndex] !== '(') {
                        continue;
                    }

                    if ($function === 'hash') {
                        $algorithmIndex = $this->nextSignificantToken($expressionTokens, $nextIndex + 1);
                        $algorithm = $algorithmIndex === null ? null : $expressionTokens[$algorithmIndex];
                        if (!is_array($algorithm) || $algorithm[0] !== T_CONSTANT_ENCAPSED_STRING) {
                            continue;
                        }
                        $algorithmName = strtolower(trim(substr($algorithm[1], 1, -1)));
                        if (!in_array($algorithmName, ['md5', 'sha1', 'sha256'], true)) {
                            continue;
                        }
                        $function = 'hash(' . $algorithmName . ')';
                    }

                    $weakAssignments[] = [
                        'line' => $expressionToken[2],
                        'function' => $function,
                    ];
                }
            }

            if ($weakAssignments === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsWeakPasswordHash' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $lines = array_values(array_unique(array_column($weakAssignments, 'line')));
            $functions = array_values(array_unique(array_column($weakAssignments, 'function')));
            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát hash mật khẩu tại %s:%d', $relativePath, $lines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic phát hiện phép gán vào biến có tên gợi ý mật khẩu tại %s dùng %s (dòng: %s). Heuristic chỉ dựa trên tên biến và callsite; không chứng minh dữ liệu là mật khẩu người dùng, được lưu, hoặc có thể khai thác. Nếu đây thực sự là lưu trữ mật khẩu, kiểm tra luồng đầy đủ và dùng password_hash()/password_verify() với thuật toán thích nghi thay cho hash nhanh; nếu là dữ liệu khác hoặc migration có chủ đích, ghi rõ ngữ cảnh và không đổi hành vi tùy tiện. Thêm hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    $relativePath,
                    implode(', ', $functions),
                    implode(', ', $lines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findPrepopulatedPasswordFields(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Analyze rendered template markup only. Replace PHP tokens with a
            // sentinel so a dynamically rendered value is never mistaken for a
            // literal password while preserving useful source line positions.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            preg_match_all('/<input\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*?>/is', $html, $inputs, PREG_OFFSET_CAPTURE);
            $prepopulatedLines = [];
            foreach ($inputs[0] as [$inputTag, $offset]) {
                if (preg_match('/\s+type\s*=\s*(?:"password"|\'password\'|password)(?=\s|\/?>)/i', $inputTag) !== 1) {
                    continue;
                }
                if (preg_match('/\s+value\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/is', $inputTag, $valueMatch, PREG_UNMATCHED_AS_NULL) !== 1) {
                    continue;
                }
                $value = trim((string) ($valueMatch[1] ?? $valueMatch[2] ?? $valueMatch[3] ?? ''));
                if ($value === '' || str_contains($value, "\x01")) {
                    continue;
                }

                $prepopulatedLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
            }

            if ($prepopulatedLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsPasswordPrefill' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Rà soát mật khẩu được điền sẵn tại %s:%d', $relativePath, $prepopulatedLines[0]),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input type="password" có value literal không rỗng trong template %s (dòng: %s). Giá trị này được render ra HTML và có thể tiết lộ credential hoặc khuyến khích dùng mật khẩu mặc định; xác minh đây có phải dữ liệu demo/bootstrap và xóa prefill khỏi trang đăng nhập nếu không cần. Không ghi lại hoặc sao chép giá trị mật khẩu vào task/log. Detector chỉ nhận diện attribute literal trong source, không xác minh giá trị có thể đăng nhập hay môi trường triển khai. Thêm hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($prepopulatedLines),
                    $relativePath,
                    implode(', ', $prepopulatedLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findPasswordFieldsWithoutAutocomplete(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Restrict analysis to rendered template markup. Mask PHP tokens so
            // dynamic attributes cannot be mistaken for literal evidence.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            preg_match_all('/<input\b("[^"]*"|\'[^\']*\'|[^\'">])*?>/is', $html, $inputs, PREG_OFFSET_CAPTURE);
            $missingLines = [];
            foreach ($inputs[0] as [$inputTag, $offset]) {
                preg_match('/^<input\b(.*)>$/is', $inputTag, $tagMatch);
                if (!isset($tagMatch[1])) {
                    continue;
                }
                $attributes = $this->parseHtmlAttributes($tagMatch[1]);
                $type = strtolower($attributes['type'] ?? 'text');
                if (str_contains($type, "\x01") || $type !== 'password' || array_key_exists('autocomplete', $attributes)) {
                    continue;
                }
                $missingLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
            }

            if ($missingLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsPasswordAutocomplete' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát autocomplete cho trường mật khẩu tại %s:%d', $relativePath, $missingLines[0]),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input mật khẩu literal không khai báo autocomplete trong template %s (dòng: %s). Xác minh loại luồng trước khi chọn giá trị: thường dùng current-password cho đăng nhập, new-password khi tạo/đổi mật khẩu, hoặc one-time-code cho mã xác thực một lần. Thêm token phù hợp để browser/password manager nhận diện trường nếu đây là trường xác thực; không áp dụng mù quáng cho markup sinh động hoặc trường đặc thù. Detector chỉ xét template PHP tĩnh và không đánh giá đầy đủ WCAG hay DOM runtime. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($missingLines),
                    $relativePath,
                    implode(', ', $missingLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findPersonalDataFieldsWithoutAutocomplete(array $existingTargets): array
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
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $missingLines = [];
            preg_match_all('~<form\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)</form\s*>~is', $html, $forms, PREG_OFFSET_CAPTURE);
            foreach ($forms[1] as [$formHtml, $formOffset]) {
                preg_match_all('~<input\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~is', $formHtml, $inputs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($inputs as $input) {
                    $attributes = $this->parseHtmlAttributes($input[1][0]);
                    $type = strtolower($attributes['type'] ?? 'text');
                    if (str_contains($type, "\x01") || !in_array($type, ['email', 'tel'], true)) {
                        continue;
                    }

                    $autocomplete = $attributes['autocomplete'] ?? null;
                    if ($autocomplete !== null
                        && str_contains($autocomplete, "\x01")
                    ) {
                        continue;
                    }
                    if ($autocomplete !== null && $this->hasMatchingContactAutocompletePurpose($autocomplete, $type)) {
                        continue;
                    }

                    $offset = $formOffset + $input[0][1];
                    $missingLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
                }
            }

            if ($missingLines === []) {
                continue;
            }

            $missingLines = array_values(array_unique($missingLines));
            sort($missingLines);
            $relativePath = $this->relativePath($file);
            $targetName = 'CmsPersonalAutocomplete' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát autocomplete cho dữ liệu liên hệ tại %s:%d', $relativePath, $missingLines[0]),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input email/điện thoại tĩnh trong form tại %s (dòng: %s) thiếu autocomplete hoặc có purpose token không khớp. Xác minh trường có thu thập thông tin cá nhân và chọn token phù hợp (email hoặc tel cùng các tiền tố detail hợp lệ); không thêm token chỉ để loại cảnh báo nếu ngữ cảnh khác. Detector chỉ xét input nằm trong form PHP template tĩnh, bỏ qua comment và thuộc tính/loại input động; đây là gợi ý rà soát, không phải kết luận vi phạm WCAG hay dự đoán hành vi autofill. Kiểm tra markup runtime, bổ sung hồi quy nếu cần và chạy ./bin/tuzygent verify.',
                    count($missingLines),
                    $relativePath,
                    implode(', ', $missingLines)
                ),
            ];
        }

        return $topics;
    }

    private function hasMatchingContactAutocompletePurpose(string $value, string $inputType): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return false;
        }

        $index = 0;
        if (isset($tokens[$index]) && str_starts_with($tokens[$index], 'section-') && strlen($tokens[$index]) > strlen('section-')) {
            $index++;
        }
        if (isset($tokens[$index]) && in_array($tokens[$index], ['shipping', 'billing'], true)) {
            $index++;
        }
        if (isset($tokens[$index]) && in_array($tokens[$index], ['home', 'work', 'mobile', 'fax', 'pager'], true)) {
            $index++;
        }

        $purpose = $tokens[$index] ?? '';
        $matchesType = $inputType === 'email'
            ? $purpose === 'email'
            : in_array($purpose, ['tel', 'tel-country-code', 'tel-national', 'tel-area-code', 'tel-local', 'tel-local-prefix', 'tel-local-suffix', 'tel-extension'], true);
        if (!$matchesType) {
            return false;
        }

        $index++;
        if (isset($tokens[$index]) && $tokens[$index] === 'webauthn') {
            $index++;
        }

        return $index === count($tokens);
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findIdentityFieldsWithoutAutocomplete(array $existingTargets): array
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
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $missingLines = [];
            preg_match_all('~<form\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)</form\s*>~is', $html, $forms, PREG_OFFSET_CAPTURE);
            foreach ($forms[1] as [$formHtml, $formOffset]) {
                preg_match_all('~<input\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>~is', $formHtml, $inputs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($inputs as $input) {
                    $attributes = $this->parseHtmlAttributes($input[1][0]);
                    $type = strtolower($attributes['type'] ?? 'text');
                    if ($type !== 'text' || str_contains($type, "\x01")) {
                        continue;
                    }

                    $fieldName = $attributes['name'] ?? '';
                    $fieldId = $attributes['id'] ?? '';
                    if (str_contains($fieldName, "\x01") || str_contains($fieldId, "\x01")) {
                        continue;
                    }
                    $expectedPurposes = $this->identityAutocompletePurposes($fieldName)
                        ?? $this->identityAutocompletePurposes($fieldId)
                        ?? [];
                    if ($expectedPurposes === []) {
                        continue;
                    }

                    $autocomplete = $attributes['autocomplete'] ?? null;
                    if ($autocomplete !== null && str_contains($autocomplete, "\x01")) {
                        continue;
                    }
                    if ($autocomplete !== null && $this->hasMatchingIdentityAutocompletePurpose($autocomplete, $expectedPurposes)) {
                        continue;
                    }

                    $offset = $formOffset + $input[0][1];
                    $missingLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
                }
            }

            if ($missingLines === []) {
                continue;
            }

            $missingLines = array_values(array_unique($missingLines));
            sort($missingLines);
            $relativePath = $this->relativePath($file);
            $targetName = 'CmsIdentityAutocomplete' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát autocomplete cho trường định danh tại %s:%d', $relativePath, $missingLines[0]),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input text trong form tại %s (dòng: %s) có name/id gợi ý tên, địa chỉ hoặc tổ chức nhưng thiếu autocomplete purpose token phù hợp. Xác minh mục đích thực tế trước khi chọn token; detector chỉ dùng name/id tĩnh, không đọc nhãn, dữ liệu runtime hay JavaScript và có thể nhầm trường nghiệp vụ trùng tên. Đây là gợi ý rà soát, không phải kết luận WCAG. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($missingLines),
                    $relativePath,
                    implode(', ', $missingLines)
                ),
            ];
        }

        return $topics;
    }

    /** @return string[]|null */
    private function identityAutocompletePurposes(string $fieldName): ?array
    {
        $normalized = strtolower(trim(preg_replace('/([a-z])([A-Z])/', '$1_$2', trim($fieldName)) ?? $fieldName));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');
        if ($normalized === '' || preg_match('/(?:^|_)(?:product|post|category|tag|coupon|banner)(?:_|$)/', $normalized) === 1) {
            return null;
        }

        $patterns = [
            '/^(?:first_name|firstname|given_name|givenname|fname)$/' => ['given-name'],
            '/^(?:last_name|lastname|family_name|familyname|surname|lname)$/' => ['family-name'],
            '/^(?:full_name|fullname|customer_name|recipient_name|contact_name)$/' => ['name'],
            '/^(?:company|company_name|organization|organisation|organization_name|organisation_name)$/' => ['organization'],
            '/^(?:street_address|streetaddress|address|address1|address_line_1|addressline1)$/' => ['street-address', 'address-line1'],
            '/^(?:address2|address_line_2|addressline2)$/' => ['address-line2'],
            '/^(?:city|town|address_level_2|addresslevel2)$/' => ['address-level2'],
            '/^(?:state|province|address_level_1|addresslevel1)$/' => ['address-level1'],
            '/^(?:postal|postal_code|postalcode|zip|zip_code|zipcode)$/' => ['postal-code'],
            '/^(?:country|country_name|countryname)$/' => ['country-name'],
        ];
        foreach ($patterns as $pattern => $purposes) {
            if (preg_match($pattern, $normalized) === 1) {
                return $purposes;
            }
        }

        return null;
    }

    /** @param string[] $expectedPurposes */
    private function hasMatchingIdentityAutocompletePurpose(string $value, array $expectedPurposes): bool
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return false;
        }

        $index = 0;
        if (isset($tokens[$index]) && str_starts_with($tokens[$index], 'section-') && strlen($tokens[$index]) > strlen('section-')) {
            $index++;
        }
        if (isset($tokens[$index]) && in_array($tokens[$index], ['shipping', 'billing'], true)) {
            $index++;
        }

        return isset($tokens[$index])
            && in_array($tokens[$index], $expectedPurposes, true)
            && $index === count($tokens) - 1;
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
    private function findDirectRequestOutput(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $tokens = token_get_all($source);
            $unsafeLines = [];
            for ($index = 0, $count = count($tokens); $index < $count; $index++) {
                $token = $tokens[$index];
                $tokenId = is_array($token) ? $token[0] : null;
                if ($tokenId === null || !in_array($tokenId, [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                    continue;
                }

                $expression = [];
                $parentheses = 0;
                $brackets = 0;
                $braces = 0;
                for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                    $part = $tokens[$cursor];
                    if (is_array($part) && $part[0] === T_CLOSE_TAG) {
                        break;
                    }
                    $text = $this->tokenText($part);
                    if ($text === ';' && $parentheses === 0 && $brackets === 0 && $braces === 0) {
                        break;
                    }
                    if ($text === '(') {
                        $parentheses++;
                    } elseif ($text === ')') {
                        $parentheses = max(0, $parentheses - 1);
                    } elseif ($text === '[') {
                        $brackets++;
                    } elseif ($text === ']') {
                        $brackets = max(0, $brackets - 1);
                    } elseif ($text === '{') {
                        $braces++;
                    } elseif ($text === '}') {
                        $braces = max(0, $braces - 1);
                    }
                    $expression[] = $part;
                }

                $encoderScopes = [];
                $pendingEncoder = false;
                foreach ($expression as $expressionIndex => $part) {
                    $text = $this->tokenText($part);
                    if ($text === '(') {
                        $encoderScopes[] = ($encoderScopes !== [] && $encoderScopes[array_key_last($encoderScopes)]) || $pendingEncoder;
                        $pendingEncoder = false;
                        continue;
                    }
                    if ($text === ')') {
                        array_pop($encoderScopes);
                        $pendingEncoder = false;
                        continue;
                    }
                    if (!is_array($part)) {
                        continue;
                    }

                    $isNameToken = in_array($part[0], [T_STRING], true);
                    foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $nameToken) {
                        if (defined($nameToken) && $part[0] === constant($nameToken)) {
                            $isNameToken = true;
                            break;
                        }
                    }
                    if ($isNameToken) {
                        $name = strtolower(ltrim($part[1], '\\'));
                        $next = $this->nextSignificantToken($expression, $expressionIndex + 1);
                        $pendingEncoder = in_array($name, ['htmlspecialchars', 'htmlentities'], true)
                            && $next !== null
                            && $this->tokenText($expression[$next]) === '(';
                        continue;
                    }

                    if ($part[0] === T_VARIABLE
                        && in_array(strtolower($part[1]), ['$_get', '$_post', '$_request'], true)
                        && !in_array(true, $encoderScopes, true)
                    ) {
                        $unsafeLines[$part[2]] = true;
                    }
                }
            }

            if ($unsafeLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsDirectRequestOutput' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }
            $lines = array_keys($unsafeLines);
            sort($lines, SORT_NUMERIC);
            $topics[] = [
                'title' => sprintf('[CMS Topic P0] Rà soát output trực tiếp từ request tại %s', $relativePath),
                'target_name' => $targetName,
                'priority' => 'P0',
                'specification' => sprintf(
                    'AutoTopic phát hiện echo/print trong template %s có tham chiếu trực tiếp $_GET, $_POST hoặc $_REQUEST tại dòng %s mà không thấy lời gọi htmlspecialchars/htmlentities bao quanh tham chiếu đó. Xác minh luồng dữ liệu và output context; dùng encoding phù hợp với HTML text/attribute hoặc biện pháp đúng cho URL, JavaScript hay CSS. Đây là heuristic source-level, không chứng minh có thể khai thác và không theo dõi giá trị đã được xử lý qua biến/helper. Bổ sung hồi quy rồi chạy ./bin/tuzygent verify.',
                    $relativePath,
                    implode(', ', $lines)
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
    private function findLabelsWithoutMatchingControls(array $existingTargets): array
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
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $controlIds = [];
            preg_match_all('/<(input|select|textarea|button)\b([^>]*)>/is', $html, $controls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($controls as $control) {
                $attributes = $this->parseHtmlAttributes($control[2][0]);
                if (strtolower($control[1][0]) === 'input' && strtolower($attributes['type'] ?? 'text') === 'hidden') {
                    continue;
                }
                if (($attributes['id'] ?? '') !== '') {
                    $controlIds[$attributes['id']] = true;
                }
            }

            $unmatchedLines = [];
            preg_match_all('/<label\b([^>]*)>/is', $html, $labels, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($labels as $label) {
                $attributes = $this->parseHtmlAttributes($label[1][0]);
                $for = $attributes['for'] ?? '';
                if ($for === '' || isset($controlIds[$for])) {
                    continue;
                }
                $unmatchedLines[] = substr_count(substr($html, 0, $label[0][1]), "\n") + 1;
            }

            if ($unmatchedLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsLabelControlLink' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát liên kết label/control tại %s:%d', $relativePath, $unmatchedLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d label có thuộc tính for literal nhưng không tìm thấy input/select/textarea/button có id tương ứng trong HTML tĩnh của template %s (dòng: %s). Kiểm tra HTML sau khi render và quan hệ label/control trong toàn trang; nếu không có phần tử được ghép từ layout/partial hay markup động, sửa for hoặc id để chúng khớp. Detector bỏ qua giá trị động, không dựng DOM, không phân tích label lồng control và có thể không thấy phần tử ở template khác; đây là gợi ý rà soát, không phải kết luận WCAG. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($unmatchedLines),
                    $relativePath,
                    implode(', ', $unmatchedLines)
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
    private function findGenericLinkText(array $existingTargets): array
    {
        $topics = [];
        $genericLabels = [
            'click here', 'here', 'read more', 'learn more', 'more', 'view more',
            'xem thêm', 'đọc thêm', 'tại đây', 'bấm vào đây', 'nhấn vào đây', 'chi tiết',
        ];

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
                    $html .= '__TUZY_DYNAMIC__' . str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $matches = [];
            preg_match_all('/<a\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)<\/a\s*>/is', $html, $anchors, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($anchors as $anchor) {
                $openingTag = substr($anchor[0][0], 0, strpos($anchor[0][0], '>') + 1);
                $innerHtml = $anchor[1][0];
                if (str_contains($openingTag, '__TUZY_DYNAMIC__')
                    || str_contains($innerHtml, '__TUZY_DYNAMIC__')
                    || preg_match('/<img\b/i', $innerHtml) === 1) {
                    continue;
                }

                $attributes = $this->parseHtmlAttributes(substr($openingTag, 2, -1));
                if (!array_key_exists('href', $attributes)) {
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

                $label = html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $label) ?? $label));
                if (in_array($label, $genericLabels, true)) {
                    $matches[] = substr_count(substr($html, 0, $anchor[0][1]), "\n") + 1;
                }
            }

            if ($matches === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsLinkPurpose' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát nhãn liên kết chung chung tại %s:%d', $relativePath, $matches[0]),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d liên kết có nhãn literal chung chung tại %s (dòng: %s), ví dụ “xem thêm”, “tại đây” hoặc “click here”. Hãy kiểm tra DOM sau khi render và ngữ cảnh liên kết được xác định bằng chương trình (cùng câu/đoạn, mục danh sách, ô bảng hoặc quan hệ phù hợp); nếu mục đích vẫn khó phân biệt, dùng nhãn mô tả đích đến/hành động. WCAG 2.4.4 cho phép văn bản liên kết kết hợp ngữ cảnh phù hợp, nên nhãn ngắn không tự chứng minh vi phạm. Detector source tĩnh bỏ qua nội dung động và không tính accessible name cuối cùng. Bổ sung hồi quy nếu phù hợp, rồi chạy ./bin/tuzygent verify.',
                    count($matches),
                    $relativePath,
                    implode(', ', $matches)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findAriaHiddenFocusableElements(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Analyze only literal template markup. PHP expressions and comments
            // are omitted so examples and dynamic attribute values are not claims.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= '__TUZY_DYNAMIC__' . str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $lineNumbers = [];
            preg_match_all('/<([a-z][a-z0-9:-]*)\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $elements, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($elements as $element) {
                $tagName = strtolower($element[1][0]);
                $openingTag = $element[0][0];
                if (str_contains($openingTag, '__TUZY_DYNAMIC__')) {
                    continue;
                }

                $attributes = $this->parseHtmlAttributes(substr($openingTag, strlen($tagName) + 1, -1));
                if (strtolower(trim($attributes['aria-hidden'] ?? '')) !== 'true'
                    || isset($attributes['hidden'])
                    || isset($attributes['disabled'])) {
                    continue;
                }

                $tabIndex = $attributes['tabindex'] ?? null;
                $hasSequentialTabIndex = $tabIndex !== null
                    && preg_match('/^\+?\d+$/', trim($tabIndex)) === 1;
                $isNativeFocusable = match ($tagName) {
                    'a', 'area' => isset($attributes['href']),
                    'button', 'select', 'textarea' => true,
                    'input' => strtolower($attributes['type'] ?? 'text') !== 'hidden',
                    'audio', 'video' => isset($attributes['controls']),
                    'iframe', 'object', 'embed' => true,
                    default => false,
                };
                if (!$isNativeFocusable && !$hasSequentialTabIndex) {
                    continue;
                }

                $lineNumbers[] = substr_count(substr($html, 0, $element[0][1]), "\n") + 1;
            }

            if ($lineNumbers === []) {
                continue;
            }

            $lineNumbers = array_values(array_unique($lineNumbers));
            sort($lineNumbers);
            $relativePath = $this->relativePath($file);
            $targetName = 'CmsAriaHiddenFocus' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát aria-hidden trên phần tử focusable tại %s:%d', $relativePath, $lineNumbers[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d phần tử HTML literal có aria-hidden="true" và có thể nhận focus theo thuộc tính trực tiếp tại %s (dòng: %s). W3C khuyến cáo không đặt aria-hidden="true" trên phần tử focusable vì người dùng có thể focus vào nội dung bị loại khỏi accessibility tree. Xác minh DOM sau khi render và trạng thái CSS/JavaScript; detector không phân tích focusable descendant, nhánh PHP, CSS, trạng thái disabled động hoặc thay đổi runtime và đây không phải kết luận WCAG. Sửa chỉ khi xác nhận vấn đề, bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
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
    private function findImagesWithoutDimensions(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect literal template HTML. PHP expressions are masked while
            // preserving line breaks; fully generated markup is not inspected.
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
            $missingDimensions = [];
            foreach ($images[0] as [$imageTag, $offset]) {
                $hasWidth = preg_match('/\s+width(?=\s|=|\/?>)/i', $imageTag) === 1;
                $hasHeight = preg_match('/\s+height(?=\s|=|\/?>)/i', $imageTag) === 1;
                if ($hasWidth && $hasHeight) {
                    continue;
                }
                $missingDimensions[] = substr_count(substr($html, 0, $offset), "\n") + 1;
            }

            if ($missingDimensions === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsImageDimensions' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P2] Rà soát ảnh thiếu width/height tại %s:%d', $relativePath, $missingDimensions[0]),
                'target_name' => $targetName,
                'priority' => 'P2',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d thẻ img trong template %s không khai báo đồng thời cả width và height (dòng: %s). Kiểm tra kích thước nội tại của asset và markup sau render; khai báo đúng kích thước nếu phù hợp để trình duyệt có thể dành trước không gian khi ảnh tải. Trước khi sửa, xác minh CSS aspect-ratio hoặc cơ chế layout khác đã dành chỗ hay chưa; không điền kích thước ước đoán. Detector chỉ quét HTML template tĩnh, không phân tích CSS/layout runtime và không chứng minh có layout shift hoặc ảnh hưởng Core Web Vitals. Bổ sung hồi quy nếu phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($missingDimensions),
                    $relativePath,
                    implode(', ', $missingDimensions)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findBlankTargetsWithoutNoopener(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect literal template markup only. Dynamic PHP fragments become
            // line-preserving whitespace, and HTML comments are not rendered links.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= '__TUZY_DYNAMIC__' . str_repeat("\n", substr_count($token[1], "\n"));
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            preg_match_all('/<a\b(?:(?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $anchors, PREG_OFFSET_CAPTURE);
            $reviewLines = [];
            foreach ($anchors[0] as [$anchor, $offset]) {
                $attributes = $this->parseHtmlAttributes(substr($anchor, 2, -1));
                if (strcasecmp(trim($attributes['target'] ?? ''), '_blank') !== 0) {
                    continue;
                }
                if (str_contains($anchor, '__TUZY_DYNAMIC__')) {
                    continue;
                }
                $relTokens = preg_split('/\s+/', strtolower(trim($attributes['rel'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (in_array('noopener', $relTokens, true)) {
                    continue;
                }
                $reviewLines[] = substr_count(substr($html, 0, $offset), "\n") + 1;
            }

            if ($reviewLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsBlankNoopener' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát liên kết target blank thiếu noopener tại %s:%d', $relativePath, $reviewLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d thẻ <a target="_blank"> trong template %s không có token noopener trong rel (dòng: %s). Trình duyệt hiện đại mặc định áp dụng hành vi noopener cho target="_blank", vì vậy đây là đề xuất hardening/tương thích và không tự nó chứng minh lỗ hổng khai thác được. Xác minh markup sau render và thêm rel="noopener" nếu phù hợp; không tự thêm noreferrer vì sẽ thay đổi Referer. Detector không đánh giá đích liên kết hoặc hỗ trợ trình duyệt của người dùng. Bổ sung hồi quy nếu phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($reviewLines),
                    $relativePath,
                    implode(', ', $reviewLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findJavascriptOpenersWithoutNoopener(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectSourceFiles(['public/assets/js'], ['js']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $reviewLines = [];
            foreach ($this->extractStaticWindowOpenCalls($source) as $call) {
                $target = $call['target'];
                if ($target !== null && in_array(strtolower(trim($target)), ['_self', '_parent', '_top'], true)) {
                    continue;
                }
                if ($target === null && $call['target_argument_present']) {
                    continue;
                }

                $features = strtolower((string) $call['features']);
                if (preg_match('/(?:^|[\s,])(?:noopener|noreferrer)(?:\s*=\s*(?:yes|1|true))?(?=$|[\s,])/i', $features) === 1) {
                    continue;
                }

                $reviewLines[] = $call['line'];
            }
            if ($reviewLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsJsNoopener' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát window.open thiếu noopener tại %s:%d', $relativePath, $reviewLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d lời gọi window.open() có URL và target/features literal tại %s (dòng: %s), có thể mở browsing context mới nhưng feature string không có noopener hoặc noreferrer. MDN mô tả noopener ngăn cửa sổ mới truy cập opener; noreferrer cũng bật noopener nhưng đồng thời bỏ Referer. Xác minh target thực tế, markup/runtime, chính sách COOP và việc mã có cần tham chiếu opener hay không; thêm noopener khi phù hợp. Đây là heuristic source scan, không khẳng định lỗ hổng runtime. Detector bỏ qua lời gọi dynamic/template literal, alias API, trường hợp target/features không đủ tĩnh và không phân tích feature được ghép qua biến. Thêm hồi quy nếu phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($reviewLines),
                    $relativePath,
                    implode(', ', $reviewLines)
                ),
            ];
        }

        return $topics;
    }

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findJavascriptHtmlInjectionSinks(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectSourceFiles(['public/assets/js'], ['js']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            $code = $this->maskJavaScriptCommentsAndStrings($source);
            preg_match_all(
                '/(?:\.\s*(?:innerHTML|outerHTML)\s*=|\[\s*([\'\"])(?:innerHTML|outerHTML)\1\s*\]\s*=|\.\s*insertAdjacentHTML\s*\(|\bdocument\s*\.\s*write\s*\()/i',
                $code,
                $matches,
                PREG_OFFSET_CAPTURE
            );
            if ($matches[0] === []) {
                continue;
            }

            $lines = [];
            foreach ($matches[0] as [, $offset]) {
                $lines[] = substr_count(substr($source, 0, $offset), "\n") + 1;
            }
            $lines = array_values(array_unique($lines));
            $relativePath = $this->relativePath($file);
            $targetName = 'CmsDomHtmlSink' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát HTML injection sink trong JavaScript tại %s:%d', $relativePath, $lines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d vị trí có thể parse chuỗi thành HTML trong %s (dòng: %s): innerHTML/outerHTML, insertAdjacentHTML() hoặc document.write(). Các API này có thể tạo DOM XSS nếu nhận dữ liệu không tin cậy. Lần theo nguồn dữ liệu, kiểm tra encoding/sanitizer và Trusted Types; nếu chỉ hiển thị text, cân nhắc textContent hoặc tạo node DOM. Xác minh từng vị trí theo ngữ cảnh trước khi sửa: static scan này không phân tích data-flow, runtime, sanitizer, Trusted Types hay template interpolation và không kết luận rằng có lỗ hổng khai thác được. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($lines),
                    $relativePath,
                    implode(', ', $lines)
                ),
            ];
        }

        return $topics;
    }

    /** Preserve offsets/newlines while masking JS comments and quoted literals. */
    private function maskJavaScriptCommentsAndStrings(string $source): string
    {
        $masked = $source;
        $length = strlen($source);
        $quote = '';
        $escaped = false;
        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];
            if ($quote !== '') {
                if ($char !== "\n" && $char !== "\r") {
                    $masked[$index] = ' ';
                }
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $literalEnd = $index + 1;
                $literalEscaped = false;
                while ($literalEnd < $length) {
                    $literalChar = $source[$literalEnd];
                    if ($literalEscaped) {
                        $literalEscaped = false;
                    } elseif ($literalChar === '\\') {
                        $literalEscaped = true;
                    } elseif ($literalChar === $char) {
                        break;
                    }
                    $literalEnd++;
                }

                if ($literalEnd < $length) {
                    $propertyName = substr($source, $index + 1, $literalEnd - $index - 1);
                    $before = $index - 1;
                    while ($before >= 0 && ctype_space($source[$before])) {
                        $before--;
                    }
                    $after = $literalEnd + 1;
                    while ($after < $length && ctype_space($source[$after])) {
                        $after++;
                    }
                    if (in_array($propertyName, ['innerHTML', 'outerHTML'], true)
                        && ($source[$before] ?? '') === '['
                        && ($source[$after] ?? '') === ']') {
                        $assignment = $after + 1;
                        while ($assignment < $length && ctype_space($source[$assignment])) {
                            $assignment++;
                        }
                        if (($source[$assignment] ?? '') === '=') {
                            $index = $literalEnd;
                            continue;
                        }
                    }
                }

                $quote = $char;
                $masked[$index] = ' ';
                continue;
            }
            if (substr($source, $index, 2) === '//') {
                $end = strpos($source, "\n", $index + 2);
                $end = $end === false ? $length : $end;
                for ($commentIndex = $index; $commentIndex < $end; $commentIndex++) {
                    $masked[$commentIndex] = ' ';
                }
                $index = $end - 1;
                continue;
            }
            if (substr($source, $index, 2) === '/*') {
                $end = strpos($source, '*/', $index + 2);
                $end = $end === false ? $length : $end + 2;
                for ($commentIndex = $index; $commentIndex < $end; $commentIndex++) {
                    if ($source[$commentIndex] !== "\n" && $source[$commentIndex] !== "\r") {
                        $masked[$commentIndex] = ' ';
                    }
                }
                $index = $end - 1;
            }
        }

        return $masked;
    }

    /**
     * Return only calls whose first argument is a static string literal.
     * @return array<int, array{line: int, target: ?string, target_argument_present: bool, features: ?string}>
     */
    private function extractStaticWindowOpenCalls(string $source): array
    {
        $calls = [];
        $length = strlen($source);
        $quote = '';
        $escaped = false;
        for ($index = 0; $index < $length; $index++) {
            $char = $source[$index];
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
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }
            if (substr($source, $index, 2) === '//') {
                $end = strpos($source, "\n", $index + 2);
                $index = $end === false ? $length : $end;
                continue;
            }
            if (substr($source, $index, 2) === '/*') {
                $end = strpos($source, '*/', $index + 2);
                $index = $end === false ? $length : $end + 1;
                continue;
            }

            if (($index > 0 && preg_match('/[a-zA-Z0-9_$\.]/', $source[$index - 1]) === 1)
                || preg_match('/\Gwindow\s*\.\s*open\s*\(/', $source, $match, 0, $index) !== 1) {
                continue;
            }

            $openParen = $index + strlen($match[0]) - 1;
            $arguments = $this->readJavaScriptCallArguments($source, $openParen);
            if ($arguments === null) {
                continue;
            }
            $url = $this->staticJavaScriptString($arguments[0] ?? '');
            $targetArgumentPresent = array_key_exists(1, $arguments);
            $target = $targetArgumentPresent ? $this->staticJavaScriptString($arguments[1]) : null;
            $features = array_key_exists(2, $arguments) ? $this->staticJavaScriptString($arguments[2]) : null;
            if ($url === null || ($targetArgumentPresent && $target === null) || (array_key_exists(2, $arguments) && $features === null)) {
                $index = $openParen;
                continue;
            }

            $calls[] = [
                'line' => substr_count(substr($source, 0, $index), "\n") + 1,
                'target' => $target,
                'target_argument_present' => $targetArgumentPresent,
                'features' => $features,
            ];
            $index = $arguments['end'];
        }

        return $calls;
    }

    /** @return array<int|string, string>|null */
    private function readJavaScriptCallArguments(string $source, int $openParen): ?array
    {
        $arguments = [];
        $start = $openParen + 1;
        $depth = 0;
        $quote = '';
        $escaped = false;
        $length = strlen($source);
        for ($index = $start; $index < $length; $index++) {
            $char = $source[$index];
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
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                continue;
            }
            if (substr($source, $index, 2) === '//') {
                $end = strpos($source, "\n", $index + 2);
                $index = $end === false ? $length : $end;
                continue;
            }
            if (substr($source, $index, 2) === '/*') {
                $end = strpos($source, '*/', $index + 2);
                $index = $end === false ? $length : $end + 1;
                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' && $depth === 0) {
                $arguments[] = trim(substr($source, $start, $index - $start));
                if (count($arguments) === 1 && $arguments[0] === '') {
                    $arguments = [];
                }
                $arguments['end'] = $index;
                return $arguments;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $arguments[] = trim(substr($source, $start, $index - $start));
                $start = $index + 1;
            }
        }

        return null;
    }

    private function staticJavaScriptString(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('/^([\'\"])([^\\\'\"]*)\1$/s', $expression, $match) !== 1) {
            return null;
        }

        return $match[2];
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
    private function findInternalLinksWithoutRoutes(array $existingTargets): array
    {
        $routeFile = $this->basePath . '/public/index.php';
        $routeSource = is_file($routeFile) ? file_get_contents($routeFile) : false;
        if (!is_string($routeSource)) {
            return [];
        }

        preg_match_all(
            '~->\s*(?:get|post)\s*\(\s*([\'\"])(/[^\'\"]*)\1~i',
            $routeSource,
            $routeMatches,
            PREG_SET_ORDER
        );
        $routePatterns = [];
        foreach ($routeMatches as $routeMatch) {
            $pattern = $routeMatch[2];
            $segments = explode('/', trim($pattern, '/'));
            $regexSegments = [];
            foreach ($segments as $segment) {
                $regexSegments[] = preg_match('/^\{[a-zA-Z0-9_]+\}$/', $segment) === 1
                    ? '[^/]+'
                    : preg_quote($segment, '#');
            }
            $routePatterns[] = '#^/' . implode('/', $regexSegments) . '$#';
        }
        if ($routePatterns === []) {
            return [];
        }

        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect HTML emitted literally by the template. Mask PHP tokens so
            // dynamic URLs cannot be mistaken for fixed application paths.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            /** @var array<string, int[]> $missingPaths */
            $missingPaths = [];
            preg_match_all('/<a\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $html, $anchors, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($anchors as $anchor) {
                $attributes = $this->parseHtmlAttributes($anchor[1][0]);
                $href = trim($attributes['href'] ?? '');
                if ($href === '' || str_contains($href, "\x01") || !str_starts_with($href, '/') || str_starts_with($href, '//')) {
                    continue;
                }

                $path = preg_split('/[?#]/', $href, 2)[0] ?? '';
                if ($path === '' || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
                    continue;
                }
                if ($path !== '/' && str_ends_with($path, '/')) {
                    $path = rtrim($path, '/');
                }

                $matchesRoute = false;
                foreach ($routePatterns as $routePattern) {
                    if (preg_match($routePattern, $path) === 1) {
                        $matchesRoute = true;
                        break;
                    }
                }
                if (!$matchesRoute) {
                    $line = substr_count(substr($html, 0, $anchor[0][1]), "\n") + 1;
                    $missingPaths[$path][] = $line;
                }
            }

            if ($missingPaths === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            foreach ($missingPaths as $path => $lines) {
                $targetName = 'CmsMissingRoute' . substr(sha1($relativePath . ':' . $path), 0, 12);
                if (isset($existingTargets[strtolower($targetName)])) {
                    continue;
                }

                $topics[] = [
                    'title' => sprintf('[CMS Topic P2] Kiểm tra liên kết nội bộ %s tại %s:%d', $path, $relativePath, $lines[0]),
                    'target_name' => $targetName,
                    'priority' => 'P2',
                    'specification' => sprintf(
                        'AutoTopic tìm thấy href root-relative literal %s tại %s (dòng: %s), nhưng không khớp route GET/POST literal trong public/index.php. Kiểm tra URL sau render, route được đăng ký động, static asset/rewrite và cấu hình triển khai; nếu đây thực sự là liên kết trang ứng dụng bị hỏng, cập nhật href hoặc route theo ý định. Detector bỏ qua URL ngoài site, URL động và asset không được đối chiếu; route có thể bị bảo vệ bởi module hoặc không hoạt động ở mọi môi trường, nên đây chỉ là đề xuất rà soát, không phải kết luận HTTP 404. Bổ sung hồi quy nếu phù hợp rồi chạy ./bin/tuzygent verify.',
                        $path,
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

    /** @param array<string, bool> $existingTargets
     *  @return array<int, array{title: string, target_name: string, specification: string, priority: string}>
     */
    private function findPasswordFormsUsingGet(array $existingTargets): array
    {
        $topics = [];
        foreach ($this->collectPhpFiles(['src/Presentation/Views']) as $file) {
            $source = file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }

            // Inspect rendered HTML literals only. Mask PHP so interpolated form
            // settings or controls do not become unsupported static conclusions.
            $html = '';
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && $token[0] === T_INLINE_HTML) {
                    $html .= $token[1];
                } elseif (is_array($token)) {
                    $html .= preg_replace('/[^\n]/', "\x01", $token[1]) ?? $token[1];
                }
            }
            $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

            $reviewLines = [];
            preg_match_all('/<form\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>(.*?)<\/form\s*>/is', $html, $forms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($forms as $form) {
                $formAttributes = $this->parseHtmlAttributes($form[1][0]);
                $method = strtolower(trim($formAttributes['method'] ?? ''));
                if (str_contains($method, "\x01") || in_array($method, ['post', 'dialog'], true)) {
                    continue;
                }

                preg_match_all('/<input\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/is', $form[2][0], $inputs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($inputs as $input) {
                    $inputAttributes = $this->parseHtmlAttributes($input[1][0]);
                    $type = strtolower(trim($inputAttributes['type'] ?? 'text'));
                    if (str_contains($type, "\x01") || $type !== 'password') {
                        continue;
                    }

                    $lineOffset = $form[2][1] + $input[0][1];
                    $reviewLines[] = substr_count(substr($html, 0, $lineOffset), "\n") + 1;
                }
            }

            if ($reviewLines === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);
            $targetName = 'CmsPasswordGetForm' . substr(sha1($relativePath), 0, 12);
            if (isset($existingTargets[strtolower($targetName)])) {
                continue;
            }

            $topics[] = [
                'title' => sprintf('[CMS Topic P1] Rà soát form mật khẩu dùng GET tại %s:%d', $relativePath, $reviewLines[0]),
                'target_name' => $targetName,
                'priority' => 'P1',
                'specification' => sprintf(
                    'AutoTopic tìm thấy %d input type="password" trong form tại %s (dòng: %s) có method GET tường minh, bị bỏ trống hoặc không hợp lệ. HTML mặc định method thiếu/không hợp lệ là GET; nếu form gửi credential, giá trị có thể xuất hiện trong URL, browser history và server logs. Xác minh markup/runtime và thuộc tính formmethod trên nút submit; nếu thật sự gửi mật khẩu theo GET, dùng POST cùng các bảo vệ phù hợp. Detector chỉ đọc markup literal, không khẳng định người dùng nhập/gửi credential, không mô hình hóa đầy đủ formmethod động và không đánh giá URL đích. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.',
                    count($reviewLines),
                    $relativePath,
                    implode(', ', $reviewLines)
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
