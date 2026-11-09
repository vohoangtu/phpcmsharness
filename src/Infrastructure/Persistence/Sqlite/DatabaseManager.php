<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Sqlite;

use App\Domain\Enum\ModuleKey;
use PDO;

final class DatabaseManager
{
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config
    ) {
    }

    public function getConnection(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dbConfig = $this->config['db'] ?? [];
        $driver = $dbConfig['driver'] ?? 'sqlite';

        if ($driver === 'mysql') {
            $this->pdo = new PDO(
                (string) $dbConfig['mysql_dsn'],
                (string) $dbConfig['mysql_user'],
                (string) $dbConfig['mysql_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } else {
            $sqlitePath = (string) ($dbConfig['sqlite_path'] ?? dirname(__DIR__, 4) . '/storage/database.sqlite');
            $dir = dirname($sqlitePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $isNew = !file_exists($sqlitePath) || filesize($sqlitePath) === 0;
            $this->pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');

            if ($isNew) {
                $this->migrateAndSeed($this->pdo);
            } else {
                // Ensure modules table exists
                $this->ensureModulesSeeded($this->pdo);
            }
            $this->ensureMediaDimensions($this->pdo);
        }

        return $this->pdo;
    }

    private function migrateAndSeed(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS modules (
                module_key TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                description TEXT NOT NULL,
                icon TEXT NOT NULL,
                is_enabled INTEGER NOT NULL DEFAULT 1,
                depends_on TEXT NULL,
                updated_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                display_name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "admin",
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT "post",
                description TEXT NOT NULL DEFAULT ""
            );

            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                excerpt TEXT NOT NULL DEFAULT "",
                content TEXT NOT NULL DEFAULT "",
                featured_image TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "published",
                category_id INTEGER NULL,
                author_id INTEGER NOT NULL DEFAULT 1,
                views INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS post_tags (
                post_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id),
                FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                sku TEXT NOT NULL,
                short_description TEXT NOT NULL DEFAULT "",
                description TEXT NOT NULL DEFAULT "",
                price REAL NOT NULL DEFAULT 0,
                sale_price REAL NULL,
                stock_quantity INTEGER NOT NULL DEFAULT 0,
                featured_image TEXT NOT NULL DEFAULT "",
                gallery_images TEXT NOT NULL DEFAULT "[]",
                status TEXT NOT NULL DEFAULT "published",
                category_id INTEGER NULL,
                is_featured INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS product_tags (
                product_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (product_id, tag_id),
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                file_path TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL DEFAULT 0,
                alt_text TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                width INTEGER NULL,
                height INTEGER NULL
            );

            CREATE TABLE IF NOT EXISTS comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                commentable_type TEXT NOT NULL,
                commentable_id INTEGER NOT NULL,
                author_name TEXT NOT NULL,
                author_email TEXT NOT NULL,
                content TEXT NOT NULL,
                rating INTEGER NOT NULL DEFAULT 5,
                status TEXT NOT NULL DEFAULT "approved",
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_code TEXT NOT NULL UNIQUE,
                customer_name TEXT NOT NULL,
                customer_email TEXT NOT NULL,
                customer_phone TEXT NOT NULL,
                customer_address TEXT NOT NULL,
                note TEXT NOT NULL DEFAULT "",
                total_amount REAL NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "pending",
                created_at TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                product_name TEXT NOT NULL,
                unit_price REAL NOT NULL,
                quantity INTEGER NOT NULL,
                subtotal REAL NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL
            );
        ');

        $this->ensureModulesSeeded($pdo);
        $now = date('Y-m-d H:i:s');

        // Seed Admin User (admin / admin123)
        $stmtUser = $pdo->prepare('INSERT INTO users (username, email, password_hash, display_name, role, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $stmtUser->execute(['admin', 'admin@wp-hexagon.local', password_hash('admin123', PASSWORD_DEFAULT), 'Quản Trị Viên', 'admin', $now]);
        $stmtUser->execute(['editor', 'editor@wp-hexagon.local', password_hash('editor123', PASSWORD_DEFAULT), 'Biên Tập Viên', 'editor', $now]);

        // Seed Settings
        $defaultSettings = [
            'site_title' => 'WP-Hexagon CMS & Store',
            'site_tagline' => 'Hệ thống Quản trị Bài viết & Sản phẩm theo kiến trúc Hexagonal (Ports & Adapters)',
            'contact_email' => 'contact@wp-hexagon.local',
            'contact_phone' => '0901 234 567',
            'footer_text' => '© 2026 WP-Hexagon CMS. Xây dựng bằng PHP 8.5 Clean Code & Hexagonal Architecture.',
            'auto_approve_comments' => '1',
        ];
        $stmtSetting = $pdo->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)');
        foreach ($defaultSettings as $k => $v) {
            $stmtSetting->execute([$k, $v]);
        }

        // Seed Categories
        $stmtCat = $pdo->prepare('INSERT INTO categories (name, slug, type, description) VALUES (?, ?, ?, ?)');
        $stmtCat->execute(['Công nghệ & Lập trình', 'cong-nghe-lap-trinh', 'post', 'Tin tức công nghệ, kiến trúc phần mềm và PHP hiện đại']);
        $catPostTech = (int) $pdo->lastInsertId();
        $stmtCat->execute(['Kinh doanh & Thương mại', 'kinh-doanh-thuong-mai', 'post', 'Kinh nghiệm vận hành cửa hàng trực tuyến']);
        $catPostBiz = (int) $pdo->lastInsertId();

        $stmtCat->execute(['Laptop & Máy tính', 'laptop-may-tinh', 'product', 'Máy tính xách tay hiệu năng cao cho lập trình viên']);
        $catProdLaptop = (int) $pdo->lastInsertId();
        $stmtCat->execute(['Phụ kiện Công nghệ', 'phu-kien-cong-nghe', 'product', 'Bàn phím cơ, chuột không dây và màn hình đồ họa']);
        $catProdAcc = (int) $pdo->lastInsertId();

        // Seed Tags
        $stmtTag = $pdo->prepare('INSERT INTO tags (name, slug) VALUES (?, ?)');
        $stmtTag->execute(['Clean Code', 'clean-code']);
        $tagClean = (int) $pdo->lastInsertId();
        $stmtTag->execute(['Hexagonal Architecture', 'hexagonal-architecture']);
        $tagHex = (int) $pdo->lastInsertId();
        $stmtTag->execute(['PHP 8.5', 'php-8-5']);
        $tagPhp = (int) $pdo->lastInsertId();

        // Seed Sample Posts
        $stmtPost = $pdo->prepare('
            INSERT INTO posts (title, slug, excerpt, content, featured_image, status, category_id, author_id, views, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmtPost->execute([
            'Kiến trúc Hexagonal (Ports & Adapters) trong PHP 8.5: Bí quyết tách biệt Domain và Infrastructure',
            'kien-truc-hexagonal-ports-adapters-trong-php-8-5',
            'Tìm hiểu cách tổ chức mã nguồn CMS theo mô hình Hexagonal giúp dễ dàng bật/tắt module và thay đổi cơ sở dữ liệu mà không chạm vào nghiệp vụ.',
            '<p><strong>Kiến trúc Lục giác (Hexagonal Architecture)</strong> hay còn gọi là <em>Ports &amp; Adapters</em> được Alistair Cockburn giới thiệu nhằm giải quyết bài toán phụ thuộc chặt giữa tầng nghiệp vụ (Domain) và cơ sở dữ liệu hoặc giao diện người dùng.</p><h3>1. Nguyên tắc cốt lõi</h3><ul><li><strong>Domain Layer:</strong> Hoàn toàn thuần khiết, không chứa câu lệnh SQL hay biến toàn cục HTTP.</li><li><strong>Ports (Cổng giao tiếp):</strong> Các Interface định nghĩa đầu vào (Inbound Use Cases) và đầu ra (Outbound Repositories).</li><li><strong>Adapters (Bộ chuyển đổi):</strong> Thực thi cụ thể bằng PDO SQLite, MySQL, Session hoặc Web Controller.</li></ul><p>Nhờ kiến trúc này, hệ thống <strong>WP-Hexagon CMS</strong> cho phép bạn bật/tắt từng chức năng ngay trên bảng điều khiển mà vẫn đảm bảo sự ổn định tuyệt đối.</p>',
            'https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&w=900&q=80',
            'published',
            $catPostTech,
            1,
            128,
            $now,
            $now,
        ]);
        $post1Id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$post1Id, $tagClean]);
        $pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$post1Id, $tagHex]);
        $pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)')->execute([$post1Id, $tagPhp]);

        $stmtPost->execute([
            'Hướng dẫn quản lý Module Bật/Tắt linh hoạt trên Dashboard như WordPress Plugins',
            'huong-dan-quan-ly-module-bat-tat-linh-hoat-tren-dashboard',
            'Chỉ với 1 cú click trên Dashboard, bạn có thể chuyển đổi hệ thống từ trang thương mại điện tử đầy đủ sang trang Blog cá nhân hoặc Catalog trưng bày.',
            '<p>Một hệ thống CMS hiện đại cần có khả năng thích ứng nhanh với nhu cầu thực tế của doanh nghiệp:</p><ol><li>Khi cần <strong>bán hàng trực tuyến</strong>: Bật cả <code>Sản phẩm</code> và <code>Giỏ hàng &amp; Đơn hàng</code>.</li><li>Khi chỉ muốn <strong>trưng bày sản phẩm (Catalog)</strong>: Giữ <code>Sản phẩm</code> bật và tắt <code>Giỏ hàng &amp; Đơn hàng</code>.</li><li>Khi chỉ làm <strong>trang Tin tức / Blog</strong>: Tắt <code>Sản phẩm</code> và giữ <code>Bài viết</code>.</li></ol><p>Bạn có thể thử nghiệm ngay trong mục <strong>Bảng điều khiển &rarr; Quản lý Chức năng (Modules)</strong>!</p>',
            'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=900&q=80',
            'published',
            $catPostBiz,
            1,
            85,
            $now,
            $now,
        ]);

        // Seed Sample Products
        $stmtProd = $pdo->prepare('
            INSERT INTO products (name, slug, sku, short_description, description, price, sale_price, stock_quantity, featured_image, gallery_images, status, category_id, is_featured, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmtProd->execute([
            'MacBook Pro 16 M4 Max 48GB / 1TB SSD',
            'macbook-pro-16-m4-max-48gb-1tb',
            'MBP-M4MAX-16',
            'Chiếc laptop đỉnh cao dành cho kỹ sư phần mềm, kiến trúc sư hệ thống và nhà sáng tạo nội dung chuyên nghiệp.',
            '<p>Trang bị vi xử lý <strong>Apple M4 Max</strong> mạnh mẽ, màn hình Liquid Retina XDR 120Hz tuyệt đẹp cùng thời lượng pin lên đến 22 giờ liên tục.</p><ul><li>RAM: 48GB Unified Memory</li><li>SSD: 1TB NVMe tốc độ cao</li><li>Cổng kết nối: 3x Thunderbolt 5, HDMI, SDXC, MagSafe 3</li></ul>',
            89990000,
            84990000,
            15,
            'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=900&q=80',
            json_encode([
                'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?auto=format&fit=crop&w=900&q=80',
                'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?auto=format&fit=crop&w=900&q=80',
            ]),
            'published',
            $catProdLaptop,
            1,
            $now,
            $now,
        ]);
        $prod1Id = (int) $pdo->lastInsertId();

        $stmtProd->execute([
            'Bàn phím cơ không dây Keychron Q1 Pro QMK/VIA Aluminum',
            'ban-phim-co-khong-day-keychron-q1-pro',
            'KC-Q1PRO-ALU',
            'Bàn phím cơ Custom khung nhôm CNC nguyên khối, kết nối Bluetooth 5.1 & Type-C, hỗ trợ QMK/VIA.',
            '<p>Trải nghiệm gõ phím tuyệt hảo cho lập trình viên với cấu trúc Gasket Mount êm ái, switch K Pro Banana đã được lube sẵn từ nhà máy.</p>',
            5490000,
            4890000,
            32,
            'https://images.unsplash.com/photo-1587829741301-dc798b83add3?auto=format&fit=crop&w=900&q=80',
            json_encode([]),
            'published',
            $catProdAcc,
            1,
            $now,
            $now,
        ]);
        $prod2Id = (int) $pdo->lastInsertId();

        $stmtProd->execute([
            'Màn hình đồ họa Dell UltraSharp 27" 4K USB-C Hub U2723QE',
            'man-hinh-dell-ultrasharp-27-4k-u2723qe',
            'DELL-U2723QE',
            'Màn hình 4K tấm nền IPS Black độ tương phản 2000:1, chuẩn màu 98% DCI-P3, tích hợp Hub USB-C 90W.',
            '<p>Kết nối chỉ với 1 sợi cáp USB-C duy nhất vừa xuất hình ảnh 4K sắc nét vừa sạc ngược 90W cho Laptop.</p>',
            14500000,
            13290000,
            20,
            'https://images.unsplash.com/photo-1527443224154-c4a3942d3acf?auto=format&fit=crop&w=900&q=80',
            json_encode([]),
            'published',
            $catProdAcc,
            1,
            $now,
            $now,
        ]);

        // Seed Sample Comments
        $stmtCmt = $pdo->prepare('
            INSERT INTO comments (commentable_type, commentable_id, author_name, author_email, content, rating, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmtCmt->execute([
            'post',
            $post1Id,
            'Minh Hoàng (Senior Dev)',
            'hoang@example.com',
            'Bài viết giải thích rất rõ ràng về cách tách Domain Port và SQLite Adapter. Áp dụng vào dự án thực tế cực kỳ sạch!',
            5,
            'approved',
            $now,
        ]);
        $stmtCmt->execute([
            'product',
            $prod1Id,
            'Trần Tuấn Anh',
            'tuananh@example.com',
            'Máy chạy Docker và biên dịch dự án lớn nhanh đáng kinh ngạc, màn hình sáng đẹp.',
            5,
            'approved',
            $now,
        ]);

        // Seed Sample Order
        $stmtOrd = $pdo->prepare('
            INSERT INTO orders (order_code, customer_name, customer_email, customer_phone, customer_address, note, total_amount, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmtOrd->execute([
            'ORD-8892A1',
            'Nguyễn Văn Thành',
            'thanh.nguyen@example.com',
            '0988112233',
            'Tầng 12, Tòa nhà TechHub, Quận 1, TP. Hồ Chí Minh',
            'Giao giờ hành chính giúp mình nhé.',
            4890000,
            'completed',
            $now,
        ]);
        $ordId = (int) $pdo->lastInsertId();
        $pdo->prepare('
            INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$ordId, $prod2Id, 'Bàn phím cơ không dây Keychron Q1 Pro QMK/VIA Aluminum', 4890000, 1, 4890000]);
    }

    private function ensureModulesSeeded(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('
            INSERT OR IGNORE INTO modules (module_key, name, description, icon, is_enabled, depends_on, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, ?)
        ');
        foreach (ModuleKey::cases() as $case) {
            $stmt->execute([
                $case->value,
                $case->label(),
                $case->description(),
                $case->icon(),
                $case->dependsOn()?->value,
                $now,
            ]);
        }
    }

    private function ensureMediaDimensions(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS media_folders (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(parent_id) REFERENCES media_folders(id) ON DELETE SET NULL)');
        $columns = $pdo->query('PRAGMA table_info(media)')->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');
        if (!in_array('width', $columnNames, true)) {
            $pdo->exec('ALTER TABLE media ADD COLUMN width INTEGER NULL');
        }
        if (!in_array('height', $columnNames, true)) {
            $pdo->exec('ALTER TABLE media ADD COLUMN height INTEGER NULL');
        }
        if (!in_array('folder_id', $columnNames, true)) $pdo->exec('ALTER TABLE media ADD COLUMN folder_id INTEGER NULL');
        if (!in_array('tags', $columnNames, true)) $pdo->exec("ALTER TABLE media ADD COLUMN tags TEXT NOT NULL DEFAULT ''");
        if (!in_array('last_used_at', $columnNames, true)) $pdo->exec('ALTER TABLE media ADD COLUMN last_used_at TEXT NULL');
        if (!in_array('use_count', $columnNames, true)) $pdo->exec('ALTER TABLE media ADD COLUMN use_count INTEGER NOT NULL DEFAULT 0');
    }
}
