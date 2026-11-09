# Tuzygent Autonomous Self-Evolving Code Harness Specification

Hệ thống **Tuzygent Harness** vận hành theo chu trình khép kín (**Recursive Self-Improvement & Verification Gate**):

1. **Pre-Flight Snapshot (`storage/snapshots/`)**:
   Mọi thay đổi đối với mã nguồn đều bắt đầu bằng việc chụp lại các tệp trong `config/`, `src/`, `public/`, `tests/` (gồm PHP, giao diện, CSS, JavaScript và cấu hình). Snapshot cũ chỉ chứa PHP vẫn được rollback tương thích.
2. **Research, Proposal & Generation (`research_and_develop` / Built-in Generator)**:
   - Tác vụ `research_and_develop` giao đề tài cho CLI agent headless (Codex, Claude Code, Gemini CLI, OpenCode hoặc Cursor Agent).
   - Codex được chạy bằng `codex exec --sandbox workspace-write --cd <repo> --approve-for-me`, phù hợp với phiên bản CLI đang cài trong môi trường.
   - Agent khảo sát codebase, nghiên cứu nguồn bên ngoài nếu công cụ và quyền truy cập sẵn có, ghi báo cáo tại `.harness/research/<task-code>.md`, rồi trực tiếp sửa source code.
   - Harness thu thập output của agent và danh sách tệp thay đổi. Chỉ báo cáo nghiên cứu mà không sửa mã nguồn sẽ không được chấp nhận.
   - Có thể tạo tác vụ bằng giao diện `/admin/harness` hoặc `./bin/tuzygent research --backend=codex "đề tài / tính năng"`.
   - Backend được chạy không tương tác với thời hạn 15 phút; mã thoát khác 0, không có thay đổi mã nguồn, hoặc Gate thất bại đều khiến vòng lặp retry và cuối cùng rollback.
   - Tác vụ nghiên cứu cần chọn một CLI agent đã cài đặt/đăng nhập. Built-in generator chỉ scaffold các lát cắt Hexagonal đơn giản.
3. **Proposal & Generation (`builtin-hexagonal` / Multi-Agent CLI)**:
   Sinh hoặc chỉnh sửa mã nguồn tuân thủ nghiêm ngặt kiến trúc **Hexagonal (Ports & Adapters)**:
   - `src/Domain/Entity/` (Thực thể nghiệp vụ thuần khiết)
   - `src/Domain/Port/Outbound/` (Cổng giao tiếp Outbound Interfaces)
   - `src/Infrastructure/Persistence/Sqlite/` (Bộ chuyển đổi Driven SQLite Adapters)
   - `src/Application/Service/` (Inbound Use Cases)
4. **3-Stage Verification Gate**:
   - **Gate 1**: Kiểm tra cú pháp PHP 8.5 (`php -l`) trên toàn bộ dự án.
   - **Gate 2**: Kiểm toán độ tinh khiết Hexagonal (`src/Domain` không chứa `PDO`, `$_POST`, `$_SESSION`, `App\Infrastructure` hay `App\Presentation`).
   - **Gate 3**: Chạy bộ kiểm thử tích hợp `php tests/hexagon_test.php`.
5. **Self-Healing & Auto-Rollback**:
   - Nếu bất kỳ Gate nào thất bại, thông tin lỗi được đưa ngược vào vòng lặp tiếp theo để tự sửa lỗi (`maxAttempts`).
   - Nếu hết số lần thử mà vẫn không vượt qua Gate, hệ thống **tự động Rollback** về Snapshot ban đầu để đảm bảo mã nguồn luôn ở trạng thái hoạt động hoàn hảo.
6. **Continuous Autonomous Mode (`continuous` / 24/7 Loop)**:
   - Chạy vòng lặp liên tục (`./bin/tuzygent continuous` hoặc bật trên Web Admin `/admin/harness`).
   - Tự động thực thi các Task trong Backlog theo thứ tự FIFO.
   - Khi Backlog trống, Continuous Mode mặc định dùng bộ phát hiện đề tài CMS để quét marker TODO/FIXME, Application Service chưa được nhắc tới trong tests và tệp PHP là outlier P90. Các đề tài có bằng chứng được thêm vào Backlog; khi không còn tín hiệu mới, Codex CLI brainstorm tiếp dựa trên repository. Nếu Codex chưa cài, Harness dùng blueprint Hexagonal tích hợp sẵn. Có thể tắt bằng `--no-auto-discover` trên CLI hoặc bỏ chọn Auto-Discover trên giao diện. Các backend agent còn lại chỉ được dùng khi chọn thủ công cho một task.
   - Hỗ trợ **Continuous Drift Auto-Heal**: Tuần tra 3-Stage Verification Gate định kỳ mỗi chu kỳ và tự động Rollback về Snapshot khỏe mạnh gần nhất nếu phát hiện lỗi cú pháp hoặc vi phạm kiến trúc.
