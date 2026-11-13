<?php

declare(strict_types=1);

namespace App\Domain\Enum;

enum ModuleKey: string
{
    case POSTS = 'posts';
    case PRODUCTS = 'products';
    case ORDERS = 'orders';
    case COMMENTS = 'comments';
    case MEDIA = 'media';
    case CATEGORIES_TAGS = 'categories_tags';
    case HARNESS = 'harness';
    case WORKERS = 'workers';
    case ANALYTICS = 'analytics';
    case PERFORMANCE = 'performance';

    public function label(): string
    {
        return match ($this) {
            self::POSTS => 'Bài viết & Tin tức (Blog)',
            self::PRODUCTS => 'Sản phẩm & Cửa hàng (Woo-Style)',
            self::ORDERS => 'Giỏ hàng & Đơn hàng (E-Commerce)',
            self::COMMENTS => 'Bình luận & Đánh giá 5 sao',
            self::MEDIA => 'Thư viện Media (Quản lý ảnh)',
            self::CATEGORIES_TAGS => 'Danh mục & Thẻ (Taxonomies)',
            self::HARNESS => 'Tuzygent AI Harness (Tự phát triển Code)',
            self::WORKERS => 'Workers (Hàng đợi tác vụ nền)',
            self::ANALYTICS => 'Analytics (Google Analytics 4)',
            self::PERFORMANCE => 'Performance (Tối ưu hiệu năng)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::POSTS => 'Cho phép soạn thảo, xuất bản bài viết tin tức/blog với trình soạn thảo Rich Text và hiển thị ngoài trang chủ.',
            self::PRODUCTS => 'Quản lý danh mục sản phẩm, giá bán, giá khuyến mãi, mã SKU, tồn kho và trang cửa hàng.',
            self::ORDERS => 'Kích hoạt giỏ hàng, trang đặt hàng (Checkout) và quản lý đơn đặt hàng. Khi tắt, cửa hàng hoạt động ở chế độ Catalog trưng bày.',
            self::COMMENTS => 'Cho phép khách gửi bình luận dưới bài viết và đánh giá 5 sao cho sản phẩm, kèm bộ lọc duyệt/spam trong Admin.',
            self::MEDIA => 'Quản lý tập trung hình ảnh tải lên, chọn ảnh nhanh khi viết bài hoặc đăng sản phẩm.',
            self::CATEGORIES_TAGS => 'Phân loại bài viết & sản phẩm theo Danh mục đa cấp và gắn thẻ Tags chuẩn SEO.',
            self::HARNESS => 'Hệ thống Autonomous Self-Evolving Code Harness kiểu Tuzygent: Quản lý Backlog, tự sinh/sửa code theo chuẩn Hexagonal, chạy Verification Gate, Self-Healing và Snapshot Rollback.',
            self::WORKERS => 'Hàng đợi SQLite bền vững và worker tuần tự để xử lý tác vụ nền từ Harness và các module khác.',
            self::ANALYTICS => 'Theo dõi hành vi và thương mại điện tử trên website, đọc báo cáo GA4 qua Google Analytics Data API.',
            self::PERFORMANCE => 'Theo dõi cấu hình hiệu năng, bật nén phản hồi, cache tài nguyên tĩnh và tối ưu chỉ mục cơ sở dữ liệu.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::POSTS => '📝',
            self::PRODUCTS => '🛍️',
            self::ORDERS => '🛒',
            self::COMMENTS => '💬',
            self::MEDIA => '🖼️',
            self::CATEGORIES_TAGS => '🏷️',
            self::HARNESS => '🤖',
            self::WORKERS => '⚙️',
            self::ANALYTICS => '📈',
            self::PERFORMANCE => '⚡',
        };
    }

    public function dependsOn(): ?self
    {
        return match ($this) {
            self::ORDERS => self::PRODUCTS,
            default => null,
        };
    }
}
