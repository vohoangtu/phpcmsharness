# TUZYGENT AUTONOMOUS SELF-EVOLVING HARNESS SPECIFICATION
- **Task Code**: TZY-359
- **Title**: [CMS Topic P2] Rà soát thứ bậc heading tại src/Presentation/Views/frontend/shop/index.php
- **Target Module/Entity**: CmsHeadingRankd2422acdf0d6
- **Specification**:
AutoTopic phát hiện một heading tăng cấp hơn một bậc trong template src/Presentation/Views/frontend/shop/index.php. Đây chỉ là gợi ý rà soát cấu trúc: kiểm tra HTML cuối sau khi ghép layout/partial và xác nhận các heading biểu đạt phân cấp có ý nghĩa. W3C khuyến cáo tránh nhảy cấp khi tạo subsection, nhưng việc bỏ cấp có thể hợp lý khi kết thúc subsection và các vùng cố định như sidebar có thể dùng rank nhất quán. Không đổi heading chỉ vì kiểu hiển thị; không coi tín hiệu này là kết luận vi phạm WCAG. Detector không dựng DOM, không phân tích nhánh PHP hoặc ngữ cảnh trang. Bổ sung hồi quy phù hợp rồi chạy ./bin/tuzygent verify.

## ARCHITECTURAL CONSTRAINTS (HEXAGONAL PORTS & ADAPTERS)
1. Every PHP file MUST start with `declare(strict_types=1);`.
2. `src/Domain/` MUST remain 100% pure PHP (no `PDO`, no `$_POST`/`$_SESSION`, no `App\Infrastructure` imports).
3. All database operations MUST go through an Outbound Port interface in `src/Domain/Port/Outbound/` and a Driven SQLite Adapter in `src/Infrastructure/Persistence/Sqlite/`.
4. All code changes MUST pass `./bin/tuzygent verify` (Syntax Lint + Hexagonal Purity Check + Integration Tests).
## EXECUTION PLAN
1. Inspect the current repository and identify the relevant existing features, conventions, and missing behavior.
2. For `research_and_develop`, research the requested topic with available web/search tools and reliable primary sources. Write a concise report to `.harness/research/TZY-359.md` before editing implementation code. Include the question, findings, source URLs, dates where available, and how each finding affects the feature. Never invent sources; state clearly when external research is unavailable.
3. Implement the requested, production-ready feature in this repository. Add or update focused regression coverage where appropriate, then run `./bin/tuzygent verify` and address failures caused by your changes.
4. Summarize changed files, decisions, verification results, and research limitations in your final response.
