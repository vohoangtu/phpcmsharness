<?php

declare(strict_types=1);

use App\Application\Service\CommentService;
use App\Application\Service\CartService;
use App\Application\Service\CmsTopicGenerator;
use App\Application\Service\ModuleManagerService;
use App\Application\Service\OrderQueryService;
use App\Application\Service\OrderService;
use App\Application\Service\PostService;
use App\Application\Service\ProductService;
use App\Application\Service\SitemapXmlBuilder;
use App\Application\Service\RobotsTxtBuilder;
use App\Application\Service\TaxonomyService;
use App\Domain\Enum\ContentStatus;
use App\Domain\Enum\ProductSort;
use App\Domain\Enum\CommentStatus;
use App\Domain\Entity\Comment;
use App\Domain\Entity\Category;
use App\Domain\Entity\Tag;
use App\Domain\Enum\ModuleKey;
use App\Domain\Port\Outbound\CartPort;
use App\Domain\Port\Outbound\CategoryRepositoryPort;
use App\Domain\Port\Outbound\CommentRepositoryPort;
use App\Domain\Port\Outbound\TagRepositoryPort;
use App\Domain\Entity\User;
use App\Domain\Port\Outbound\AuthenticationSessionPort;
use App\Domain\Port\Outbound\UserRepositoryPort;
use App\Infrastructure\Container\Container;
use App\Infrastructure\Persistence\Sqlite\SqliteCommentRepository;
use App\Presentation\Http\SecurityHeaders;

require_once dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = dirname(__DIR__) . '/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$config = require dirname(__DIR__) . '/config/app.php';
$container = new Container($config);

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "❌ FAILED: {$msg}\n");
        exit(1);
    }
    echo "✅ PASSED: {$msg}\n";
}

final class CommentServiceRepositoryFake implements CommentRepositoryPort
{
    /** @var array<string, mixed> */
    public array $calls = [];

    /** @var Comment[] */
    public array $comments = [];

    public int $savedId = 41;

    /** @var array<string, int> */
    public array $statusCounts = ['pending' => 2, 'approved' => 3, 'spam' => 1];

    public function findAll(?CommentStatus $status = null, ?string $type = null, ?int $limit = null, int $offset = 0): array
    {
        $this->calls['findAll'] = [$status, $type, $limit, $offset];
        return $this->comments;
    }

    public function countMatching(?CommentStatus $status = null, ?string $type = null): int
    {
        $this->calls['countMatching'] = [$status, $type];
        return count($this->comments);
    }

    public function findApprovedForTarget(string $type, int $targetId): array
    {
        $this->calls['findApprovedForTarget'] = [$type, $targetId];
        return $this->comments;
    }

    public function save(Comment $comment): int
    {
        $this->calls['save'] = $comment;
        return $this->savedId;
    }

    public function updateStatus(int $id, CommentStatus $status): void
    {
        $this->calls['updateStatus'] = [$id, $status];
    }

    public function delete(int $id): void
    {
        $this->calls['delete'] = $id;
    }

    public function countByStatuses(): array
    {
        $this->calls['countByStatuses'] = true;
        return $this->statusCounts;
    }
}

final class TaxonomyServiceCategoryRepositoryFake implements CategoryRepositoryPort
{
    /** @var array<string, mixed> */
    public array $calls = [];

    /** @var Category[] */
    public array $categories = [];

    public int $savedId = 52;

    public function findByType(string $type): array
    {
        $this->calls['findByType'] = $type;
        return $this->categories;
    }

    public function findById(int $id): ?Category
    {
        $this->calls['findById'] = $id;
        foreach ($this->categories as $category) {
            if ($category->id === $id) {
                return $category;
            }
        }
        return null;
    }

    public function findBySlug(string $slug, string $type): ?Category
    {
        $this->calls['findBySlug'] = [$slug, $type];
        foreach ($this->categories as $category) {
            if ($category->slug === $slug && $category->type === $type) {
                return $category;
            }
        }
        return null;
    }

    public function save(Category $category): int
    {
        $this->calls['save'] = $category;
        return $category->id ?? $this->savedId;
    }

    public function delete(int $id): void
    {
        $this->calls['delete'] = $id;
    }
}

final class TaxonomyServiceTagRepositoryFake implements TagRepositoryPort
{
    /** @var array<string, mixed> */
    public array $calls = [];

    /** @var Tag[] */
    public array $tags = [];

    public int $savedId = 73;

    public function findAll(): array
    {
        $this->calls['findAll'] = true;
        return $this->tags;
    }

    public function save(Tag $tag): int
    {
        $this->calls['save'] = $tag;
        return $this->savedId;
    }

    public function delete(int $id): void
    {
        $this->calls['delete'] = $id;
    }

    public function syncPostTags(int $postId, array $tagNames): void
    {
        $this->calls['syncPostTags'] = [$postId, $tagNames];
    }

    public function syncProductTags(int $productId, array $tagNames): void
    {
        $this->calls['syncProductTags'] = [$productId, $tagNames];
    }
}

$_SERVER['HTTPS'] = 'on';
\App\Presentation\Http\SessionBootstrap::start();
unset($_SERVER['HTTPS']);
$sessionCookieParams = session_get_cookie_params();
assertTrue(ini_get('session.use_strict_mode') === '1', 'Session bootstrap rejects client-supplied uninitialized session IDs');
assertTrue(ini_get('session.use_only_cookies') === '1' && $sessionCookieParams['httponly'] && $sessionCookieParams['secure'], 'Session bootstrap restricts HTTPS session cookies to HttpOnly and Secure transport');
assertTrue(strtolower((string) $sessionCookieParams['samesite']) === 'lax', 'Session bootstrap sets an explicit SameSite=Lax policy');
assertTrue(
    SecurityHeaders::values() === [
        'Content-Security-Policy-Report-Only' => "default-src 'self'; base-uri 'self'; object-src 'none'; form-action 'self'; frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],
    'HTTP security header policy includes report-only CSP plus MIME, referrer, framing, browser-feature, and cross-origin opener protections'
);
$_SESSION = [];
$csrfToken = \App\Presentation\Http\Csrf::token();
assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $csrfToken), 'CSRF token is generated as a cryptographically random 256-bit hex value');
assertTrue(\App\Presentation\Http\Csrf::isValid($csrfToken), 'CSRF token accepts the matching session token');
assertTrue(!\App\Presentation\Http\Csrf::isValid('invalid-token'), 'CSRF token rejects a mismatched submitted value');

$galleryProduct = new \App\Domain\Entity\Product(
    id: 91,
    name: 'Ảnh & sản phẩm',
    slug: 'anh-san-pham',
    sku: 'IMG-91',
    shortDescription: '',
    description: '',
    price: 100000,
    salePrice: null,
    stockQuantity: 3,
    featuredImage: '',
    galleryImages: ['/uploads/anh-1.jpg', '/uploads/anh-2.jpg'],
    status: ContentStatus::PUBLISHED,
    categoryId: null,
    categoryName: null
);
$galleryMarkup = (static function (\App\Domain\Entity\Product $product): string {
    $reviews = [];
    $enabledModules = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/show.php';
    return (string) ob_get_clean();
})($galleryProduct);
assertTrue(
    str_contains($galleryMarkup, '<button')
        && str_contains($galleryMarkup, 'type="button"')
        && str_contains($galleryMarkup, 'aria-label="Hiển thị Ảnh &amp; sản phẩm — ảnh 1"')
        && str_contains($galleryMarkup, 'aria-pressed="true"')
        && str_contains($galleryMarkup, 'mainImage.src = button.dataset.imageSrc')
        && str_contains($galleryMarkup, 'mainImage.alt = button.dataset.imageAlt'),
    'Product gallery thumbnails render as keyboard-operable named buttons and update the main image accessible text'
);

$labeledProductFormMarkup = (static function (\App\Domain\Entity\Product $product): string {
    $reviews = [];
    $enabledModules = ['orders' => true, 'comments' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/show.php';
    return (string) ob_get_clean();
})($galleryProduct);
$productFormLabels = [
    'product-quantity-91' => ['Số lượng', 'quantity', 'input'],
    'product-review-author-name' => ['Họ và tên *', 'author_name', 'input'],
    'product-review-author-email' => ['Email *', 'author_email', 'input'],
    'product-review-rating' => ['Mức độ hài lòng (Số sao)', 'rating', 'select'],
    'product-review-content' => ['Nhận xét chi tiết *', 'content', 'textarea'],
];
$productFormLabelsAreAssociated = true;
foreach ($productFormLabels as $controlId => [$labelText, $fieldName, $controlTag]) {
    $escapedControlId = preg_quote($controlId, '~');
    $escapedLabelText = preg_quote($labelText, '~');
    $escapedFieldName = preg_quote($fieldName, '~');
    if (preg_match(
        '~<label for="' . $escapedControlId . '"[^>]*>\s*' . $escapedLabelText
            . '\s*</label>\s*<' . $controlTag . '\b(?=[^>]*\bid="' . $escapedControlId . '")(?=[^>]*\bname="' . $escapedFieldName . '")[^>]*>~u',
        $labeledProductFormMarkup
    ) !== 1 || substr_count($labeledProductFormMarkup, 'id="' . $controlId . '"') !== 1) {
        $productFormLabelsAreAssociated = false;
        break;
    }
}
assertTrue(
    $productFormLabelsAreAssociated
        && preg_match('~<input\b(?=[^>]*\bid="product-review-author-email")(?=[^>]*\btype="email")(?=[^>]*\bautocomplete="email")[^>]*>~', $labeledProductFormMarkup) === 1
        && preg_match('~<(?:input|textarea)\b(?=[^>]*\bid="(?:product-review-author-name|product-review-content)")(?=[^>]*\bautocomplete=)[^>]*>~', $labeledProductFormMarkup) !== 1,
    'Rendered product review form associates visible labels and identifies only the email field with its matching autocomplete purpose'
);

$cartLabelMarkup = (static function (): string {
    $cartLabelProduct = new \App\Domain\Entity\Product(
        id: 929,
        name: 'Tai nghe & loa',
        slug: 'tai-nghe-loa',
        sku: 'AUDIO-929',
        shortDescription: '',
        description: '',
        price: 125000,
        salePrice: null,
        stockQuantity: 4,
        featuredImage: '',
        galleryImages: [],
        status: ContentStatus::PUBLISHED,
        categoryId: null,
        categoryName: null
    );
    $cartData = [
        'items' => [['product' => $cartLabelProduct, 'quantity' => 2, 'subtotal' => 250000]],
        'total' => 250000,
        'count' => 2,
    ];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/cart.php';
    return (string) ob_get_clean();
})();
$cartFieldLabels = [
    'cart-quantity-929' => 'Số lượng cho Tai nghe &amp; loa',
    'checkout-customer-name' => 'Họ và tên người nhận *',
    'checkout-customer-phone' => 'Số điện thoại liên hệ *',
    'checkout-customer-email' => 'Email nhận thông báo đơn hàng *',
    'checkout-customer-address' => 'Địa chỉ giao hàng chi tiết *',
    'checkout-note' => 'Ghi chú đơn hàng (Tùy chọn)',
];
$cartLabelsAreAssociated = true;
foreach ($cartFieldLabels as $controlId => $labelText) {
    $escapedControlId = preg_quote($controlId, '~');
    $escapedLabelText = preg_quote($labelText, '~');
    if (preg_match('~<label for="' . $escapedControlId . '"[^>]*>' . $escapedLabelText
        . '</label>\s*<(?:input|textarea)\b[^>]*\bid="' . $escapedControlId . '"(?=[\s>])~u', $cartLabelMarkup) !== 1
        || substr_count($cartLabelMarkup, 'id="' . $controlId . '"') !== 1) {
        $cartLabelsAreAssociated = false;
        break;
    }
}
assertTrue(
    $cartLabelsAreAssociated
        && substr_count($cartLabelMarkup, '<label for="') === 6
        && str_contains($cartLabelMarkup, 'name="quantities[929]"')
        && str_contains($cartLabelMarkup, 'name="customer_name"')
        && str_contains($cartLabelMarkup, 'name="customer_phone"')
        && str_contains($cartLabelMarkup, 'name="customer_email"')
        && str_contains($cartLabelMarkup, 'name="customer_address"')
        && str_contains($cartLabelMarkup, 'name="note"')
        && str_contains($cartLabelMarkup, 'aria-label="Xóa Tai nghe &amp; loa khỏi giỏ hàng"'),
    'Rendered cart shows a unique explicit label for each quantity and checkout field while preserving submitted names and naming the remove button'
);

$userFormMarkup = (static function (): string {
    $users = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/users.php';
    return (string) ob_get_clean();
})();
$userFormLabels = [
    'user-username' => 'Tên đăng nhập (Username) *',
    'user-display-name' => 'Tên hiển thị *',
    'user-email' => 'Email *',
    'user-password' => 'Mật khẩu *',
    'user-role' => 'Vai trò (Role)',
];
$userFormLabelsAreAssociated = true;
foreach ($userFormLabels as $controlId => $labelText) {
    $escapedControlId = preg_quote($controlId, '/');
    $hasAssociatedVisibleLabel = preg_match(
        '/<label for="' . $escapedControlId . '">' . preg_quote($labelText, '/') . '<\/label>\s*<(?:input|select)\b[^>]*\bid="' . $escapedControlId . '"/',
        $userFormMarkup
    ) === 1;
    $userFormLabelsAreAssociated = $userFormLabelsAreAssociated && $hasAssociatedVisibleLabel;
}
assertTrue(
    $userFormLabelsAreAssociated
        && preg_match('/<input\b(?=[^>]*\bid="user-email")(?=[^>]*\btype="email")(?=[^>]*\bautocomplete="email")[^>]*>/', $userFormMarkup) === 1
        && preg_match('/<input\b(?=[^>]*\bid="user-password")(?=[^>]*\btype="password")(?=[^>]*\bautocomplete="new-password")[^>]*>/', $userFormMarkup) === 1,
    'Rendered admin user creation form exposes associated labels and matching email and new-password autocomplete purposes'
);

$commentRepository = new CommentServiceRepositoryFake();
$listedComment = new Comment(
    id: 9,
    commentableType: 'product',
    commentableId: 12,
    targetTitle: 'Sản phẩm thử',
    authorName: 'Người mua',
    authorEmail: 'buyer@example.com',
    content: 'Sản phẩm tốt',
    rating: 5,
    status: CommentStatus::APPROVED
);
$commentRepository->comments = [$listedComment];
$commentService = new CommentService($commentRepository);

assertTrue(
    $commentService->listComments(CommentStatus::PENDING, 'product', 10, 20) === [$listedComment]
        && $commentRepository->calls['findAll'] === [CommentStatus::PENDING, 'product', 10, 20],
    'Comment service returns repository results and forwards listing filters and pagination'
);
assertTrue(
    $commentService->countComments(CommentStatus::APPROVED, 'post') === 1
        && $commentRepository->calls['countMatching'] === [CommentStatus::APPROVED, 'post'],
    'Comment service returns the filtered comment count from its repository port'
);
assertTrue(
    $commentService->getApprovedComments('product', 12) === [$listedComment]
        && $commentRepository->calls['findApprovedForTarget'] === ['product', 12],
    'Comment service requests approved comments for the requested target'
);

$approvedCommentId = $commentService->addComment('product', 12, [
    'author_name' => '  Lan  ',
    'author_email' => ' lan@example.com ',
    'content' => '  Rất tốt  ',
    'rating' => 8,
]);
$approvedComment = $commentRepository->calls['save'];
assertTrue(
    $approvedCommentId === 41
        && $approvedComment instanceof Comment
        && $approvedComment->commentableType === 'product'
        && $approvedComment->commentableId === 12
        && $approvedComment->authorName === 'Lan'
        && $approvedComment->authorEmail === 'lan@example.com'
        && $approvedComment->content === 'Rất tốt'
        && $approvedComment->rating === 5
        && $approvedComment->status === CommentStatus::APPROVED,
    'Adding a comment trims user data, clamps rating to five, defaults to approved, and returns the saved ID'
);

$commentService->addComment('post', 7, ['rating' => -2], false);
$pendingComment = $commentRepository->calls['save'];
assertTrue(
    $pendingComment instanceof Comment
        && $pendingComment->authorName === 'Khách'
        && $pendingComment->authorEmail === ''
        && $pendingComment->content === ''
        && $pendingComment->rating === 1
        && $pendingComment->status === CommentStatus::PENDING,
    'Adding a comment without optional fields uses documented defaults and can require moderation'
);

$commentService->changeStatus(9, CommentStatus::SPAM);
$commentService->delete(9);
assertTrue(
    $commentRepository->calls['updateStatus'] === [9, CommentStatus::SPAM]
        && $commentRepository->calls['delete'] === 9,
    'Comment status changes and deletion are forwarded to the repository port'
);
assertTrue(
    $commentService->getStatusCounts() === $commentRepository->statusCounts
        && $commentRepository->calls['countByStatuses'] === true,
    'Comment status counts are returned from the repository port'
);

$categoryRepository = new TaxonomyServiceCategoryRepositoryFake();
$tagRepository = new TaxonomyServiceTagRepositoryFake();
$postCategory = new Category(11, 'Bài viết', 'bai-viet', 'post', 'Danh mục bài viết', 4);
$productCategory = new Category(12, 'Đồ uống', 'do-uong', 'product');
$tag = new Tag(21, 'Khuyến mãi', 'khuyen-mai', 3);
$categoryRepository->categories = [$postCategory, $productCategory];
$tagRepository->tags = [$tag];
$taxonomyService = new TaxonomyService($categoryRepository, $tagRepository);

assertTrue(
    $taxonomyService->getCategories('product') === [$postCategory, $productCategory]
        && $categoryRepository->calls['findByType'] === 'product',
    'Taxonomy service returns categories and forwards the requested type'
);
assertTrue(
    $taxonomyService->getCategoryById(12) === $productCategory
        && $taxonomyService->getCategoryById(99) === null
        && $categoryRepository->calls['findById'] === 99,
    'Taxonomy service returns a matching category by ID and preserves a missing result'
);
assertTrue(
    $taxonomyService->getCategoryBySlug('do-uong', 'product') === $productCategory
        && $taxonomyService->getCategoryBySlug('do-uong', 'post') === null
        && $categoryRepository->calls['findBySlug'] === ['do-uong', 'post'],
    'Taxonomy service looks up categories by both slug and type'
);

$newCategoryId = $taxonomyService->saveCategory([
    'name' => '  Đồ uống nóng  ',
    'slug' => '  ',
    'type' => 'product',
    'description' => '  Thức uống  ',
]);
$newCategory = $categoryRepository->calls['save'];
assertTrue(
    $newCategoryId === 52
        && $newCategory instanceof Category
        && $newCategory->id === null
        && $newCategory->name === 'Đồ uống nóng'
        && $newCategory->slug === 'do-uong-nong'
        && $newCategory->type === 'product'
        && $newCategory->description === 'Thức uống',
    'Saving a category trims fields, derives a Vietnamese slug, keeps a valid type, and returns its saved ID'
);

$updatedCategoryId = $taxonomyService->saveCategory([
    'id' => '12',
    'name' => '  Đồ uống  ',
    'slug' => ' nuoc-uong ',
    'type' => 'invalid',
]);
$updatedCategory = $categoryRepository->calls['save'];
assertTrue(
    $updatedCategoryId === 12
        && $updatedCategory instanceof Category
        && $updatedCategory->id === 12
        && $updatedCategory->name === 'Đồ uống'
        && $updatedCategory->slug === 'nuoc-uong'
        && $updatedCategory->type === 'post',
    'Saving a category preserves its update ID, trims an explicit slug, and defaults an invalid type to post'
);

$taxonomyService->saveCategory([]);
$defaultCategory = $categoryRepository->calls['save'];
assertTrue(
    $defaultCategory instanceof Category
        && $defaultCategory->name === 'Danh mục mới'
        && $defaultCategory->slug === 'danh-muc-moi'
        && $defaultCategory->type === 'post',
    'Saving an empty category payload uses the default name, derived slug, and post type'
);

$taxonomyService->deleteCategory(12);
assertTrue(
    $categoryRepository->calls['delete'] === 12,
    'Taxonomy service forwards category deletion to its repository port'
);
assertTrue(
    $taxonomyService->getAllTags() === [$tag]
        && $tagRepository->calls['findAll'] === true,
    'Taxonomy service returns all tags from its repository port'
);

$savedTagId = $taxonomyService->saveTag(['name' => '  Khuyến mãi Tết  ', 'slug' => '  ']);
$savedTag = $tagRepository->calls['save'];
assertTrue(
    $savedTagId === 73
        && $savedTag instanceof Tag
        && $savedTag->id === null
        && $savedTag->name === 'Khuyến mãi Tết'
        && $savedTag->slug === 'khuyen-mai-tet',
    'Saving a tag trims its name, derives a Vietnamese slug, and returns its saved ID'
);

$taxonomyService->saveTag(['name' => '  Ưu đãi  ', 'slug' => ' uu-dai ']);
$explicitSlugTag = $tagRepository->calls['save'];
assertTrue(
    $explicitSlugTag instanceof Tag
        && $explicitSlugTag->name === 'Ưu đãi'
        && $explicitSlugTag->slug === 'uu-dai',
    'Saving a tag trims and uses a supplied slug'
);

$taxonomyService->deleteTag(21);
assertTrue(
    $tagRepository->calls['delete'] === 21,
    'Taxonomy service forwards tag deletion to its repository port'
);

$logoutRouteSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$adminLayoutSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/layouts/admin.php');
$frontendLayoutSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/layouts/frontend.php');
$dashboardSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/dashboard.php');
$moduleControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Admin/ModuleController.php');
assertTrue(
    str_contains($logoutRouteSource, "\$router->post('/admin/logout'")
        && !str_contains($logoutRouteSource, "\$router->get('/admin/logout'"),
    'Logout is registered as a state-changing POST route'
);
foreach ([$adminLayoutSource, $frontendLayoutSource] as $logoutLayoutSource) {
    assertTrue(
        preg_match('~<form method="post" action="/admin/logout"[^>]*>\s*<\?= \\\\App\\\\Presentation\\\\Http\\\\Csrf::field\(\) \?>~', $logoutLayoutSource) === 1,
        'Logout controls submit a CSRF-protected POST form'
    );
    assertTrue(!str_contains($logoutLayoutSource, 'href="/admin/logout"'), 'Logout is not triggered by a GET link');
}
preg_match('~<form method="POST" action="/admin/modules/toggle" class="wp-toggle-form">(?P<body>.*?)</form>~s', $dashboardSource, $quickToggleFormMatch);
$quickToggleFormBody = $quickToggleFormMatch['body'] ?? '';
preg_match('~<input(?P<attributes>[^>]*type="checkbox"[^>]*)>~s', $quickToggleFormBody, $quickToggleCheckboxMatch);
$quickToggleCheckboxAttributes = $quickToggleCheckboxMatch['attributes'] ?? '';
assertTrue(
    str_contains($quickToggleFormBody, 'name="module_key"')
        && str_contains($quickToggleFormBody, 'name="state"')
        && str_contains($quickToggleFormBody, 'onchange="this.form.submit()"')
        && !preg_match('/\bname\s*=/', $quickToggleCheckboxAttributes)
        && str_contains($moduleControllerSource, "\$_POST['module_key']")
        && str_contains($moduleControllerSource, "\$_POST['state']"),
    'Dashboard quick-toggle checkbox only triggers submission while named hidden fields carry the state consumed by ModuleController'
);

$postRoutesSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
$postControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Admin/PostController.php');
$postFormSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php');
$routerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Http/Router.php');
preg_match('/public function create\(\): void\s*\{(?P<body>.*?)\n    \}/s', $postControllerSource, $createMethodMatch);
$createMethodBody = $createMethodMatch['body'] ?? '';
assertTrue(
    str_contains($postRoutesSource, "\$router->get('/admin/posts/create', [\$postCtrl, 'create']")
        && !str_contains($postRoutesSource, "\$router->post('/admin/posts/create'"),
    'Post creation form remains a GET route because opening it is a safe read operation'
);
assertTrue(
    preg_match('/\$this->renderForm\(null\);/', $createMethodBody) === 1
        && !preg_match('/savePost|changeStatus|deletePermanently|flash\(|redirect\(|\$_SESSION/', $createMethodBody),
    'PostController::create only renders a new post form and does not perform a post or session mutation'
);
assertTrue(
    str_contains($postFormSource, '<form method="POST" action="/admin/posts/save"')
        && str_contains($postFormSource, '\\App\\Presentation\\Http\\Csrf::field()')
        && str_contains($routerSource, "\$route['method'] !== 'GET' && !Csrf::isValid"),
    'Saving the post remains a CSRF-protected POST enforced by the central router guard'
);
$postFormControlIds = [
    'post-title',
    'post-slug',
    'raw-content',
    'post-excerpt',
    'post-status',
    'post-category',
    'post-tags',
    'featured-image-input',
    'featured-image-file',
];
foreach ($postFormControlIds as $postFormControlId) {
    assertTrue(
        preg_match('~<label\b(?=[^>]*\bfor="' . preg_quote($postFormControlId, '~') . '")[^>]*>~', $postFormSource) === 1
            && str_contains($postFormSource, 'id="' . $postFormControlId . '"'),
        'Post form control ' . $postFormControlId . ' has a visible label explicitly associated by for/id'
    );
}
assertTrue(
    str_contains($postFormSource, 'aria-labelledby="post-content-label"')
        && str_contains($postFormSource, '<label id="post-content-label" for="raw-content">Nội dung bài viết</label>'),
    'The visual content editor and source textarea share the visible post-content label'
);
$renderedPostFormMarkup = (static function (): string {
    $post = null;
    $categories = [];
    $mediaFiles = [];
    $enabledModules = ['categories_tags' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php';
    return (string) ob_get_clean();
})();
$renderedPostFormLabelsAssociated = true;
foreach ($postFormControlIds as $postFormControlId) {
    if (!preg_match('~<label\b(?=[^>]*\bfor="' . preg_quote($postFormControlId, '~') . '")[^>]*>~', $renderedPostFormMarkup)
        || !str_contains($renderedPostFormMarkup, 'id="' . $postFormControlId . '"')) {
        $renderedPostFormLabelsAssociated = false;
    }
}
assertTrue(
    $renderedPostFormLabelsAssociated
        && str_contains($renderedPostFormMarkup, 'aria-labelledby="post-content-label"'),
    'Rendered admin post form exposes visible, explicitly associated labels for all nine data controls and the visual editor'
);
assertTrue(
    substr_count($renderedPostFormMarkup, 'id="featured-preview-img"') === 1
        && preg_match('~<img\s+src=""\s+id="featured-preview-img"\s+alt=""\s+style="display:none;"~', $renderedPostFormMarkup) === 1,
    'Rendered new post form has one hidden featured-image preview target for JavaScript'
);
$postWithFeaturedImage = new \App\Domain\Entity\Post(
    id: 1,
    title: 'Ảnh bài viết',
    slug: 'anh-bai-viet',
    excerpt: '',
    content: '',
    featuredImage: '/uploads/featured.png?size=large&fit=crop',
    status: \App\Domain\Enum\ContentStatus::PUBLISHED,
    categoryId: null,
    categoryName: null,
    authorId: 1,
    authorName: 'Admin'
);
$renderedPostFormWithFeaturedImage = (static function () use ($postWithFeaturedImage): string {
    $post = $postWithFeaturedImage;
    $categories = [];
    $mediaFiles = [];
    $enabledModules = ['categories_tags' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php';
    return (string) ob_get_clean();
})();
assertTrue(
    substr_count($renderedPostFormWithFeaturedImage, 'id="featured-preview-img"') === 1
        && preg_match('~<img\s+src="/uploads/featured\.png\?size=large&amp;fit=crop"\s+id="featured-preview-img"\s+alt=""\s*>~', $renderedPostFormWithFeaturedImage) === 1,
    'Rendered edit post form has one visible featured-image preview and escapes its URL'
);
$postFormMedia = [new \App\Domain\Entity\MediaFile(
    id: 1,
    filename: 'media.png',
    originalName: 'Media.png',
    filePath: '/uploads/media.png',
    mimeType: 'image/png',
    fileSize: 1024,
    altText: 'Mô tả & "ảnh"',
)];
$renderedPostFormWithMedia = (static function () use ($postFormMedia): string {
    $post = null;
    $categories = [];
    $mediaFiles = $postFormMedia;
    $enabledModules = ['categories_tags' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php';
    return (string) ob_get_clean();
})();
assertTrue(
    preg_match('~<img\s+src="/uploads/media\.png"\s+alt="Mô tả &amp; &quot;ảnh&quot;"~', $renderedPostFormWithMedia) === 1,
    'Rendered post media-picker thumbnail includes the supplied alt text and escapes it for HTML'
);
$renderedPostIndexMarkup = (static function (): string {
    $posts = [];
    $counts = [];
    $categories = [];
    $currentStatus = '';
    $currentCategory = null;
    $search = '';
    $currentPage = 1;
    $totalPages = 1;
    $totalItems = 0;
    $pageSize = 20;
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/posts/index.php';
    return (string) ob_get_clean();
})();
$postIndexFilterLabelsAssociated = true;
foreach ([
    'post-category-filter' => 'Lọc theo danh mục',
    'post-search' => 'Tìm bài viết',
] as $controlId => $labelText) {
    if (!preg_match('~<label\\b(?=[^>]*\\bfor="' . preg_quote($controlId, '~') . '")[^>]*>\\s*'
        . preg_quote($labelText, '~') . '\\s*</label>~u', $renderedPostIndexMarkup)
        || !preg_match('~<(?:select|input)\\b(?=[^>]*\\bid="' . preg_quote($controlId, '~') . '")[^>]*>~', $renderedPostIndexMarkup)) {
        $postIndexFilterLabelsAssociated = false;
    }
}
assertTrue(
    $postIndexFilterLabelsAssociated,
    'Rendered admin post filters expose visible labels explicitly associated with the category and search controls'
);

$productControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Admin/ProductController.php');
$productFormSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/products/form.php');
preg_match('/public function create\(\): void\s*\{(?P<body>.*?)\n    \}/s', $productControllerSource, $productCreateMethodMatch);
$productCreateMethodBody = $productCreateMethodMatch['body'] ?? '';
assertTrue(
    str_contains($postRoutesSource, "\$router->get('/admin/products/create', [\$prodCtrl, 'create']")
        && !str_contains($postRoutesSource, "\$router->post('/admin/products/create'"),
    'Product creation form remains a GET route because opening it does not change product or authentication state'
);
assertTrue(
    preg_match('/\$this->renderForm\(null\);/', $productCreateMethodBody) === 1
        && !preg_match('/saveProduct|changeStatus|deletePermanently|flash\(|redirect\(|\$_SESSION/', $productCreateMethodBody),
    'ProductController::create only renders a new product form without changing product or direct session state'
);
assertTrue(
    str_contains($productFormSource, '<form method="POST" action="/admin/products/save"')
        && str_contains($productFormSource, '\\App\\Presentation\\Http\\Csrf::field()')
        && str_contains($routerSource, "\$route['method'] !== 'GET' && !Csrf::isValid"),
    'Saving the product remains a CSRF-protected POST enforced by the central router guard'
);
$productFormControlIds = [
    'post-title',
    'post-slug',
    'product-price',
    'product-sale-price',
    'product-sku',
    'product-stock-quantity',
    'raw-content',
    'product-short-description',
    'product-gallery-images',
    'product-status',
    'product-category',
    'product-tags',
    'featured-image-input',
    'featured-image-file',
];
$renderedProductFormMarkup = (static function (): string {
    $product = null;
    $categories = [];
    $mediaFiles = [];
    $enabledModules = ['categories_tags' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/products/form.php';
    return (string) ob_get_clean();
})();
$renderedProductFormLabelsAssociated = true;
foreach ($productFormControlIds as $productFormControlId) {
    if (!preg_match('~<label\\b(?=[^>]*\\bfor="' . preg_quote($productFormControlId, '~') . '")[^>]*>~', $renderedProductFormMarkup)
        || !preg_match('~<(?:input|select|textarea)\\b(?=[^>]*\\bid="' . preg_quote($productFormControlId, '~') . '")[^>]*>~', $renderedProductFormMarkup)
        || substr_count($renderedProductFormMarkup, 'id="' . $productFormControlId . '"') !== 1) {
        $renderedProductFormLabelsAssociated = false;
    }
}
assertTrue(
    $renderedProductFormLabelsAssociated
        && str_contains($renderedProductFormMarkup, 'aria-labelledby="product-description-label"')
        && str_contains($renderedProductFormMarkup, '<label class="wp-checkbox-label">')
        && substr_count($renderedProductFormMarkup, 'name="is_featured"') === 1
        && substr_count($renderedProductFormMarkup, 'id="featured-preview-img"') === 1
        && preg_match('~<img src="" id="featured-preview-img" alt="" style="display:none;">~', $renderedProductFormMarkup) === 1,
    'Rendered admin product form exposes visible labels for all fourteen data controls, a named visual editor, and its wrapped checkbox label'
);
$productFormMedia = [
    new \App\Domain\Entity\MediaFile(
        id: 1,
        filename: 'media.png',
        originalName: 'Media.png',
        filePath: '/uploads/media.png',
        mimeType: 'image/png',
        fileSize: 1024,
        altText: 'Mô tả & "ảnh"'
    ),
    new \App\Domain\Entity\MediaFile(
        id: 2,
        filename: 'decorative.png',
        originalName: 'Decorative.png',
        filePath: '/uploads/decorative.png',
        mimeType: 'image/png',
        fileSize: 1024,
        altText: ''
    ),
];
$renderedProductFormWithMedia = (static function () use ($productFormMedia): string {
    $product = null;
    $categories = [];
    $mediaFiles = $productFormMedia;
    $enabledModules = ['categories_tags' => true];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/products/form.php';
    return (string) ob_get_clean();
})();
assertTrue(
    str_contains($renderedProductFormWithMedia, 'src="/uploads/media.png"')
        && str_contains($renderedProductFormWithMedia, 'alt="Mô tả &amp; &quot;ảnh&quot;"')
        && str_contains($renderedProductFormWithMedia, 'src="/uploads/decorative.png"')
        && str_contains($renderedProductFormWithMedia, 'alt=""'),
    'Rendered product media-picker thumbnails include escaped supplied alt text and preserve an empty alt value'
);
$sanitizedProductDescription = \App\Presentation\Security\HtmlContentSanitizer::sanitize(
    '<p><strong>Bold</strong><script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)" onclick="alert(4)">Link</a></p>'
);
assertTrue(
    str_contains($sanitizedProductDescription, '<p><strong>Bold</strong>')
        && str_contains($sanitizedProductDescription, '<a>Link</a>')
        && !preg_match('/<script|<img|onerror|onclick|javascript:/i', $sanitizedProductDescription),
    'Shared HTML content sanitizer preserves allowed rich formatting and removes script, event-handler, image, and unsafe-link payloads'
);
$renderedBlogPostContent = (static function (): string {
    $post = new \App\Domain\Entity\Post(
        id: 1,
        title: 'Example article',
        slug: 'example-article',
        excerpt: '',
        content: '<h2>Useful details</h2><p><strong>Keep this formatting</strong> and <a href="https://example.com" title="Reference">a safe link</a>.</p>'
            . '<script>alert(1)</script><p onclick="alert(2)">Unsafe event</p>'
            . '<svg onload="alert(3)"><script>alert(4)</script></svg>'
            . '<iframe src="javascript:alert(5)"></iframe>'
            . '<a href="data:text/html,<script>alert(6)</script>" onmouseover="alert(7)">Unsafe link</a>',
        featuredImage: '',
        status: \App\Domain\Enum\ContentStatus::PUBLISHED,
        categoryId: null,
        categoryName: 'News',
        authorId: 1,
        authorName: 'Editor',
        createdAt: '2026-01-01 12:00:00'
    );
    $comments = [];
    $enabledModules = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/show.php';
    return (string) ob_get_clean();
})();
assertTrue(
    str_contains($renderedBlogPostContent, '<h2>Useful details</h2>')
        && str_contains($renderedBlogPostContent, '<strong>Keep this formatting</strong>')
        && str_contains($renderedBlogPostContent, '<a href="https://example.com" title="Reference">a safe link</a>')
        && !preg_match('/<script|<svg|<iframe|onclick|onload|onmouseover|javascript:|data:text\/html/i', $renderedBlogPostContent),
    'Rendered blog post keeps allowed editorial formatting and safe links while removing active HTML and unsafe URI payloads'
);
$renderedProductDescription = (static function (): string {
    $product = new \App\Domain\Entity\Product(
        id: 1,
        name: 'Example',
        slug: 'example',
        sku: 'SKU-1',
        shortDescription: '',
        description: '<h2>Details</h2><p><strong>Safe formatting</strong> <a href="https://example.com">Reference</a></p><script>alert(1)</script><img src=x onerror="alert(2)"><a href="javascript:alert(3)" onclick="alert(4)">Unsafe link</a>',
        price: 1,
        salePrice: null,
        stockQuantity: 1,
        featuredImage: '',
        galleryImages: [],
        status: \App\Domain\Enum\ContentStatus::PUBLISHED,
        categoryId: null,
        categoryName: null,
        isFeatured: false
    );
    $reviews = [];
    $enabledModules = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/show.php';
    return (string) ob_get_clean();
})();
assertTrue(
    str_contains($renderedProductDescription, '<h2>Details</h2>')
        && str_contains($renderedProductDescription, '<strong>Safe formatting</strong>')
        && str_contains($renderedProductDescription, '<a href="https://example.com">Reference</a>')
        && !preg_match('/<script|<img|onerror|onclick|javascript:/i', $renderedProductDescription),
    'Storefront product description keeps editorial formatting and safe links while removing dangerous HTML payloads'
);
$renderedProductDescriptionWithActiveContent = (static function (): string {
    $product = new \App\Domain\Entity\Product(
        id: 1,
        name: 'Example',
        slug: 'example',
        sku: 'SKU-1',
        shortDescription: '',
        description: '<p>Keep <em>emphasis</em> and <ul><li>list items</li></ul></p>'
            . '<blockquote>Editorial quote</blockquote>'
            . '<a href="mailto:support@example.com" title="Contact support" target="_blank" data-track="unsafe">Email support</a>'
            . '<svg onload="alert(1)"><script>alert(2)</script></svg>'
            . '<iframe src="javascript:alert(3)"></iframe>'
            . '<a href="data:text/html,<script>alert(4)</script>" onmouseover="alert(5)">Unsafe URI</a>'
            . '<style>body{background:url(javascript:alert(6))}</style>',
        price: 1,
        salePrice: null,
        stockQuantity: 1,
        featuredImage: '',
        galleryImages: [],
        status: \App\Domain\Enum\ContentStatus::PUBLISHED,
        categoryId: null,
        categoryName: null,
        isFeatured: false
    );
    $reviews = [];
    $enabledModules = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/show.php';
    return (string) ob_get_clean();
})();
assertTrue(
    str_contains($renderedProductDescriptionWithActiveContent, '<em>emphasis</em>')
        && str_contains($renderedProductDescriptionWithActiveContent, '<li>list items</li>')
        && str_contains($renderedProductDescriptionWithActiveContent, '<blockquote>Editorial quote</blockquote>')
        && str_contains($renderedProductDescriptionWithActiveContent, '<a href="mailto:support@example.com" title="Contact support">Email support</a>')
        && !str_contains($renderedProductDescriptionWithActiveContent, 'target="_blank"')
        && !str_contains($renderedProductDescriptionWithActiveContent, 'data-track=')
        && !preg_match('/<svg|<iframe|<style|<script|onload|onmouseover|javascript:|data:text\/html/i', $renderedProductDescriptionWithActiveContent),
    'Storefront product description retains allowed editor formatting and mail links while removing disallowed attributes and active HTML payloads'
);
$productFormWithUntrustedDescription = (static function (): string {
    $product = new \App\Domain\Entity\Product(
        id: 1,
        name: 'Example',
        slug: 'example',
        sku: 'SKU-1',
        shortDescription: '',
        description: '<p>Safe</p><script>alert(1)</script>',
        price: 1,
        salePrice: null,
        stockQuantity: 1,
        featuredImage: '/uploads/featured.png?size=large&fit=crop',
        galleryImages: [],
        status: \App\Domain\Enum\ContentStatus::DRAFT,
        categoryId: null,
        categoryName: null,
        isFeatured: false
    );
    $categories = [];
    $mediaFiles = [];
    $enabledModules = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/products/form.php';
    return (string) ob_get_clean();
})();
assertTrue(
    str_contains($productFormWithUntrustedDescription, '<p>Safe</p>')
        && !str_contains($productFormWithUntrustedDescription, '<script>alert(1)</script>')
        && str_contains($productFormWithUntrustedDescription, '&lt;script&gt;alert(1)&lt;/script&gt;'),
    'Admin product form sanitizes the visual editor and HTML-escapes the editable source textarea'
);
assertTrue(
    substr_count($productFormWithUntrustedDescription, 'id="featured-preview-img"') === 1
        && preg_match('~<img src="/uploads/featured\.png\?size=large&amp;fit=crop" id="featured-preview-img" alt="">~', $productFormWithUntrustedDescription) === 1,
    'Rendered admin product form emits one visible preview image with an escaped saved URL'
);
$renderedProductIndexMarkup = (static function (): string {
    $products = [];
    $counts = ['all' => 0, 'published' => 0, 'draft' => 0, 'trash' => 0];
    $categories = [];
    $currentStatus = '';
    $stockFilter = '';
    $currentCategory = null;
    $search = '';
    $currentPage = 1;
    $totalPages = 1;
    $totalItems = 0;
    $pageSize = 20;
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/products/index.php';
    return (string) ob_get_clean();
})();
assertTrue(
    preg_match('~<label for="product-category-filter">Lọc theo danh mục sản phẩm</label>\s*<select id="product-category-filter"(?=\s|>)~', $renderedProductIndexMarkup) === 1
        && preg_match('~<label for="product-search">Tìm tên sản phẩm hoặc mã SKU</label>\s*<input id="product-search"(?=\s|>)~', $renderedProductIndexMarkup) === 1
        && substr_count($renderedProductIndexMarkup, 'id="product-category-filter"') === 1
        && substr_count($renderedProductIndexMarkup, 'id="product-search"') === 1,
    'Rendered admin product filters show visible labels explicitly associated with the category and search controls'
);

// Category form labels must be explicitly associated with their rendered controls.
$categoriesViewMarkup = (static function (): string {
    $type = 'post';
    $categories = [];
    $editingCategory = null;
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/taxonomies/categories.php';
    return (string) ob_get_clean();
})();
$categoryLabelPairs = [
    'category-name' => ['Tên danh mục *', 'input'],
    'category-slug' => ['Đường dẫn tĩnh (Slug)', 'input'],
    'category-description' => ['Mô tả', 'textarea'],
];
$categoryLabelsAssociated = true;
foreach ($categoryLabelPairs as $controlId => [$labelText, $controlTag]) {
    if (!preg_match('~<label\\b(?=[^>]*\\bfor="' . preg_quote($controlId, '~') . '")[^>]*>\\s*'
        . preg_quote($labelText, '~') . '\\s*</label>~u', $categoriesViewMarkup)
        || !preg_match('~<' . $controlTag . '\\b(?=[^>]*\\bid="' . preg_quote($controlId, '~') . '")[^>]*>~', $categoriesViewMarkup)
        || substr_count($categoriesViewMarkup, 'id="' . $controlId . '"') !== 1) {
        $categoryLabelsAssociated = false;
    }
}
assertTrue(
    $categoryLabelsAssociated,
    'Rendered admin category form exposes visible labels explicitly associated with all three unique data controls'
);

// Tag form labels must be explicitly associated with their rendered controls.
$tagsViewMarkup = (static function (): string {
    $tags = [];
    ob_start();
    require dirname(__DIR__) . '/src/Presentation/Views/admin/taxonomies/tags.php';
    return (string) ob_get_clean();
})();
$tagLabelPairs = [
    'tag-name' => 'Tên thẻ *',
    'tag-slug' => 'Slug (Tùy chọn)',
];
$tagLabelsAssociated = true;
foreach ($tagLabelPairs as $controlId => $labelText) {
    if (!preg_match('~<label\\b(?=[^>]*\\bfor="' . preg_quote($controlId, '~') . '")[^>]*>\\s*'
        . preg_quote($labelText, '~') . '\\s*</label>~u', $tagsViewMarkup)
        || !preg_match('~<input\\b(?=[^>]*\\bid="' . preg_quote($controlId, '~') . '")[^>]*>~', $tagsViewMarkup)
        || substr_count($tagsViewMarkup, 'id="' . $controlId . '"') !== 1) {
        $tagLabelsAssociated = false;
    }
}
assertTrue(
    $tagLabelsAssociated,
    'Rendered admin tag form exposes visible labels explicitly associated with both unique data controls'
);

$authEvents = new stdClass();
$authEvents->events = [];
$authEvents->principal = null;
$authUser = new User(42, 'operator', 'operator@example.test', password_hash('correct horse', PASSWORD_DEFAULT), 'Operator', 'admin');
$authUserRepository = new class($authUser) implements UserRepositoryPort {
    public function __construct(private User $user) {}
    public function findAll(): array { return [$this->user]; }
    public function findById(int $id): ?User { return $id === $this->user->id ? $this->user : null; }
    public function findByUsername(string $username): ?User { return $username === $this->user->username ? $this->user : null; }
    public function save(User $user): int { return (int) $user->id; }
    public function delete(int $id): void {}
};
$authSession = new class($authEvents) implements AuthenticationSessionPort {
    public function __construct(private stdClass $state) {}
    public function regenerateId(bool $deleteOldSession = false): bool { $this->state->events[] = $deleteOldSession ? 'regenerate:delete-old' : 'regenerate'; return true; }
    public function storePrincipal(array $principal): void { $this->state->events[] = 'store'; $this->state->principal = $principal; }
    public function currentPrincipal(): ?array { return $this->state->principal; }
    public function clearPrincipal(): void { $this->state->principal = null; }
};
$attemptState = new stdClass();
$attemptState->rows = [];
$attemptState->now = 1_800_000_000;
$attemptRepository = new class($attemptState) implements \App\Domain\Port\Outbound\LoginAttemptRepositoryPort {
    public function __construct(private stdClass $state) {}
    public function find(string $identifierHash): ?array { return $this->state->rows[$identifierHash] ?? null; }
    public function save(string $identifierHash, int $failures, int $windowStartedAt, int $blockedUntil): void
    {
        $this->state->rows[$identifierHash] = [
            'failures' => $failures,
            'window_started_at' => $windowStartedAt,
            'blocked_until' => $blockedUntil,
        ];
    }
    public function delete(string $identifierHash): void { unset($this->state->rows[$identifierHash]); }
    public function deleteBefore(int $timestamp): void
    {
        foreach ($this->state->rows as $key => $row) {
            if ($row['window_started_at'] < $timestamp && $row['blocked_until'] < $timestamp) {
                unset($this->state->rows[$key]);
            }
        }
    }
};
$authService = new \App\Application\Service\AuthService(
    $authUserRepository,
    $authSession,
    $attemptRepository,
    static fn(): int => $attemptState->now
);
assertTrue($authService->attemptLogin('operator', 'wrong password') === null && $authEvents->events === [], 'Failed authentication does not rotate or write authentication session state');
assertTrue(count($attemptState->rows) === 1, 'Failed authentication records a hashed login identifier for throttling');
assertTrue($authService->attemptLogin(' operator ', 'correct horse') === $authUser && $authEvents->events === ['regenerate', 'store'], 'Successful authentication rotates the session ID before storing the principal');
assertTrue($attemptState->rows === [], 'Successful authentication clears the failed-attempt counter');
assertTrue($authService->currentUser() === $authEvents->principal && $authService->isAuthenticated(), 'AuthService reads its authenticated principal through the outbound session port');
$authService->logout();
assertTrue(
    $authService->currentUser() === null
        && !$authService->isAuthenticated()
        && $authEvents->events === ['regenerate', 'store', 'regenerate:delete-old'],
    'AuthService clears its authenticated principal and rotates the session ID while deleting the old session on logout'
);

$sessionAdapterSource = (string) file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Session/PhpAuthenticationSessionAdapter.php');
assertTrue(
    str_contains($sessionAdapterSource, 'public function regenerateId(bool $deleteOldSession = false): bool')
        && str_contains($sessionAdapterSource, 'session_regenerate_id($deleteOldSession)'),
    'PHP session adapter forwards the old-session deletion option to PHP session ID rotation'
);

$throttleEvents = new stdClass();
$throttleEvents->events = [];
$throttleEvents->principal = null;
$throttleSession = new class($throttleEvents) implements AuthenticationSessionPort {
    public function __construct(private stdClass $state) {}
    public function regenerateId(bool $deleteOldSession = false): bool { $this->state->events[] = $deleteOldSession ? 'regenerate:delete-old' : 'regenerate'; return true; }
    public function storePrincipal(array $principal): void { $this->state->events[] = 'store'; $this->state->principal = $principal; }
    public function currentPrincipal(): ?array { return $this->state->principal; }
    public function clearPrincipal(): void { $this->state->principal = null; }
};
$throttleService = new \App\Application\Service\AuthService(
    $authUserRepository,
    $throttleSession,
    $attemptRepository,
    static fn(): int => $attemptState->now
);
for ($failedAttempt = 0; $failedAttempt < 5; $failedAttempt++) {
    assertTrue($throttleService->attemptLogin('operator', 'wrong password') === null, 'Incorrect password remains a generic failed login');
}
assertTrue(
    $throttleService->attemptLogin('operator', 'correct horse') === null && $throttleEvents->events === [],
    'Fifth failure throttles further login attempts without creating a session'
);
$attemptState->now += 61;
assertTrue(
    $throttleService->attemptLogin('operator', 'correct horse') === $authUser && $attemptState->rows === [],
    'Login succeeds after the temporary throttle expires and clears its counter'
);
$unknownLogin = $throttleService->attemptLogin('missing-user', 'wrong password');
assertTrue($unknownLogin === null && count($attemptState->rows) === 1, 'Unknown usernames receive the same failed result and are throttled without storing their name');
$storedAttemptKey = (string) array_key_first($attemptState->rows);
assertTrue($storedAttemptKey === hash('sha256', 'missing-user'), 'Login throttling stores only a one-way username digest');

$sqliteAttemptRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteLoginAttemptRepository(new PDO('sqlite::memory:'));
$sqliteAttemptRepository->save('test-identifier-digest', 3, 1_800_000_000, 0);
assertTrue(
    $sqliteAttemptRepository->find('test-identifier-digest') === [
        'failures' => 3,
        'window_started_at' => 1_800_000_000,
        'blocked_until' => 0,
    ],
    'SQLite login-attempt adapter persists throttle state through its outbound port'
);
$sqliteAttemptRepository->deleteBefore(1_800_000_901);
assertTrue($sqliteAttemptRepository->find('test-identifier-digest') === null, 'SQLite login-attempt adapter prunes expired throttle records');

assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/cart') === '/cart', 'Local redirect accepts an internal path');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/shop?category=books&page=2') === '/shop?category=books&page=2', 'Local redirect preserves an internal path and query');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('/admin/modules?filter=enabled', '/admin/modules') === '/admin/modules?filter=enabled', 'Local redirect preserves a valid module admin return path');
assertTrue(\App\Presentation\Http\LocalRedirect::resolve('https://evil.example', '/admin/modules') === '/admin/modules', 'Local redirect uses the module admin fallback for an external destination');
foreach (['https://evil.example', '//evil.example/path', '///evil.example', 'javascript:alert(1)', '/\\\\evil.example', "/cart\r\nLocation: https://evil.example"] as $unsafeRedirect) {
    assertTrue(\App\Presentation\Http\LocalRedirect::resolve($unsafeRedirect) === '/cart', 'Local redirect rejects an external or malformed destination');
}

// 0. AutoTopic reviews form controls that have no programmatic label association.
$sessionRotationRoot = sys_get_temp_dir() . '/tuzygent-topic-session-rotation-' . bin2hex(random_bytes(6));
mkdir($sessionRotationRoot . '/src/Application/Service', 0775, true);
$sessionRotationFixture = $sessionRotationRoot . '/src/Application/Service/LoginService.php';
$sessionRotationTarget = 'CmsSessionRotation' . substr(sha1('src/Application/Service/LoginService.php'), 0, 12);
file_put_contents($sessionRotationFixture, <<<'PHP'
<?php
declare(strict_types=1);
final class LoginService
{
    public function login(): void
    {
        $this->session->storePrincipal($principal);
        $this->session->regenerateId();
    }
}
PHP);
$sessionRotationGenerator = new CmsTopicGenerator($sessionRotationRoot);
$sessionRotationTopics = array_values(array_filter(
    $sessionRotationGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $sessionRotationTarget
));
assertTrue(
    count($sessionRotationTopics) === 1
        && $sessionRotationTopics[0]['priority'] === 'P0'
        && str_contains($sessionRotationTopics[0]['specification'], 'session fixation'),
    'AutoTopic flags principal storage before session-ID rotation in a login method as a bounded security review'
);
file_put_contents($sessionRotationFixture, <<<'PHP'
<?php
declare(strict_types=1);
final class LoginService
{
    public function login(): void
    {
        // session_regenerate_id() in this comment is not evidence.
        \\session_regenerate_id();
        $this->session->storePrincipal($principal);
    }
}
PHP);
assertTrue(
    !array_filter(
        (new CmsTopicGenerator($sessionRotationRoot))->generate(),
        static fn(array $topic): bool => $topic['target_name'] === $sessionRotationTarget
    ),
    'AutoTopic accepts a session rotation call before principal storage and ignores comment-only references'
);
assertTrue(
    !array_filter(
        $sessionRotationGenerator->generate([strtolower($sessionRotationTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $sessionRotationTarget
    ),
    'AutoTopic suppresses an already discovered session-rotation review target'
);
unlink($sessionRotationFixture);
rmdir($sessionRotationRoot . '/src/Application/Service');
rmdir($sessionRotationRoot . '/src/Application');
rmdir($sessionRotationRoot . '/src');
rmdir($sessionRotationRoot);

$formLabelTestRoot = sys_get_temp_dir() . '/tuzygent-topic-form-labels-' . bin2hex(random_bytes(6));
mkdir($formLabelTestRoot . '/src/Presentation/Views', 0775, true);
$formLabelFixture = $formLabelTestRoot . '/src/Presentation/Views/form.php';
file_put_contents($formLabelFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<form>
    <label for="named">Named</label><input id="named" type="text">
    <label>Wrapped <textarea name="wrapped"></textarea></label>
    <input aria-label="Search" type="search">
    <input type="email" name="email">
    <input type="hidden" name="token">
    <button type="submit">Save</button>
</form>
PHP);
$formLabelGenerator = new CmsTopicGenerator($formLabelTestRoot);
$formLabelTarget = 'CmsFormLabels' . substr(sha1('src/Presentation/Views/form.php'), 0, 12);
$formLabelTopics = array_values(array_filter(
    $formLabelGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $formLabelTarget
));
assertTrue(
    count($formLabelTopics) === 1 && str_contains($formLabelTopics[0]['specification'], '1 control nhập liệu'),
    'AutoTopic finds an unlabeled form field while recognizing explicit labels, wrapping labels, ARIA labels, and non-data controls'
);
$suppressedFormLabelTopics = $formLabelGenerator->generate([strtolower($formLabelTarget) => true]);
assertTrue(
    !array_filter($suppressedFormLabelTopics, static fn(array $topic): bool => $topic['target_name'] === $formLabelTarget),
    'AutoTopic suppresses a previously discovered form-label review target'
);
unlink($formLabelFixture);
rmdir($formLabelTestRoot . '/src/Presentation/Views');
rmdir($formLabelTestRoot . '/src/Presentation');
rmdir($formLabelTestRoot . '/src');
rmdir($formLabelTestRoot);

// AutoTopic reviews the centralized security-header adapter when no CSP is declared.
$cspTopicRoot = sys_get_temp_dir() . '/tuzygent-topic-csp-' . bin2hex(random_bytes(6));
mkdir($cspTopicRoot . '/src/Presentation/Http', 0775, true);
$cspTopicFile = $cspTopicRoot . '/src/Presentation/Http/SecurityHeaders.php';
$cspTopicTarget = 'CmsContentSecurityPolicy' . substr(sha1('src/Presentation/Http/SecurityHeaders.php'), 0, 12);
file_put_contents($cspTopicFile, <<<'PHP'
<?php
declare(strict_types=1);
final class SecurityHeaders
{
    public static function values(): array
    {
        // 'Content-Security-Policy: default-src self' is only a comment.
        $description = 'Content-Security-Policy';
        return ['X-Frame-Options' => 'DENY'];
    }
}
PHP);
$missingCspTopics = (new CmsTopicGenerator($cspTopicRoot))->generate();
assertTrue(
    count($missingCspTopics) === 1
        && $missingCspTopics[0]['target_name'] === $cspTopicTarget
        && $missingCspTopics[0]['priority'] === 'P1'
        && str_contains($missingCspTopics[0]['specification'], 'report-only'),
    'AutoTopic identifies a missing centralized CSP declaration without treating comments or unrelated strings as configuration'
);
$suppressedCspTopics = (new CmsTopicGenerator($cspTopicRoot))->generate([strtolower($cspTopicTarget) => true]);
assertTrue($suppressedCspTopics === [], 'AutoTopic suppresses a previously discovered CSP review target');
foreach ([
    "'Content-Security-Policy' => \"default-src 'self'\"",
    "'Content-Security-Policy-Report-Only' => \"default-src 'self'\"",
] as $cspHeaderDeclaration) {
    file_put_contents($cspTopicFile, "<?php\ndeclare(strict_types=1);\nfinal class SecurityHeaders { public static function values(): array { return [{$cspHeaderDeclaration}]; } }\n");
    assertTrue((new CmsTopicGenerator($cspTopicRoot))->generate() === [], 'AutoTopic accepts an enforced or report-only CSP header declaration');
}
unlink($cspTopicFile);
rmdir($cspTopicRoot . '/src/Presentation/Http');
rmdir($cspTopicRoot . '/src/Presentation');
rmdir($cspTopicRoot . '/src');
rmdir($cspTopicRoot);

// AutoTopic identifies literal, non-empty password values rendered by PHP views.
$passwordPrefillRoot = sys_get_temp_dir() . '/tuzygent-topic-password-prefill-' . bin2hex(random_bytes(6));
mkdir($passwordPrefillRoot . '/src/Presentation/Views', 0775, true);
$passwordPrefillFixture = $passwordPrefillRoot . '/src/Presentation/Views/login.php';
file_put_contents($passwordPrefillFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <input type="password" value="comment-only"> -->
<input type="password" name="password" value="demo-secret">
<input type=password value=legacy-demo>
<input value="other-field" type="text">
<input type="password" value="">
<input type="password" value="<?= htmlspecialchars($savedPassword) ?>">
<?php $literalMarkup = '<input type="password" value="string-only">'; ?>
PHP);
$passwordPrefillGenerator = new CmsTopicGenerator($passwordPrefillRoot);
$passwordPrefillTarget = 'CmsPasswordPrefill' . substr(sha1('src/Presentation/Views/login.php'), 0, 12);
$passwordPrefillTopics = array_values(array_filter(
    $passwordPrefillGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordPrefillTarget
));
assertTrue(
    count($passwordPrefillTopics) === 1
        && str_contains($passwordPrefillTopics[0]['specification'], '2 input')
        && str_contains($passwordPrefillTopics[0]['specification'], 'dòng: 3, 4')
        && !str_contains($passwordPrefillTopics[0]['specification'], 'demo-secret'),
    'AutoTopic detects a non-empty literal password value in rendered template markup without exposing it or flagging comments, other inputs, blank values, dynamic values, or PHP strings'
);
$suppressedPasswordPrefillTopics = $passwordPrefillGenerator->generate([strtolower($passwordPrefillTarget) => true]);
assertTrue(
    !array_filter($suppressedPasswordPrefillTopics, static fn(array $topic): bool => $topic['target_name'] === $passwordPrefillTarget),
    'AutoTopic suppresses a previously discovered password-prefill review target'
);
file_put_contents($passwordPrefillFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($passwordPrefillFixture));
$shiftedPasswordPrefillTopics = array_values(array_filter(
    $passwordPrefillGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordPrefillTarget
));
assertTrue(count($shiftedPasswordPrefillTopics) === 1, 'AutoTopic password-prefill identity remains stable when unrelated lines shift');
unlink($passwordPrefillFixture);
rmdir($passwordPrefillRoot . '/src/Presentation/Views');
rmdir($passwordPrefillRoot . '/src/Presentation');
rmdir($passwordPrefillRoot . '/src');
rmdir($passwordPrefillRoot);

// AutoTopic reviews static password inputs without an autocomplete purpose.
$passwordAutocompleteRoot = sys_get_temp_dir() . '/tuzygent-topic-password-autocomplete-' . bin2hex(random_bytes(6));
mkdir($passwordAutocompleteRoot . '/src/Presentation/Views', 0775, true);
$passwordAutocompleteFixture = $passwordAutocompleteRoot . '/src/Presentation/Views/auth.php';
file_put_contents($passwordAutocompleteFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <input type="password"> -->
<input type="password" name="login_password">
<input name="new_password" type='PASSWORD'>
<input type="password" autocomplete="current-password">
<input type="password" autocomplete="new-password">
<input type="<?= htmlspecialchars($fieldType) ?>">
<?php $literalMarkup = '<input type="password">'; ?>
PHP);
$passwordAutocompleteGenerator = new CmsTopicGenerator($passwordAutocompleteRoot);
$passwordAutocompleteTarget = 'CmsPasswordAutocomplete' . substr(sha1('src/Presentation/Views/auth.php'), 0, 12);
$passwordAutocompleteTopics = array_values(array_filter(
    $passwordAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordAutocompleteTarget
));
assertTrue(
    count($passwordAutocompleteTopics) === 1
        && str_contains($passwordAutocompleteTopics[0]['specification'], '2 input mật khẩu')
        && str_contains($passwordAutocompleteTopics[0]['specification'], 'dòng: 3, 4')
        && str_contains($passwordAutocompleteTopics[0]['specification'], 'current-password')
        && str_contains($passwordAutocompleteTopics[0]['specification'], 'new-password'),
    'AutoTopic identifies static password fields without autocomplete while accepting declared values and ignoring comments, dynamic markup, and PHP strings'
);
$suppressedPasswordAutocompleteTopics = $passwordAutocompleteGenerator->generate([strtolower($passwordAutocompleteTarget) => true]);
assertTrue(
    !array_filter($suppressedPasswordAutocompleteTopics, static fn(array $topic): bool => $topic['target_name'] === $passwordAutocompleteTarget),
    'AutoTopic suppresses a previously discovered password-autocomplete review target'
);
file_put_contents($passwordAutocompleteFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($passwordAutocompleteFixture));
$shiftedPasswordAutocompleteTopics = array_values(array_filter(
    $passwordAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordAutocompleteTarget
));
assertTrue(count($shiftedPasswordAutocompleteTopics) === 1, 'AutoTopic password-autocomplete identity remains stable when unrelated lines shift');
unlink($passwordAutocompleteFixture);
rmdir($passwordAutocompleteRoot . '/src/Presentation/Views');
rmdir($passwordAutocompleteRoot . '/src/Presentation');
rmdir($passwordAutocompleteRoot . '/src');
rmdir($passwordAutocompleteRoot);

// AutoTopic reviews contact fields in forms that lack an autocomplete purpose.
$personalAutocompleteRoot = sys_get_temp_dir() . '/tuzygent-topic-personal-autocomplete-' . bin2hex(random_bytes(6));
mkdir($personalAutocompleteRoot . '/src/Presentation/Views', 0775, true);
$personalAutocompleteFixture = $personalAutocompleteRoot . '/src/Presentation/Views/contact.php';
file_put_contents($personalAutocompleteFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <form><input type="email"></form> -->
<form method="post">
    <input type="email" name="author_email">
    <input name="phone" type='TEL'>
    <input type="email" autocomplete="off">
    <input type="tel" autocomplete="email">
    <input type="email" autocomplete="section-contact shipping email">
    <input type="tel" autocomplete="home tel">
    <input type="email" autocomplete="">
    <input type="<?= htmlspecialchars($fieldType) ?>">
</form>
<input type="email" name="outside_form">
<?php $literalMarkup = '<form><input type="email"></form>'; ?>
PHP);
$personalAutocompleteGenerator = new CmsTopicGenerator($personalAutocompleteRoot);
$personalAutocompleteTarget = 'CmsPersonalAutocomplete' . substr(sha1('src/Presentation/Views/contact.php'), 0, 12);
$personalAutocompleteTopics = array_values(array_filter(
    $personalAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $personalAutocompleteTarget
));
assertTrue(
    count($personalAutocompleteTopics) === 1
        && str_contains($personalAutocompleteTopics[0]['specification'], '5 input email/điện thoại')
        && str_contains($personalAutocompleteTopics[0]['specification'], 'dòng: 4, 5, 6, 7, 10')
        && str_contains($personalAutocompleteTopics[0]['specification'], 'gợi ý rà soát'),
    'AutoTopic finds missing, empty, or mismatched email and telephone autocomplete purposes while accepting valid detail tokens and skipping dynamic or out-of-form markup'
);
$suppressedPersonalAutocompleteTopics = $personalAutocompleteGenerator->generate([strtolower($personalAutocompleteTarget) => true]);
assertTrue(
    !array_filter($suppressedPersonalAutocompleteTopics, static fn(array $topic): bool => $topic['target_name'] === $personalAutocompleteTarget),
    'AutoTopic suppresses a previously discovered personal-data autocomplete review target'
);
file_put_contents($personalAutocompleteFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($personalAutocompleteFixture));
$shiftedPersonalAutocompleteTopics = array_values(array_filter(
    $personalAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $personalAutocompleteTarget
));
assertTrue(count($shiftedPersonalAutocompleteTopics) === 1, 'AutoTopic personal autocomplete identity remains stable when unrelated lines shift');
unlink($personalAutocompleteFixture);
rmdir($personalAutocompleteRoot . '/src/Presentation/Views');
rmdir($personalAutocompleteRoot . '/src/Presentation');
rmdir($personalAutocompleteRoot . '/src');
rmdir($personalAutocompleteRoot);

// AutoTopic reviews name and address text fields with recognizable personal-data identifiers.
$identityAutocompleteRoot = sys_get_temp_dir() . '/tuzygent-topic-identity-autocomplete-' . bin2hex(random_bytes(6));
mkdir($identityAutocompleteRoot . '/src/Presentation/Views', 0775, true);
$identityAutocompleteFixture = $identityAutocompleteRoot . '/src/Presentation/Views/checkout.php';
file_put_contents($identityAutocompleteFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <form><input type="text" name="first_name"></form> -->
<form method="post">
    <input type="text" name="first_name">
    <input type="text" name="street_address" autocomplete="section-billing billing street-address">
    <input type="text" id="postal_code" autocomplete="off">
    <input type="text" name="company_name" autocomplete="organization">
    <input type="text" name="product_name">
    <input type="text" name="<?= $fieldName ?>">
    <input type="<?= $fieldType ?>" name="city">
</form>
<input type="text" name="city">
<?php $literalMarkup = '<form><input type="text" name="family_name"></form>'; ?>
PHP);
$identityAutocompleteGenerator = new CmsTopicGenerator($identityAutocompleteRoot);
$identityAutocompleteTarget = 'CmsIdentityAutocomplete' . substr(sha1('src/Presentation/Views/checkout.php'), 0, 12);
$identityAutocompleteTopics = array_values(array_filter(
    $identityAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $identityAutocompleteTarget
));
assertTrue(
    count($identityAutocompleteTopics) === 1
        && str_contains($identityAutocompleteTopics[0]['specification'], '2 input text')
        && str_contains($identityAutocompleteTopics[0]['specification'], 'dòng: 4, 6')
        && str_contains($identityAutocompleteTopics[0]['specification'], 'gợi ý rà soát'),
    'AutoTopic reviews recognizable identity text fields while accepting matching section tokens and ignoring unrelated, dynamic, commented, or out-of-form markup'
);
assertTrue(
    !array_filter($identityAutocompleteGenerator->generate([strtolower($identityAutocompleteTarget) => true]), static fn(array $topic): bool => $topic['target_name'] === $identityAutocompleteTarget),
    'AutoTopic suppresses an already discovered identity autocomplete review target'
);
file_put_contents($identityAutocompleteFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($identityAutocompleteFixture));
$shiftedIdentityAutocompleteTopics = array_values(array_filter(
    $identityAutocompleteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $identityAutocompleteTarget
));
assertTrue(count($shiftedIdentityAutocompleteTopics) === 1, 'AutoTopic identity-autocomplete target remains stable when unrelated lines shift');
unlink($identityAutocompleteFixture);
rmdir($identityAutocompleteRoot . '/src/Presentation/Views');
rmdir($identityAutocompleteRoot . '/src/Presentation');
rmdir($identityAutocompleteRoot . '/src');
rmdir($identityAutocompleteRoot);

// AutoTopic finds template images that omit the required alt attribute.
$imageAltTestRoot = sys_get_temp_dir() . '/tuzygent-topic-image-alt-' . bin2hex(random_bytes(6));
mkdir($imageAltTestRoot . '/src/Presentation/Views', 0775, true);
$imageAltFixture = $imageAltTestRoot . '/src/Presentation/Views/images.php';
file_put_contents($imageAltFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <img src="comment-only.png"> -->
<img src="product.png">
<img src="decorative.png" alt="">
<img alt="A banner" src="banner.png">
<img src="<?= htmlspecialchars($dynamicImage) ?>">
<?php $literalMarkup = '<img src="not-template-markup.png">'; ?>
PHP);
$imageAltGenerator = new CmsTopicGenerator($imageAltTestRoot);
$imageAltTarget = 'CmsImageAlt' . substr(sha1('src/Presentation/Views/images.php'), 0, 12);
$imageAltTopics = array_values(array_filter(
    $imageAltGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $imageAltTarget
));
assertTrue(
    count($imageAltTopics) === 1
        && str_contains($imageAltTopics[0]['specification'], '2 thẻ img')
        && str_contains($imageAltTopics[0]['specification'], 'alt=""'),
    'AutoTopic finds images without alt in template HTML while accepting empty alt and ignoring comments and PHP strings'
);
file_put_contents($imageAltFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($imageAltFixture));
$shiftedImageAltTopics = array_values(array_filter(
    $imageAltGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $imageAltTarget
));
assertTrue(count($shiftedImageAltTopics) === 1, 'AutoTopic image-alt identity remains stable when unrelated lines shift');
$suppressedImageAltTopics = $imageAltGenerator->generate([strtolower($imageAltTarget) => true]);
assertTrue(
    !array_filter($suppressedImageAltTopics, static fn(array $topic): bool => $topic['target_name'] === $imageAltTarget),
    'AutoTopic suppresses a previously discovered image-alt review target'
);
unlink($imageAltFixture);
rmdir($imageAltTestRoot . '/src/Presentation/Views');
rmdir($imageAltTestRoot . '/src/Presentation');
rmdir($imageAltTestRoot . '/src');
rmdir($imageAltTestRoot);

// AutoTopic reviews template images that do not declare both intrinsic dimensions.
$imageDimensionsRoot = sys_get_temp_dir() . '/tuzygent-topic-image-dimensions-' . bin2hex(random_bytes(6));
mkdir($imageDimensionsRoot . '/src/Presentation/Views', 0775, true);
$imageDimensionsFile = $imageDimensionsRoot . '/src/Presentation/Views/images.php';
file_put_contents($imageDimensionsFile, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <img src="comment-only.png"> -->
<img src="product.png">
<img src="width-only.png" width="640">
<img src="height-only.png" height="480">
<img src="sized.png" width="640" height="480">
<img src="<?= htmlspecialchars($dynamicImage) ?>">
<?php $literalMarkup = '<img src="not-template-markup.png">'; ?>
PHP);
$imageDimensionsGenerator = new CmsTopicGenerator($imageDimensionsRoot);
$imageDimensionsTarget = 'CmsImageDimensions' . substr(sha1('src/Presentation/Views/images.php'), 0, 12);
$imageDimensionsTopics = array_values(array_filter(
    $imageDimensionsGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $imageDimensionsTarget
));
assertTrue(
    count($imageDimensionsTopics) === 1
        && $imageDimensionsTopics[0]['priority'] === 'P2'
        && str_contains($imageDimensionsTopics[0]['specification'], '4 thẻ img')
        && str_contains($imageDimensionsTopics[0]['specification'], 'dòng: 3, 4, 5, 7')
        && str_contains($imageDimensionsTopics[0]['specification'], 'aspect-ratio'),
    'AutoTopic finds literal template image tags missing either dimension while accepting complete dimensions and ignoring comments and PHP strings'
);
file_put_contents($imageDimensionsFile, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($imageDimensionsFile));
$shiftedImageDimensionsTopics = array_values(array_filter(
    $imageDimensionsGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $imageDimensionsTarget
));
assertTrue(count($shiftedImageDimensionsTopics) === 1, 'AutoTopic image-dimension identity remains stable when unrelated lines shift');
$suppressedImageDimensionsTopics = $imageDimensionsGenerator->generate([strtolower($imageDimensionsTarget) => true]);
assertTrue(
    !array_filter($suppressedImageDimensionsTopics, static fn(array $topic): bool => $topic['target_name'] === $imageDimensionsTarget),
    'AutoTopic suppresses a previously discovered image-dimension target'
);
unlink($imageDimensionsFile);
rmdir($imageDimensionsRoot . '/src/Presentation/Views');
rmdir($imageDimensionsRoot . '/src/Presentation');
rmdir($imageDimensionsRoot . '/src');
rmdir($imageDimensionsRoot);

// AutoTopic reviews links that open new browsing contexts without explicit noopener.
$blankLinkRoot = sys_get_temp_dir() . '/tuzygent-topic-blank-links-' . bin2hex(random_bytes(6));
mkdir($blankLinkRoot . '/src/Presentation/Views', 0775, true);
$blankLinkFixture = $blankLinkRoot . '/src/Presentation/Views/links.php';
file_put_contents($blankLinkFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <a href="/comment" target="_blank">Comment</a> -->
<a href="/missing-one" target="_blank">Missing one</a>
<a target='_BLANK' rel='external noreferrer' href='/missing-two'>Missing two</a>
<a href="/protected" target="_blank" rel="external noopener noreferrer">Protected</a>
<a href="/protected-case" target="_blank" rel="NoOpEnEr">Protected case</a>
<a href="/dynamic-target" target="<?= htmlspecialchars($target) ?>">Dynamic target</a>
<a href="/dynamic-markup" target="_blank" rel="<?= htmlspecialchars($rel) ?>">Dynamic rel</a>
<?php $literalMarkup = '<a href="/string" target="_blank">String</a>'; ?>
PHP);
$blankLinkTarget = 'CmsBlankNoopener' . substr(sha1('src/Presentation/Views/links.php'), 0, 12);
$blankLinkGenerator = new CmsTopicGenerator($blankLinkRoot);
$blankLinkTopics = array_values(array_filter(
    $blankLinkGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $blankLinkTarget
));
assertTrue(
    count($blankLinkTopics) === 1
        && $blankLinkTopics[0]['priority'] === 'P1'
        && str_contains($blankLinkTopics[0]['specification'], '2 thẻ <a target="_blank">')
        && str_contains($blankLinkTopics[0]['specification'], 'dòng: 3, 4')
        && str_contains($blankLinkTopics[0]['specification'], 'Trình duyệt hiện đại'),
    'AutoTopic finds literal blank-target links without noopener while accepting rel tokens and ignoring comments and PHP markup'
);
file_put_contents($blankLinkFixture, "<?php declare(strict_types=1); ?>\n\n" . file_get_contents($blankLinkFixture));
$shiftedBlankLinkTopics = array_values(array_filter(
    $blankLinkGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $blankLinkTarget
));
assertTrue(count($shiftedBlankLinkTopics) === 1, 'AutoTopic blank-link identity remains stable when unrelated lines shift');
assertTrue(
    !array_filter(
        $blankLinkGenerator->generate([strtolower($blankLinkTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $blankLinkTarget
    ),
    'AutoTopic suppresses a previously discovered blank-link review target'
);
unlink($blankLinkFixture);
rmdir($blankLinkRoot . '/src/Presentation/Views');
rmdir($blankLinkRoot . '/src/Presentation');
rmdir($blankLinkRoot . '/src');
rmdir($blankLinkRoot);

// AutoTopic reviews static JavaScript window.open calls that can create new browsing contexts.
$javascriptOpenerRoot = sys_get_temp_dir() . '/tuzygent-topic-js-openers-' . bin2hex(random_bytes(6));
mkdir($javascriptOpenerRoot . '/public/assets/js', 0775, true);
$javascriptOpenerFixture = $javascriptOpenerRoot . '/public/assets/js/openers.js';
file_put_contents($javascriptOpenerFixture, <<<'JS'
// window.open('/comment', '_blank');
window.open('/report', '_blank', 'width=600');
window.open('/protected', '_blank', 'noopener,width=600');
window.open('/also-protected', '_blank', 'noreferrer');
window.open('/same-tab', '_self');
window.open('/dynamic-url', dynamicUrl, 'width=600');
window.open(dynamicUrl, '_blank', 'width=600');
window.open('/named', 'existingWindow');
const example = "window.open('/string', '_blank')";
JS);
$javascriptOpenerTarget = 'CmsJsNoopener' . substr(sha1('public/assets/js/openers.js'), 0, 12);
$javascriptOpenerGenerator = new CmsTopicGenerator($javascriptOpenerRoot);
$javascriptOpenerTopics = array_values(array_filter(
    $javascriptOpenerGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $javascriptOpenerTarget
));
assertTrue(
    count($javascriptOpenerTopics) === 1
        && $javascriptOpenerTopics[0]['priority'] === 'P1'
        && str_contains($javascriptOpenerTopics[0]['specification'], '2 lời gọi window.open()')
        && str_contains($javascriptOpenerTopics[0]['specification'], 'dòng: 2, 8')
        && str_contains($javascriptOpenerTopics[0]['specification'], 'không khẳng định lỗ hổng runtime'),
    'AutoTopic finds static JavaScript window.open calls without noopener while accepting safe features and ignoring comments, strings, and dynamic calls'
);
assertTrue(
    !array_filter(
        $javascriptOpenerGenerator->generate([strtolower($javascriptOpenerTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $javascriptOpenerTarget
    ),
    'AutoTopic suppresses a previously discovered JavaScript opener review target'
);
unlink($javascriptOpenerFixture);
rmdir($javascriptOpenerRoot . '/public/assets/js');
rmdir($javascriptOpenerRoot . '/public/assets');
rmdir($javascriptOpenerRoot . '/public');
rmdir($javascriptOpenerRoot);

// AutoTopic reviews JavaScript APIs that parse assigned strings as HTML.
$domHtmlSinkRoot = sys_get_temp_dir() . '/tuzygent-topic-dom-html-sinks-' . bin2hex(random_bytes(6));
mkdir($domHtmlSinkRoot . '/public/assets/js', 0775, true);
$domHtmlSinkFixture = $domHtmlSinkRoot . '/public/assets/js/render.js';
file_put_contents($domHtmlSinkFixture, <<<'JS'
// element.innerHTML = attackerInput; element['outerHTML'] = attackerInput;
const example = "target.outerHTML = payload; target['innerHTML'] = payload; document.write(payload);";
element.innerHTML = message;
element.outerHTML = markup;
element.insertAdjacentHTML('beforeend', fragment);
document.write(page);
element['innerHTML'] = userMarkup;
element["outerHTML"] = replacementMarkup;
element['textContent'] = plainText;
element.textContent = plainText;
JS);
$domHtmlSinkTarget = 'CmsDomHtmlSink' . substr(sha1('public/assets/js/render.js'), 0, 12);
$domHtmlSinkGenerator = new CmsTopicGenerator($domHtmlSinkRoot);
$domHtmlSinkTopics = array_values(array_filter(
    $domHtmlSinkGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $domHtmlSinkTarget
));
assertTrue(
    count($domHtmlSinkTopics) === 1
        && $domHtmlSinkTopics[0]['priority'] === 'P1'
        && str_contains($domHtmlSinkTopics[0]['specification'], '6 vị trí')
        && str_contains($domHtmlSinkTopics[0]['specification'], 'dòng: 3, 4, 5, 6, 7, 8')
        && str_contains($domHtmlSinkTopics[0]['specification'], 'không kết luận rằng có lỗ hổng khai thác được'),
    'AutoTopic finds dot and bracket JavaScript HTML sinks while ignoring comments, strings, and safe textContent assignments'
);
assertTrue(
    (new CmsTopicGenerator($domHtmlSinkRoot))->generate([strtolower($domHtmlSinkTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered JavaScript HTML sink target'
);
unlink($domHtmlSinkFixture);
rmdir($domHtmlSinkRoot . '/public/assets/js');
rmdir($domHtmlSinkRoot . '/public/assets');
rmdir($domHtmlSinkRoot . '/public');
rmdir($domHtmlSinkRoot);

// AutoTopic flags file controls whose surrounding form does not declare a native upload encoding.
$fileFormTestRoot = sys_get_temp_dir() . '/tuzygent-topic-file-forms-' . bin2hex(random_bytes(6));
mkdir($fileFormTestRoot . '/src/Presentation/Views', 0775, true);
$fileFormFixture = $fileFormTestRoot . '/src/Presentation/Views/uploads.php';
file_put_contents($fileFormFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <form><input type="file"></form> -->
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="valid">
</form>
<form method="POST">
    <input type="file" name="missing-encoding">
</form>
<form enctype="multipart/form-data">
    <input type="file" name="missing-method">
</form>
<form method="<?= htmlspecialchars($method) ?>" enctype="multipart/form-data">
    <input type="file" name="dynamic-form-settings">
</form>
<input type="file" form="external-form" name="externally-associated">
<?php $literal = '<form><input type="file"></form>'; ?>
PHP);
$fileFormGenerator = new CmsTopicGenerator($fileFormTestRoot);
$fileFormTarget = 'CmsFileForm' . substr(sha1('src/Presentation/Views/uploads.php'), 0, 12);
$fileFormTopics = array_values(array_filter(
    $fileFormGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $fileFormTarget
));
assertTrue(
    count($fileFormTopics) === 1
        && $fileFormTopics[0]['priority'] === 'P1'
        && str_contains($fileFormTopics[0]['specification'], '2 input type="file"')
        && str_contains($fileFormTopics[0]['specification'], '7, 10')
        && str_contains($fileFormTopics[0]['specification'], 'JavaScript'),
    'AutoTopic finds file controls missing POST or multipart form configuration while ignoring valid, dynamic, external, commented, and PHP-string markup'
);
$suppressedFileFormTopics = $fileFormGenerator->generate([strtolower($fileFormTarget) => true]);
assertTrue(
    !array_filter($suppressedFileFormTopics, static fn(array $topic): bool => $topic['target_name'] === $fileFormTarget),
    'AutoTopic suppresses a previously discovered file-upload form review target'
);
unlink($fileFormFixture);
rmdir($fileFormTestRoot . '/src/Presentation/Views');
rmdir($fileFormTestRoot . '/src/Presentation');
rmdir($fileFormTestRoot . '/src');
rmdir($fileFormTestRoot);

// AutoTopic identifies state-changing forms that do not include the Router's CSRF token.
$csrfFormTestRoot = sys_get_temp_dir() . '/tuzygent-topic-csrf-forms-' . bin2hex(random_bytes(6));
mkdir($csrfFormTestRoot . '/src/Presentation/Views', 0775, true);
$csrfFormFixture = $csrfFormTestRoot . '/src/Presentation/Views/forms.php';
file_put_contents($csrfFormFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<form method="POST" action="/save"><input name="title"></form>
<form method="put" action="/update"><?= \App\Presentation\Http\Csrf::field() ?></form>
<form method="DELETE" action="/remove"><input type="hidden" name="_csrf_token" value="<?= $token ?>"></form>
<form method="get" action="/search"><input name="query"></form>
<form action="/default-get"><input name="query"></form>
PHP);
$csrfFormTarget = 'CmsCsrfForms' . substr(sha1('src/Presentation/Views/forms.php'), 0, 12);
$csrfFormGenerator = new CmsTopicGenerator($csrfFormTestRoot);
$csrfFormTopics = array_values(array_filter(
    $csrfFormGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $csrfFormTarget
));
assertTrue(
    count($csrfFormTopics) === 1
        && $csrfFormTopics[0]['priority'] === 'P0'
        && str_contains($csrfFormTopics[0]['specification'], '1 form có method khác GET'),
    'AutoTopic flags mutating forms without a CSRF token and recognizes helper and direct hidden fields while ignoring GET forms'
);
$suppressedCsrfFormTopics = $csrfFormGenerator->generate([strtolower($csrfFormTarget) => true]);
assertTrue(
    !array_filter($suppressedCsrfFormTopics, static fn(array $topic): bool => $topic['target_name'] === $csrfFormTarget),
    'AutoTopic suppresses a previously discovered missing-CSRF-form target'
);
unlink($csrfFormFixture);
rmdir($csrfFormTestRoot . '/src/Presentation/Views');
rmdir($csrfFormTestRoot . '/src/Presentation');
rmdir($csrfFormTestRoot . '/src');
rmdir($csrfFormTestRoot);

// AutoTopic reviews password fields inside forms whose method resolves to GET.
$passwordGetTestRoot = sys_get_temp_dir() . '/tuzygent-topic-password-get-' . bin2hex(random_bytes(6));
mkdir($passwordGetTestRoot . '/src/Presentation/Views', 0775, true);
$passwordGetFixture = $passwordGetTestRoot . '/src/Presentation/Views/password_forms.php';
file_put_contents($passwordGetFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <form><input type="password"></form> -->
<form method="GET"><input type="password" name="explicit-get"></form>
<form><input name="defaults-to-get" type="password"></form>
<form method="nonsense"><input type="password" name="invalid-defaults-to-get"></form>
<form method="POST"><input type="password" name="post"></form>
<form method="dialog"><input type="password" name="dialog"></form>
<form method="<?= htmlspecialchars($method) ?>"><input type="password" name="dynamic-method"></form>
<form method="GET"><input type="<?= htmlspecialchars($type) ?>" name="dynamic-type"></form>
<input type="password" name="outside-form">
<?php $literal = '<form><input type="password"></form>'; ?>
PHP);
$passwordGetGenerator = new CmsTopicGenerator($passwordGetTestRoot);
$passwordGetTarget = 'CmsPasswordGetForm' . substr(sha1('src/Presentation/Views/password_forms.php'), 0, 12);
$passwordGetTopics = array_values(array_filter(
    $passwordGetGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordGetTarget
));
assertTrue(
    count($passwordGetTopics) === 1
        && $passwordGetTopics[0]['priority'] === 'P1'
        && str_contains($passwordGetTopics[0]['specification'], '3 input type="password"')
        && str_contains($passwordGetTopics[0]['specification'], 'dòng: 3, 4, 5')
        && str_contains($passwordGetTopics[0]['specification'], 'formmethod'),
    'AutoTopic identifies password forms using explicit, implicit, or invalid-default GET while ignoring POST, dialog, dynamic markup, comments, and PHP strings'
);
$shiftedPasswordGetTopics = array_values(array_filter(
    $passwordGetGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $passwordGetTarget
));
assertTrue(count($shiftedPasswordGetTopics) === 1, 'AutoTopic password-GET identity remains stable when source lines shift');
assertTrue(
    !array_filter(
        $passwordGetGenerator->generate([strtolower($passwordGetTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $passwordGetTarget
    ),
    'AutoTopic suppresses a previously discovered password-GET form target'
);
unlink($passwordGetFixture);
rmdir($passwordGetTestRoot . '/src/Presentation/Views');
rmdir($passwordGetTestRoot . '/src/Presentation');
rmdir($passwordGetTestRoot . '/src');
rmdir($passwordGetTestRoot);

// 0. Verify AutoTopic identities survive unrelated line-number shifts.
$strictTypesTestRoot = sys_get_temp_dir() . '/tuzygent-topics-strict-' . bin2hex(random_bytes(6));
mkdir($strictTypesTestRoot . '/src', 0775, true);
mkdir($strictTypesTestRoot . '/tests', 0775, true);
file_put_contents($strictTypesTestRoot . '/src/MissingTypes.php', "<?php\n// declare(strict_types=1); in a comment is not a directive.\n\$example = 'declare(strict_types=1);';\n");
file_put_contents($strictTypesTestRoot . '/src/StrictTypes.php', "<?php\ndeclare(strict_types=1);\n\$value = 1;\n");
file_put_contents($strictTypesTestRoot . '/src/LateStrictTypes.php', "<?php\n\$value = 1;\ndeclare(strict_types=1);\n");
file_put_contents($strictTypesTestRoot . '/src/ScopedStrictTypes.php', "<?php\nif (true) { declare(strict_types=1); }\n");
file_put_contents($strictTypesTestRoot . '/tests/fixture_test.php', "<?php\ndeclare(strict_types=1);\n");
$strictTypeTopics = (new CmsTopicGenerator($strictTypesTestRoot))->generate();
$strictTypeTopic = array_values(array_filter($strictTypeTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsStrictTypes' . substr(sha1('src/MissingTypes.php'), 0, 12)));
assertTrue(count($strictTypeTopic) === 1 && $strictTypeTopic[0]['priority'] === 'P0', 'AutoTopic finds a PHP file without strict types while ignoring compliant files and directive-like text');
$lateStrictTypeTarget = 'CmsStrictTypes' . substr(sha1('src/LateStrictTypes.php'), 0, 12);
$scopedStrictTypeTarget = 'CmsStrictTypes' . substr(sha1('src/ScopedStrictTypes.php'), 0, 12);
assertTrue(
    count(array_filter($strictTypeTopics, static fn(array $topic): bool => in_array($topic['target_name'], [$lateStrictTypeTarget, $scopedStrictTypeTarget], true))) === 2,
    'AutoTopic requires strict_types to be the first file-level statement'
);
$suppressedStrictTypeTopics = (new CmsTopicGenerator($strictTypesTestRoot))->generate([strtolower($strictTypeTopic[0]['target_name']) => true]);
assertTrue(!array_filter($suppressedStrictTypeTopics, static fn(array $topic): bool => $topic['target_name'] === $strictTypeTopic[0]['target_name']), 'AutoTopic suppresses a previously discovered strict-types target');
foreach (glob($strictTypesTestRoot . '/src/*.php') ?: [] as $strictFile) {
    unlink($strictFile);
}
unlink($strictTypesTestRoot . '/tests/fixture_test.php');
rmdir($strictTypesTestRoot . '/src');
rmdir($strictTypesTestRoot . '/tests');
rmdir($strictTypesTestRoot);

// AutoTopic compares literal root-relative view links with declared router paths.
$internalRouteTestRoot = sys_get_temp_dir() . '/tuzygent-topic-internal-routes-' . bin2hex(random_bytes(6));
mkdir($internalRouteTestRoot . '/public', 0775, true);
mkdir($internalRouteTestRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($internalRouteTestRoot . '/public/index.php', <<<'PHP'
<?php
$router->get('/', [$home, 'index']);
$router->get('/known', [$pages, 'known']);
$router->post('/item/{id}', [$items, 'save']);
PHP);
$internalRouteFixture = $internalRouteTestRoot . '/src/Presentation/Views/links.php';
file_put_contents($internalRouteFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<a href="/known?from=menu">Known route</a>
<a href="/item/42#details">Parameterized route</a>
<a href="/missing">Missing route</a>
<a href="https://example.test/missing">External URL</a>
<a href="//cdn.example.test/missing">Scheme-relative URL</a>
<a href="<?= $dynamicPath ?>">Dynamic URL</a>
<!-- <a href="/comment-only">Comment</a> -->
<?php $literal = '<a href="/php-string">not markup</a>'; ?>
PHP);
$internalRouteGenerator = new CmsTopicGenerator($internalRouteTestRoot);
$internalRouteTarget = 'CmsMissingRoute' . substr(sha1('src/Presentation/Views/links.php:/missing'), 0, 12);
$internalRouteTopics = array_values(array_filter(
    $internalRouteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $internalRouteTarget
));
assertTrue(
    count($internalRouteTopics) === 1
        && $internalRouteTopics[0]['priority'] === 'P2'
        && str_contains($internalRouteTopics[0]['title'], '/missing'),
    'AutoTopic reports one literal root-relative link that does not match a declared route while accepting query strings and route parameters'
);
file_put_contents($internalRouteFixture, "\n\n" . (string) file_get_contents($internalRouteFixture));
$shiftedInternalRouteTopics = array_values(array_filter(
    $internalRouteGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $internalRouteTarget
));
assertTrue(
    count($shiftedInternalRouteTopics) === 1
        && $shiftedInternalRouteTopics[0]['target_name'] === $internalRouteTopics[0]['target_name'],
    'AutoTopic internal-route review identity remains stable when unrelated lines shift'
);
assertTrue(
    !array_filter(
        $internalRouteGenerator->generate([strtolower($internalRouteTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $internalRouteTarget
    ),
    'AutoTopic suppresses a previously discovered internal-route review target'
);

// AutoTopic surfaces real PHP token references that violate the Domain purity gate.
$domainPurityRoot = sys_get_temp_dir() . '/tuzygent-topic-domain-purity-' . bin2hex(random_bytes(6));
mkdir($domainPurityRoot . '/src/Domain/Entity', 0775, true);
$domainPurityFile = $domainPurityRoot . '/src/Domain/Entity/LeakyEntity.php';
file_put_contents($domainPurityFile, <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Domain\Entity;

use App\Infrastructure\Persistence\Sqlite\DatabaseManager;
use PDO;

// PDO, App\Presentation\Controller and $_SESSION in comments are not code references.
final class LeakyEntity
{
    private string $example = 'App\\Infrastructure\\Foo PDO $_POST';

    public function __construct(PDO $pdo, DatabaseManager $databaseManager)
    {
        $this->submitted = $_POST['value'] ?? '';
    }
}
PHP
);
$domainPurityTopics = (new CmsTopicGenerator($domainPurityRoot))->generate();
$domainPurityTarget = 'CmsDomainPurity' . substr(sha1('src/Domain/Entity/LeakyEntity.php'), 0, 12);
assertTrue(
    count($domainPurityTopics) === 1
        && $domainPurityTopics[0]['target_name'] === $domainPurityTarget
        && $domainPurityTopics[0]['priority'] === 'P0'
        && str_contains($domainPurityTopics[0]['specification'], 'App\\Infrastructure, PDO, $_POST'),
    'AutoTopic identifies PHP token references forbidden by the Domain purity gate'
);
assertTrue(
    (new CmsTopicGenerator($domainPurityRoot))->generate([strtolower($domainPurityTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered Domain purity target'
);
unlink($domainPurityFile);
rmdir($domainPurityRoot . '/src/Domain/Entity');
rmdir($domainPurityRoot . '/src/Domain');
rmdir($domainPurityRoot . '/src');
rmdir($domainPurityRoot);

// AutoTopic surfaces database APIs used in application code without scanning literals.
$databaseBoundaryRoot = sys_get_temp_dir() . '/tuzygent-topic-database-boundary-' . bin2hex(random_bytes(6));
mkdir($databaseBoundaryRoot . '/src/Application/Service', 0775, true);
mkdir($databaseBoundaryRoot . '/src/Presentation', 0775, true);
$directDatabaseFile = $databaseBoundaryRoot . '/src/Application/Service/DirectDatabase.php';
file_put_contents($directDatabaseFile, <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Application\Service;

use PDO;

final class DirectDatabase
{
    public function __construct(private PDO $connection)
    {
    }

    public function load(): mixed
    {
        return $this->connection->query('SELECT 1');
    }
}
PHP
);
file_put_contents($databaseBoundaryRoot . '/src/Presentation/NoDatabase.php', <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Presentation;

// PDO and ->query() in a comment are not executable database access.
final class NoDatabase
{
    private string $example = 'PDO ->query()';
}
PHP
);
$directDatabaseTarget = 'CmsDirectDatabase' . substr(sha1('src/Application/Service/DirectDatabase.php'), 0, 12);
$databaseBoundaryTopics = (new CmsTopicGenerator($databaseBoundaryRoot))->generate();
$directDatabaseTopics = array_values(array_filter(
    $databaseBoundaryTopics,
    static fn(array $topic): bool => $topic['target_name'] === $directDatabaseTarget
));
assertTrue(
    count($directDatabaseTopics) === 1
        && $directDatabaseTopics[0]['priority'] === 'P0'
        && str_contains($directDatabaseTopics[0]['specification'], 'PDO, ->query()'),
    'AutoTopic finds PDO and SQL query API tokens in application code while ignoring comments and strings'
);
assertTrue(
    (new CmsTopicGenerator($databaseBoundaryRoot))->generate([strtolower($directDatabaseTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered direct database access target'
);
unlink($directDatabaseFile);
unlink($databaseBoundaryRoot . '/src/Presentation/NoDatabase.php');
rmdir($databaseBoundaryRoot . '/src/Application/Service');
rmdir($databaseBoundaryRoot . '/src/Application');
rmdir($databaseBoundaryRoot . '/src/Presentation');
rmdir($databaseBoundaryRoot . '/src');
rmdir($databaseBoundaryRoot);

// AutoTopic requests a review when rich HTML fields are rendered without contextual escaping.
$rawHtmlRoot = sys_get_temp_dir() . '/tuzygent-topics-raw-html-' . bin2hex(random_bytes(6));
mkdir($rawHtmlRoot . '/src/Presentation/Views/frontend', 0775, true);
$rawHtmlView = $rawHtmlRoot . '/src/Presentation/Views/frontend/show.php';
file_put_contents($rawHtmlView, <<<'PHP'
<?php
declare(strict_types=1);
// $example->content is only a comment.
?>
<?= $post->content ?>
<?= nl2br(htmlspecialchars($post->description)) ?>
<?= render(htmlspecialchars($post->title), $product->description) ?>
<?= '$example->content' ?>
PHP
);
$rawHtmlTarget = 'CmsRawHtml' . substr(sha1('src/Presentation/Views/frontend/show.php:content,description'), 0, 12);
$rawHtmlTopics = (new CmsTopicGenerator($rawHtmlRoot))->generate();
$rawHtmlTopic = array_values(array_filter($rawHtmlTopics, static fn(array $topic): bool => $topic['target_name'] === $rawHtmlTarget));
assertTrue(
    count($rawHtmlTopic) === 1
        && $rawHtmlTopic[0]['priority'] === 'P1'
        && str_contains($rawHtmlTopic[0]['specification'], 'content')
        && str_contains($rawHtmlTopic[0]['specification'], 'description'),
    'AutoTopic flags raw rich-HTML properties while ignoring escaped output, comments, and string literals'
);
assertTrue(
    (new CmsTopicGenerator($rawHtmlRoot))->generate([strtolower($rawHtmlTarget) => true]) === [],
    'AutoTopic suppresses an already discovered raw rich-HTML review target'
);
unlink($rawHtmlView);
rmdir($rawHtmlRoot . '/src/Presentation/Views/frontend');
rmdir($rawHtmlRoot . '/src/Presentation/Views');
rmdir($rawHtmlRoot . '/src/Presentation');
rmdir($rawHtmlRoot . '/src');
rmdir($rawHtmlRoot);

// AutoTopic flags suspicious admin GET handler names as review candidates, not proven mutations.
$unsafeGetTopicRoot = sys_get_temp_dir() . '/tuzygent-topic-admin-get-' . bin2hex(random_bytes(6));
mkdir($unsafeGetTopicRoot . '/public', 0775, true);
file_put_contents($unsafeGetTopicRoot . '/public/index.php', <<<'PHP'
<?php
declare(strict_types=1);
$router->get('/admin/logout', [$authCtrl, 'logout']);
$router->get('/admin/dashboard', [$dashboardCtrl, 'index']);
$router->get('/admin/posts/create', [$postCtrl, 'create']);
$router->get('/admin/products/create', [$productCtrl, 'create']);
$router->post('/admin/users/delete/{id}', [$settingsCtrl, 'deleteUser']);
PHP
);
$unsafeGetTarget = 'CmsUnsafeGet' . substr(sha1('/admin/logout:logout'), 0, 12);
$unsafeGetTopics = (new CmsTopicGenerator($unsafeGetTopicRoot))->generate();
assertTrue(
    count($unsafeGetTopics) === 1
        && $unsafeGetTopics[0]['target_name'] === $unsafeGetTarget
        && $unsafeGetTopics[0]['priority'] === 'P0',
    'AutoTopic flags a stateful-looking admin GET handler while ignoring read-only and create-form GET routes plus mutation POST routes'
);
assertTrue(
    (new CmsTopicGenerator($unsafeGetTopicRoot))->generate([strtolower($unsafeGetTarget) => true]) === [],
    'AutoTopic suppresses an already discovered suspicious admin GET target'
);
unlink($unsafeGetTopicRoot . '/public/index.php');
rmdir($unsafeGetTopicRoot . '/public');
rmdir($unsafeGetTopicRoot);

// AutoTopic reviews potentially stateful GET routes across the public and admin surfaces.
$publicUnsafeGetRoot = sys_get_temp_dir() . '/tuzygent-topic-public-get-' . bin2hex(random_bytes(6));
mkdir($publicUnsafeGetRoot . '/public', 0775, true);
file_put_contents($publicUnsafeGetRoot . '/public/index.php', <<<'PHP'
<?php
declare(strict_types=1);
$router->get('/account/logout', [$authCtrl, 'logout']);
$router->get('/catalog/{slug}', [$shopCtrl, 'show']);
$router->get('/account/create', [$accountCtrl, 'create']);
$router->post('/account/update', [$accountCtrl, 'update']);
PHP
);
$publicUnsafeGetTarget = 'CmsUnsafeGet' . substr(sha1('/account/logout:logout'), 0, 12);
$publicUnsafeGetTopics = (new CmsTopicGenerator($publicUnsafeGetRoot))->generate();
assertTrue(
    count($publicUnsafeGetTopics) === 1
        && $publicUnsafeGetTopics[0]['target_name'] === $publicUnsafeGetTarget
        && str_contains($publicUnsafeGetTopics[0]['specification'], '/account/logout'),
    'AutoTopic flags a stateful-looking public GET handler while ignoring read-only and create-form GET routes plus mutation POST routes'
);
assertTrue(
    (new CmsTopicGenerator($publicUnsafeGetRoot))->generate([strtolower($publicUnsafeGetTarget) => true]) === [],
    'AutoTopic suppresses an already discovered suspicious public GET target'
);
unlink($publicUnsafeGetRoot . '/public/index.php');
rmdir($publicUnsafeGetRoot . '/public');
rmdir($publicUnsafeGetRoot);

// AutoTopic identifies weak randomness specifically in order-code assignments.
$weakOrderCodeRoot = sys_get_temp_dir() . '/tuzygent-topic-order-code-' . bin2hex(random_bytes(6));
mkdir($weakOrderCodeRoot . '/src/Application/Service', 0775, true);
$weakOrderCodeFile = $weakOrderCodeRoot . '/src/Application/Service/OrderService.php';
file_put_contents($weakOrderCodeFile, <<<'PHP'
<?php
declare(strict_types=1);
final class OrderService
{
    public function checkout(): void
    {
        $orderCode = 'ORD-' . strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 6));
        $traceCode = md5((string) mt_rand());
    }
}
PHP
);
file_put_contents($weakOrderCodeRoot . '/src/Application/Service/SafeOrderService.php', <<<'PHP'
<?php
declare(strict_types=1);
final class SafeOrderService
{
    public function checkout(): void
    {
        $orderCode = 'ORD-' . strtoupper(bin2hex(random_bytes(8)));
    }
}
PHP
);
$weakOrderCodeTarget = 'CmsWeakOrderCode' . substr(sha1('src/Application/Service/OrderService.php'), 0, 12);
$weakOrderCodeTopics = (new CmsTopicGenerator($weakOrderCodeRoot))->generate();
$weakOrderCodeMatches = array_values(array_filter(
    $weakOrderCodeTopics,
    static fn(array $topic): bool => $topic['target_name'] === $weakOrderCodeTarget
));
assertTrue(
    count($weakOrderCodeMatches) === 1
        && $weakOrderCodeMatches[0]['priority'] === 'P1'
        && str_contains($weakOrderCodeMatches[0]['specification'], 'uniqid, mt_rand')
        && str_contains($weakOrderCodeMatches[0]['specification'], 'dòng: 7')
        && str_contains($weakOrderCodeMatches[0]['specification'], 'không kết luận đây là credential'),
    'AutoTopic flags weak randomness in order-code assignments with a stable target and actionable, qualified evidence'
);
$suppressedWeakOrderCodeTopics = (new CmsTopicGenerator($weakOrderCodeRoot))->generate([strtolower($weakOrderCodeTarget) => true]);
assertTrue(
    count(array_filter($suppressedWeakOrderCodeTopics, static fn(array $topic): bool => $topic['target_name'] === $weakOrderCodeTarget)) === 0,
    'AutoTopic suppresses an already discovered weak order-code target'
);
unlink($weakOrderCodeFile);
unlink($weakOrderCodeRoot . '/src/Application/Service/SafeOrderService.php');
rmdir($weakOrderCodeRoot . '/src/Application/Service');
rmdir($weakOrderCodeRoot . '/src/Application');
rmdir($weakOrderCodeRoot . '/src');
rmdir($weakOrderCodeRoot);

// AutoTopic audits weak PRNG calls assigned to security-sensitive values.
$weakSecurityRandomRoot = sys_get_temp_dir() . '/tuzygent-topic-weak-security-random-' . bin2hex(random_bytes(6));
mkdir($weakSecurityRandomRoot . '/src/Infrastructure', 0775, true);
$weakSecurityRandomFile = $weakSecurityRandomRoot . '/src/Infrastructure/ResetTokenFactory.php';
file_put_contents($weakSecurityRandomFile, <<<'PHP'
<?php
declare(strict_types=1);
final class ResetTokenFactory
{
    public function create(): void
    {
        // $csrfToken = md5((string) rand());
        $message = '$sessionToken = uniqid();';
        $resetToken = 'reset-' . strtoupper(
            md5((string) mt_rand())
        );
        $csrfToken = bin2hex(random_bytes(32));
        $traceId = uniqid();
        $this->rand();
    }
}
PHP
);
$weakSecurityRandomTarget = 'CmsWeakSecurityRandom' . substr(sha1('src/Infrastructure/ResetTokenFactory.php'), 0, 12);
$weakSecurityRandomTopics = (new CmsTopicGenerator($weakSecurityRandomRoot))->generate();
$weakSecurityRandomMatches = array_values(array_filter(
    $weakSecurityRandomTopics,
    static fn(array $topic): bool => $topic['target_name'] === $weakSecurityRandomTarget
));
assertTrue(
    count($weakSecurityRandomMatches) === 1
        && $weakSecurityRandomMatches[0]['priority'] === 'P1'
        && str_contains($weakSecurityRandomMatches[0]['specification'], 'mt_rand')
        && str_contains($weakSecurityRandomMatches[0]['specification'], 'dòng: 10')
        && str_contains($weakSecurityRandomMatches[0]['specification'], 'heuristic'),
    'AutoTopic flags weak PRNG calls in multiline assignments to security-named variables with qualified evidence'
);
assertTrue(
    (new CmsTopicGenerator($weakSecurityRandomRoot))->generate([strtolower($weakSecurityRandomTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered weak security-randomness target'
);
unlink($weakSecurityRandomFile);
rmdir($weakSecurityRandomRoot . '/src/Infrastructure');
rmdir($weakSecurityRandomRoot . '/src');
rmdir($weakSecurityRandomRoot);

// AutoTopic audits fast general-purpose hashes assigned to password-named variables.
$weakPasswordHashRoot = sys_get_temp_dir() . '/tuzygent-topic-weak-password-hash-' . bin2hex(random_bytes(6));
mkdir($weakPasswordHashRoot . '/src/Infrastructure', 0775, true);
$weakPasswordHashFile = $weakPasswordHashRoot . '/src/Infrastructure/PasswordMigration.php';
file_put_contents($weakPasswordHashFile, <<<'PHP'
<?php
declare(strict_types=1);
final class PasswordMigration
{
    public function hash(string $password, string $payload): void
    {
        // $passwordHash = md5($password);
        $example = '$passwordDigest = sha1($password);';
        $passwordHash = sha1($password);
        $user_password_digest = hash('SHA256', $password);
        $pwd = \md5($password);
        $namespacedPasswordHash = PasswordTools\md5($password);
        $checksum = md5($payload);
        $modernPasswordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->sha1($password);
    }
}
PHP
);
$weakPasswordHashTarget = 'CmsWeakPasswordHash' . substr(sha1('src/Infrastructure/PasswordMigration.php'), 0, 12);
$weakPasswordHashTopics = (new CmsTopicGenerator($weakPasswordHashRoot))->generate();
$weakPasswordHashMatches = array_values(array_filter(
    $weakPasswordHashTopics,
    static fn(array $topic): bool => $topic['target_name'] === $weakPasswordHashTarget
));
assertTrue(
    count($weakPasswordHashMatches) === 1
        && $weakPasswordHashMatches[0]['priority'] === 'P1'
        && str_contains($weakPasswordHashMatches[0]['specification'], 'sha1, hash(sha256), md5')
        && str_contains($weakPasswordHashMatches[0]['specification'], 'dòng: 9, 10, 11')
        && str_contains($weakPasswordHashMatches[0]['specification'], 'password_hash()/password_verify()')
        && str_contains($weakPasswordHashMatches[0]['specification'], 'không chứng minh dữ liệu là mật khẩu người dùng'),
    'AutoTopic flags weak hashes assigned to password-named variables while ignoring comments, strings, methods, and unrelated data'
);
assertTrue(
    (new CmsTopicGenerator($weakPasswordHashRoot))->generate([strtolower($weakPasswordHashTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered weak-password-hash target'
);
unlink($weakPasswordHashFile);
rmdir($weakPasswordHashRoot . '/src/Infrastructure');
rmdir($weakPasswordHashRoot . '/src');
rmdir($weakPasswordHashRoot);

// AutoTopic discovers standalone comment markers in frontend JS and CSS assets.
$assetTopicRoot = sys_get_temp_dir() . '/tuzygent-topic-assets-' . bin2hex(random_bytes(6));
mkdir($assetTopicRoot . '/public/assets/js', 0775, true);
mkdir($assetTopicRoot . '/public/assets/css', 0775, true);
file_put_contents($assetTopicRoot . '/public/assets/js/app.js', <<<'JS'
const help = "// TODO: This is string content, not work.";
const example = `
// FIXME: This is template content, not work.
`;
const dynamic = `literal TODO: still template text ${
    // TODO: Restore keyboard navigation inside interpolation.
    `${(() => {
        /* FIXME: Preserve nested template interpolation. */
        return "ready";
    })()}`
}`;
// TODO: Restore keyboard navigation.
JS
);
file_put_contents($assetTopicRoot . '/public/assets/css/app.css', <<<'CSS'
.sample::before { content: "TODO: This is CSS string content."; }
/*
 * FIXME: Restore focus indicator.
 */
.button { color: red; } /* TODO: Restore button contrast. */ /* FIXME: Preserve compact layout. */
CSS
);
$assetTopics = (new CmsTopicGenerator($assetTopicRoot))->generate();
$assetTodoTarget = 'CmsTodo' . substr(sha1('public/assets/js/app.js:TODO:restore keyboard navigation.'), 0, 12);
$assetFixmeTarget = 'CmsTodo' . substr(sha1('public/assets/css/app.css:FIXME:restore focus indicator.'), 0, 12);
$interpolationTodoTarget = 'CmsTodo' . substr(sha1('public/assets/js/app.js:TODO:restore keyboard navigation inside interpolation.'), 0, 12);
$nestedInterpolationFixmeTarget = 'CmsTodo' . substr(sha1('public/assets/js/app.js:FIXME:preserve nested template interpolation.'), 0, 12);
$inlineCssTodoTarget = 'CmsTodo' . substr(sha1('public/assets/css/app.css:TODO:restore button contrast.'), 0, 12);
$inlineCssFixmeTarget = 'CmsTodo' . substr(sha1('public/assets/css/app.css:FIXME:preserve compact layout.'), 0, 12);
assertTrue(
    count($assetTopics) === 6
        && count(array_filter($assetTopics, static fn(array $topic): bool => in_array($topic['target_name'], [
            $assetFixmeTarget,
            $nestedInterpolationFixmeTarget,
            $assetTodoTarget,
            $interpolationTodoTarget,
            $inlineCssTodoTarget,
            $inlineCssFixmeTarget,
        ], true))) === 6,
    'AutoTopic finds standalone JS/CSS and inline CSS markers while ignoring literal text'
);
assertTrue(
    count(array_filter($assetTopics, static fn(array $topic): bool => $topic['target_name'] === $inlineCssTodoTarget && str_contains($topic['title'], ':5'))) === 1,
    'AutoTopic preserves the source line for inline CSS comment markers'
);
file_put_contents($assetTopicRoot . '/public/assets/js/app.js', "// TODO: Restore keyboard navigation.\n" . file_get_contents($assetTopicRoot . '/public/assets/js/app.js'));
$shiftedAssetTopics = (new CmsTopicGenerator($assetTopicRoot))->generate();
assertTrue(
    count(array_filter($shiftedAssetTopics, static fn(array $topic): bool => $topic['target_name'] === $assetTodoTarget)) === 1,
    'AutoTopic asset marker identity remains stable when unrelated lines shift'
);
foreach (glob($assetTopicRoot . '/public/assets/js/*.js') ?: [] as $assetFile) {
    unlink($assetFile);
}
foreach (glob($assetTopicRoot . '/public/assets/css/*.css') ?: [] as $assetFile) {
    unlink($assetFile);
}
rmdir($assetTopicRoot . '/public/assets/js');
rmdir($assetTopicRoot . '/public/assets/css');
rmdir($assetTopicRoot . '/public/assets');
rmdir($assetTopicRoot . '/public');
rmdir($assetTopicRoot);

// AutoTopic discovers markers in HTML comments inside PHP templates only.
$htmlCommentRoot = sys_get_temp_dir() . '/tuzygent-topic-html-comments-' . bin2hex(random_bytes(6));
mkdir($htmlCommentRoot . '/src/Presentation/Views', 0775, true);
$htmlCommentPath = $htmlCommentRoot . '/src/Presentation/Views/fixture.php';
file_put_contents($htmlCommentPath, <<<'PHP'
<?php
declare(strict_types=1);
$example = '<!-- FIXME: This is PHP string content, not work. -->';
?>
<!--
 TODO: Restore the accessible empty state.
-->
<main>TODO: This is markup text, not work.</main>
<!-- This closed comment has no actionable marker. -->
<!-- FIXME: This comment is deliberately unterminated.
PHP
);
$htmlCommentTopics = (new CmsTopicGenerator($htmlCommentRoot))->generate();
$htmlTodoTarget = 'CmsTodo' . substr(sha1('src/Presentation/Views/fixture.php:TODO:restore the accessible empty state.'), 0, 12);
assertTrue(
    count($htmlCommentTopics) === 1
        && $htmlCommentTopics[0]['target_name'] === $htmlTodoTarget
        && str_contains($htmlCommentTopics[0]['title'], ':6'),
    'AutoTopic finds markers in closed HTML comments within PHP templates and ignores PHP strings, markup text, and unclosed comments'
);
unlink($htmlCommentPath);
rmdir($htmlCommentRoot . '/src/Presentation/Views');
rmdir($htmlCommentRoot . '/src/Presentation');
rmdir($htmlCommentRoot . '/src');
rmdir($htmlCommentRoot);

// AutoTopic finds repeated literal HTML IDs inside one template while ignoring dynamic IDs and comments.
$interactiveNameRoot = sys_get_temp_dir() . '/tuzygent-topic-interactive-name-' . bin2hex(random_bytes(6));
mkdir($interactiveNameRoot . '/src/Presentation/Views', 0775, true);
$interactiveNameSource = <<<'PHP'
<?php declare(strict_types=1); ?>
<a href="/empty"></a>
<button type="button"><span></span></button>
<a href="/aria" aria-label="Open page"></a>
<button type="button" title="Save"></button>
<a href="/text">Read more</a>
<a href="/image"><img src="/icon.svg" alt="Account" width="24" height="24"></a>
<a href="/dynamic"><?= htmlspecialchars($linkName) ?></a>
<button type="button"><?= htmlspecialchars($buttonName) ?></button>
<a>Not a link</a>
PHP;
file_put_contents($interactiveNameRoot . '/src/Presentation/Views/fixture.php', $interactiveNameSource);
$interactiveNameTopics = (new CmsTopicGenerator($interactiveNameRoot))->generate();
$interactiveNameTarget = 'CmsInteractiveName' . substr(sha1('src/Presentation/Views/fixture.php'), 0, 12);
$interactiveNameTopic = array_values(array_filter(
    $interactiveNameTopics,
    static fn(array $topic): bool => $topic['target_name'] === $interactiveNameTarget
));
assertTrue(
    count($interactiveNameTopic) === 1
        && str_contains($interactiveNameTopic[0]['specification'], 'dòng: 2, 3')
        && !str_contains($interactiveNameTopic[0]['specification'], 'dòng: 2, 3, 4'),
    'AutoTopic reports empty static links and buttons while accepting text, ARIA/title, image alt, and skipping PHP-dynamic names'
);
assertTrue(
    !array_filter(
        (new CmsTopicGenerator($interactiveNameRoot))->generate([strtolower($interactiveNameTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $interactiveNameTarget
    ),
    'AutoTopic suppresses an already discovered unnamed interactive-element target'
);
unlink($interactiveNameRoot . '/src/Presentation/Views/fixture.php');
rmdir($interactiveNameRoot . '/src/Presentation/Views');
rmdir($interactiveNameRoot . '/src/Presentation');
rmdir($interactiveNameRoot . '/src');
rmdir($interactiveNameRoot);

// AutoTopic flags buttons in literal forms without an explicit type, but ignores typed and out-of-form buttons.
$buttonTypeRoot = sys_get_temp_dir() . '/tuzygent-topic-button-type-' . bin2hex(random_bytes(6));
mkdir($buttonTypeRoot . '/src/Presentation/Views', 0775, true);
$buttonTypePath = $buttonTypeRoot . '/src/Presentation/Views/fixture.php';
file_put_contents($buttonTypePath, <<<'PHP'
<?php declare(strict_types=1); ?>
<button>Outside form</button>
<form method="post">
    <button>Ambiguous action</button>
    <button type="submit">Save</button>
    <button TYPE="button">Cancel</button>
</form>
PHP
);
$buttonTypeTarget = 'CmsButtonType' . substr(sha1('src/Presentation/Views/fixture.php'), 0, 12);
$buttonTypeTopics = (new CmsTopicGenerator($buttonTypeRoot))->generate();
$buttonTypeMatches = array_values(array_filter(
    $buttonTypeTopics,
    static fn(array $topic): bool => $topic['target_name'] === $buttonTypeTarget
));
assertTrue(
    count($buttonTypeMatches) === 1
        && str_contains($buttonTypeMatches[0]['specification'], '1 thẻ <button>')
        && str_contains($buttonTypeMatches[0]['specification'], 'dòng: 4')
        && str_contains($buttonTypeMatches[0]['specification'], 'mặc định có hành vi submit'),
    'AutoTopic reports only untyped buttons in forms and explains the HTML default behavior'
);
assertTrue(
    count(array_filter(
        (new CmsTopicGenerator($buttonTypeRoot))->generate([strtolower($buttonTypeTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $buttonTypeTarget
    )) === 0,
    'AutoTopic suppresses an already discovered missing button type target'
);
unlink($buttonTypePath);
rmdir($buttonTypeRoot . '/src/Presentation/Views');
rmdir($buttonTypeRoot . '/src/Presentation');
rmdir($buttonTypeRoot . '/src');
rmdir($buttonTypeRoot);

// AutoTopic identifies form data controls without a usable literal name.
$formNameRoot = sys_get_temp_dir() . '/tuzygent-topic-form-names-' . bin2hex(random_bytes(6));
mkdir($formNameRoot . '/src/Presentation/Views', 0775, true);
$formNamePath = $formNameRoot . '/src/Presentation/Views/fixture.php';
file_put_contents($formNamePath, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <input> -->
<input form="otherForm">
<form method="post">
    <input type="text">
    <input type="email" name="email">
    <input name="">
    <select><option>Choose</option></select>
    <textarea name="<?= htmlspecialchars($dynamicName) ?>"></textarea>
    <button>Save</button>
    <input type="reset">
</form>
PHP
);
$formNameTarget = 'CmsFormNames' . substr(sha1('src/Presentation/Views/fixture.php'), 0, 12);
$formNameTopics = array_values(array_filter(
    (new CmsTopicGenerator($formNameRoot))->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $formNameTarget
));
assertTrue(
    count($formNameTopics) === 1
        && str_contains($formNameTopics[0]['specification'], '3 input/select/textarea')
        && str_contains($formNameTopics[0]['specification'], 'dòng: 5, 7, 8')
        && str_contains($formNameTopics[0]['specification'], 'name=value'),
    'AutoTopic finds unnamed and empty-name controls inside forms while skipping comments, named/dynamic controls, buttons, and out-of-form fields'
);
assertTrue(
    !array_filter(
        (new CmsTopicGenerator($formNameRoot))->generate([strtolower($formNameTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $formNameTarget
    ),
    'AutoTopic suppresses a previously discovered missing form-name target'
);
unlink($formNamePath);
rmdir($formNameRoot . '/src/Presentation/Views');
rmdir($formNameRoot . '/src/Presentation');
rmdir($formNameRoot . '/src');
rmdir($formNameRoot);

// AutoTopic identifies literal labels whose `for` target is absent from static controls.
$labelControlRoot = sys_get_temp_dir() . '/tuzygent-topic-label-control-' . bin2hex(random_bytes(6));
mkdir($labelControlRoot . '/src/Presentation/Views', 0775, true);
$labelControlPath = $labelControlRoot . '/src/Presentation/Views/fixture.php';
file_put_contents($labelControlPath, <<<'PHP'
<?php
declare(strict_types=1);
$dynamicLabel = '<label for="runtime">Ignored string</label>';
?>
<form>
    <label for="missing">Missing target</label>
    <label for="email">Email</label><input id="email" type="email">
    <label for="<?= htmlspecialchars($dynamicId) ?>">Dynamic target</label>
    <!-- <label for="comment-only">Comment</label> -->
</form>
PHP
);
$labelControlTarget = 'CmsLabelControlLink' . substr(sha1('src/Presentation/Views/fixture.php'), 0, 12);
$labelControlTopics = array_values(array_filter(
    (new CmsTopicGenerator($labelControlRoot))->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $labelControlTarget
));
assertTrue(
    count($labelControlTopics) === 1
        && $labelControlTopics[0]['priority'] === 'P1'
        && str_contains($labelControlTopics[0]['specification'], '1 label')
        && str_contains($labelControlTopics[0]['specification'], 'dòng: 6')
        && str_contains($labelControlTopics[0]['specification'], 'không phải kết luận WCAG'),
    'AutoTopic finds a literal label reference without a matching control and ignores comments, PHP strings, and dynamic values'
);
assertTrue(
    !array_filter(
        (new CmsTopicGenerator($labelControlRoot))->generate([strtolower($labelControlTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $labelControlTarget
    ),
    'AutoTopic suppresses a previously discovered label-control target'
);
unlink($labelControlPath);
rmdir($labelControlRoot . '/src/Presentation/Views');
rmdir($labelControlRoot . '/src/Presentation');
rmdir($labelControlRoot . '/src');
rmdir($labelControlRoot);

$duplicateIdRoot = sys_get_temp_dir() . '/tuzygent-topic-duplicate-ids-' . bin2hex(random_bytes(6));
mkdir($duplicateIdRoot . '/src/Presentation/Views', 0775, true);
$duplicateIdPath = $duplicateIdRoot . '/src/Presentation/Views/fixture.php';
file_put_contents($duplicateIdPath, <<<'PHP'
<?php
declare(strict_types=1);
$runtimeId = 'from-runtime';
?>
<label for="shared">Name</label>
<input id="shared">
<div id="shared"></div>
<div id="unique"></div>
<div id="<?= htmlspecialchars($runtimeId) ?>"></div>
<!-- <div id="shared"></div> -->
PHP
);
file_put_contents($duplicateIdRoot . '/src/Presentation/Views/another.php', <<<'PHP'
<?php
declare(strict_types=1);
?>
<div id="shared"></div>
PHP
);
$duplicateIdTarget = 'CmsDuplicateId' . substr(sha1('src/Presentation/Views/fixture.php:shared'), 0, 12);
$duplicateIdTopics = (new CmsTopicGenerator($duplicateIdRoot))->generate();
$duplicateIdMatches = array_values(array_filter($duplicateIdTopics, static fn(array $topic): bool => $topic['target_name'] === $duplicateIdTarget));
assertTrue(
    count($duplicateIdMatches) === 1
        && $duplicateIdMatches[0]['priority'] === 'P2'
        && str_contains($duplicateIdMatches[0]['specification'], '6, 7')
        && str_contains($duplicateIdMatches[0]['specification'], 'label[for]'),
    'AutoTopic reports repeated literal IDs with lines and reference guidance while ignoring dynamic IDs, comments, and other templates'
);
assertTrue(
    (new CmsTopicGenerator($duplicateIdRoot))->generate([strtolower($duplicateIdTarget) => true]) === [],
    'AutoTopic suppresses an already discovered duplicate-ID target'
);
unlink($duplicateIdPath);
unlink($duplicateIdRoot . '/src/Presentation/Views/another.php');
rmdir($duplicateIdRoot . '/src/Presentation/Views');
rmdir($duplicateIdRoot . '/src/Presentation');
rmdir($duplicateIdRoot . '/src');
rmdir($duplicateIdRoot);

// AutoTopic finds local fragment links without a static target in the same PHP template.
$fragmentTestRoot = sys_get_temp_dir() . '/tuzygent-topic-fragments-' . bin2hex(random_bytes(6));
mkdir($fragmentTestRoot . '/src/Presentation/Views', 0775, true);
$fragmentFixture = $fragmentTestRoot . '/src/Presentation/Views/fragments.php';
file_put_contents($fragmentFixture, <<<'PHP'
<?php
declare(strict_types=1);
$dynamicId = 'runtime-target';
?>
<a href="#present">Present</a>
<a href="#missing">Missing</a>
<a href="#missing">Missing again</a>
<a href="<?= htmlspecialchars($dynamicHref) ?>">Dynamic href</a>
<a href="#other-template">Other template</a>
<a href="#encoded%20target">Encoded target</a>
<a href="#">Top</a>
<div id="present"></div>
<div id="<?= htmlspecialchars($dynamicId) ?>"></div>
<!-- <a href="#comment-only">Ignored</a> -->
<?php $literal = '<a href="#php-string">Ignored</a>'; ?>
PHP
);
file_put_contents($fragmentTestRoot . '/src/Presentation/Views/other.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<div id="other-template"></div>
PHP
);
$missingFragmentTarget = 'CmsMissingFragment' . substr(sha1('src/Presentation/Views/fragments.php:missing'), 0, 12);
$fragmentTopics = (new CmsTopicGenerator($fragmentTestRoot))->generate();
$missingFragmentMatches = array_values(array_filter(
    $fragmentTopics,
    static fn(array $topic): bool => $topic['target_name'] === $missingFragmentTarget
));
$crossTemplateFragmentTarget = 'CmsMissingFragment' . substr(sha1('src/Presentation/Views/fragments.php:other-template'), 0, 12);
assertTrue(
    count($missingFragmentMatches) === 1
        && $missingFragmentMatches[0]['priority'] === 'P2'
        && str_contains($missingFragmentMatches[0]['specification'], '6, 7')
        && str_contains($missingFragmentMatches[0]['specification'], 'layout/partial'),
    'AutoTopic reports repeated unresolved literal local fragments with line references and runtime composition guidance'
);
assertTrue(
    count(array_filter($fragmentTopics, static fn(array $topic): bool => str_starts_with($topic['target_name'], 'CmsMissingFragment'))) === 2
        && count(array_filter($fragmentTopics, static fn(array $topic): bool => $topic['target_name'] === $crossTemplateFragmentTarget)) === 1,
    'AutoTopic ignores static IDs, dynamic hrefs, comments, PHP strings, encoded values, and empty fragments while keeping template scope local'
);
$suppressedFragmentTopics = (new CmsTopicGenerator($fragmentTestRoot))->generate([strtolower($missingFragmentTarget) => true]);
assertTrue(
    !array_filter($suppressedFragmentTopics, static fn(array $topic): bool => $topic['target_name'] === $missingFragmentTarget),
    'AutoTopic suppresses an already discovered unresolved fragment target'
);
unlink($fragmentFixture);
unlink($fragmentTestRoot . '/src/Presentation/Views/other.php');
rmdir($fragmentTestRoot . '/src/Presentation/Views');
rmdir($fragmentTestRoot . '/src/Presentation');
rmdir($fragmentTestRoot . '/src');
rmdir($fragmentTestRoot);

// AutoTopic must keep resolved symlink targets inside the repository root.
$symlinkTestRoot = sys_get_temp_dir() . '/tuzygent-topics-symlink-' . bin2hex(random_bytes(6));
$symlinkOutsideRoot = sys_get_temp_dir() . '/tuzygent-topics-outside-' . bin2hex(random_bytes(6));
mkdir($symlinkTestRoot . '/src', 0775, true);
mkdir($symlinkOutsideRoot, 0775, true);
$outsidePhpFile = $symlinkOutsideRoot . '/MissingTypes.php';
file_put_contents($outsidePhpFile, "<?php\n\$outside = true;\n");
$externalPhpLink = $symlinkTestRoot . '/src/External.php';
assertTrue(symlink($outsidePhpFile, $externalPhpLink), 'AutoTopic symlink fixture created');
$externalTargetName = 'CmsStrictTypes' . substr(sha1('src/External.php'), 0, 12);
$externalFileTopics = (new CmsTopicGenerator($symlinkTestRoot))->generate();
assertTrue(
    !array_filter($externalFileTopics, static fn(array $topic): bool => $topic['target_name'] === $externalTargetName),
    'AutoTopic ignores PHP symlinks whose canonical target is outside the repository'
);
unlink($externalPhpLink);
unlink($outsidePhpFile);
rmdir($symlinkTestRoot . '/src');
rmdir($symlinkTestRoot);
rmdir($symlinkOutsideRoot);

// AutoTopic discovers repository ports without the matching SQLite adapter.
$adapterTopicRoot = sys_get_temp_dir() . '/tuzygent-topic-adapters-' . bin2hex(random_bytes(6));
mkdir($adapterTopicRoot . '/src/Domain/Port/Outbound', 0775, true);
file_put_contents($adapterTopicRoot . '/src/Domain/Port/Outbound/OrderRepositoryPort.php', "<?php\ndeclare(strict_types=1);\ninterface OrderRepositoryPort {}\n");
$adapterPortPath = 'src/Domain/Port/Outbound/OrderRepositoryPort.php';
$adapterTopicTarget = 'CmsAdapter' . substr(sha1($adapterPortPath), 0, 12);
$missingAdapterTopics = (new CmsTopicGenerator($adapterTopicRoot))->generate();
assertTrue(
    count($missingAdapterTopics) === 1
        && $missingAdapterTopics[0]['target_name'] === $adapterTopicTarget
        && $missingAdapterTopics[0]['priority'] === 'P1',
    'AutoTopic finds a repository port without a matching SQLite adapter and ignores ports outside the repository convention'
);
$suppressedAdapterTopics = (new CmsTopicGenerator($adapterTopicRoot))->generate([strtolower($adapterTopicTarget) => true]);
assertTrue($suppressedAdapterTopics === [], 'AutoTopic suppresses a previously discovered missing-adapter target');
mkdir($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite', 0775, true);
file_put_contents($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite/SqliteOrderRepository.php', "<?php\ndeclare(strict_types=1);\nfinal class SqliteOrderRepository {}\n");
file_put_contents($adapterTopicRoot . '/src/Domain/Port/Outbound/CartPort.php', "<?php\ndeclare(strict_types=1);\ninterface CartPort {}\n");
file_put_contents($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite/SqliteCartRepository.php', "<?php\ndeclare(strict_types=1);\nfinal class SqliteCartRepository {}\n");
assertTrue((new CmsTopicGenerator($adapterTopicRoot))->generate() === [], 'AutoTopic drops a missing-adapter topic after the matching SQLite adapter is added');
unlink($adapterTopicRoot . '/src/Domain/Port/Outbound/OrderRepositoryPort.php');
unlink($adapterTopicRoot . '/src/Domain/Port/Outbound/CartPort.php');
unlink($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite/SqliteCartRepository.php');
unlink($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite/SqliteOrderRepository.php');
rmdir($adapterTopicRoot . '/src/Infrastructure/Persistence/Sqlite');
rmdir($adapterTopicRoot . '/src/Infrastructure/Persistence');
rmdir($adapterTopicRoot . '/src/Infrastructure');
rmdir($adapterTopicRoot . '/src/Domain/Port/Outbound');
rmdir($adapterTopicRoot . '/src/Domain/Port');
rmdir($adapterTopicRoot . '/src/Domain');
rmdir($adapterTopicRoot . '/src');
rmdir($adapterTopicRoot);

$topicTestRoot = sys_get_temp_dir() . '/tuzygent-topics-' . bin2hex(random_bytes(6));
mkdir($topicTestRoot . '/src', 0775, true);
mkdir($topicTestRoot . '/src/Application/Service', 0775, true);
mkdir($topicTestRoot . '/tests', 0775, true);
$topicFixture = $topicTestRoot . '/src/Markers.php';
$topicSource = <<<'PHP'
<?php
declare(strict_types=1);

// TODO: Keep the stable marker.
// FIXME: A separate marker.
// TODO: Keep the stable marker.
PHP;
file_put_contents($topicFixture, $topicSource);
$topicGenerator = new CmsTopicGenerator($topicTestRoot);
$initialTopics = $topicGenerator->generate();
assertTrue(count($initialTopics) === 2, 'AutoTopic discovery deduplicates identical source markers while preserving distinct work');
$fixtureService = $topicTestRoot . '/src/Application/Service/FixtureService.php';
file_put_contents($fixtureService, "<?php\ndeclare(strict_types=1);\nfinal class FixtureService {}\n");
$fixtureTest = $topicTestRoot . '/tests/fixture_test.php';
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\n// FixtureService is mentioned in this comment only.\n\$description = 'FixtureService';\n");
$uncoveredTopics = $topicGenerator->generate();
assertTrue(count(array_filter($uncoveredTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat service names in comments or string literals as test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\n\$fixture->FixtureService();\n");
$methodNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($methodNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an unrelated method identifier as service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnamespace Tests;\nclass FixtureService {}\nnew FixtureService();\n");
$sameNamespaceNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($sameNamespaceNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an unimported same-name class as Application Service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnamespace Tests;\nuse Other\\FixtureService;\nnew FixtureService();\n");
$otherNamespaceNameTopics = $topicGenerator->generate();
assertTrue(count(array_filter($otherNamespaceNameTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 1, 'AutoTopic does not treat an imported same-name class from another namespace as service coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nuse App\\Application\\Service\\FixtureService as Subject;\nnew Subject();\n");
$aliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($aliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves an aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nuse App\\Application\\Service\\{FixtureService as Subject};\nnew Subject();\n");
$groupAliasedTopics = $topicGenerator->generate();
assertTrue(count(array_filter($groupAliasedTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic resolves a grouped aliased service import when detecting test coverage');
file_put_contents($fixtureTest, "<?php\ndeclare(strict_types=1);\nnew \\App\\Application\\Service\\FixtureService();\n");
$coveredTopics = $topicGenerator->generate();
assertTrue(count(array_filter($coveredTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsCoverageFixtureService')) === 0, 'AutoTopic recognizes a service referenced by a qualified PHP class name in tests');
$stableTopic = $initialTopics[0]['target_name'];
file_put_contents($topicFixture, "<?php\n\n\n" . substr($topicSource, strlen("<?php\n")));
$shiftedTopics = $topicGenerator->generate();
assertTrue($shiftedTopics[0]['target_name'] === $stableTopic, 'AutoTopic identity remains stable when unrelated lines shift marker positions');
$remainingTopics = $topicGenerator->generate([strtolower($stableTopic) => true]);
assertTrue(count($remainingTopics) === 1 && $remainingTopics[0]['target_name'] !== $stableTopic, 'AutoTopic history suppresses an already discovered marker after line shifts');
unlink($topicFixture);
unlink($fixtureService);
unlink($fixtureTest);
rmdir($topicTestRoot . '/src/Application/Service');
rmdir($topicTestRoot . '/src/Application');
rmdir($topicTestRoot . '/tests');
rmdir($topicTestRoot . '/src');
rmdir($topicTestRoot);

// AutoTopic flags PHP unserialize() call sites without treating comments, strings, declarations, or methods as calls.
$unserializeTestRoot = sys_get_temp_dir() . '/tuzygent-topic-unserialize-' . bin2hex(random_bytes(6));
mkdir($unserializeTestRoot . '/src', 0775, true);
$unserializeFixture = $unserializeTestRoot . '/src/UnsafePayload.php';
file_put_contents($unserializeFixture, <<<'PHP'
<?php
declare(strict_types=1);

// unserialize($commentOnly);
$example = 'unserialize($stringOnly)';
function unserialize($value) { return $value; }
$decoded = unserialize($_COOKIE['payload']);
$qualified = \\unserialize($storedValue, ['allowed_classes' => false]);
$object->unserialize($value);
$object?->unserialize($value);
Payload::unserialize($value);
PHP);
$unserializeGenerator = new CmsTopicGenerator($unserializeTestRoot);
$unserializeTopics = $unserializeGenerator->generate();
$unserializeTopic = array_values(array_filter(
    $unserializeTopics,
    static fn(array $topic): bool => $topic['target_name'] === 'CmsUnserialize' . substr(sha1('src/UnsafePayload.php'), 0, 12)
))[0] ?? null;
assertTrue(
    $unserializeTopic !== null
        && $unserializeTopic['priority'] === 'P0'
        && str_contains($unserializeTopic['specification'], 'không chứng minh dữ liệu đầu vào không tin cậy')
        && str_contains($unserializeTopic['specification'], 'dòng: 7, 8'),
    'AutoTopic identifies unqualified and fully qualified unserialize() calls while ignoring comments, strings, declarations, and methods'
);
$suppressedUnserializeTopics = $unserializeGenerator->generate([strtolower($unserializeTopic['target_name']) => true]);
assertTrue(
    !array_filter($suppressedUnserializeTopics, static fn(array $topic): bool => $topic['target_name'] === $unserializeTopic['target_name']),
    'AutoTopic suppresses an already discovered unserialize() review target'
);
unlink($unserializeFixture);
rmdir($unserializeTestRoot . '/src');
rmdir($unserializeTestRoot);

// 0.0.0.1. AutoTopic uses the nearest-rank P90 cutoff for large source files.
$outlierTestRoot = sys_get_temp_dir() . '/tuzygent-topic-outliers-' . bin2hex(random_bytes(6));
mkdir($outlierTestRoot . '/src', 0775, true);
for ($fileIndex = 1; $fileIndex <= 10; $fileIndex++) {
    $lineCount = match ($fileIndex) {
        9 => 200,
        10 => 300,
        default => 10,
    };
    $source = "<?php\ndeclare(strict_types=1);\n";
    for ($line = 1; $line < $lineCount; $line++) {
        $source .= '$value' . $line . ' = ' . $line . ";\n";
    }
    file_put_contents(sprintf('%s/src/File%02d.php', $outlierTestRoot, $fileIndex), $source);
}
$outlierTopics = (new CmsTopicGenerator($outlierTestRoot))->generate();
$largeFileTarget = 'CmsRefactor' . substr(sha1('src/File10.php'), 0, 12);
assertTrue(
    count($outlierTopics) === 1 && $outlierTopics[0]['target_name'] === $largeFileTarget,
    'AutoTopic nearest-rank P90 discovers a lone large-file outlier in a ten-file source sample'
);
foreach (glob($outlierTestRoot . '/src/*.php') ?: [] as $outlierFile) {
    unlink($outlierFile);
}
rmdir($outlierTestRoot . '/src');
rmdir($outlierTestRoot);

// 0.0.0.1.1. AutoTopic must not classify comment-heavy files as large source outliers.
$commentOutlierRoot = sys_get_temp_dir() . '/tuzygent-topic-comment-outlier-' . bin2hex(random_bytes(6));
mkdir($commentOutlierRoot . '/src', 0775, true);
for ($fileIndex = 1; $fileIndex <= 10; $fileIndex++) {
    $lineCount = $fileIndex === 10 ? 300 : 10;
    $source = "<?php\ndeclare(strict_types=1);\n";
    if ($fileIndex === 10) {
        $source .= str_repeat("// documentation only\n", $lineCount - 1);
    } else {
        for ($line = 1; $line < $lineCount; $line++) {
            $source .= '$value' . $line . ' = ' . $line . ";\n";
        }
    }
    file_put_contents(sprintf('%s/src/File%02d.php', $commentOutlierRoot, $fileIndex), $source);
}
$commentOutlierTopics = (new CmsTopicGenerator($commentOutlierRoot))->generate();
assertTrue($commentOutlierTopics === [], 'AutoTopic excludes comment-only lines from large-source outlier measurements');
foreach (glob($commentOutlierRoot . '/src/*.php') ?: [] as $outlierFile) {
    unlink($outlierFile);
}
rmdir($commentOutlierRoot . '/src');
rmdir($commentOutlierRoot);

// 0.0.0.2. AutoTopic caps each discovery batch and leaves deferred targets discoverable.
$batchTestRoot = sys_get_temp_dir() . '/tuzygent-topic-batch-' . bin2hex(random_bytes(6));
mkdir($batchTestRoot . '/src', 0775, true);
mkdir($batchTestRoot . '/src/Application/Service', 0775, true);
$batchMarkers = [];
$batchSource = "<?php\ndeclare(strict_types=1);\n";
for ($markerIndex = 1; $markerIndex <= 12; $markerIndex++) {
    $batchSource .= sprintf("// TODO: Batch fixture %02d.\n", $markerIndex);
}
file_put_contents($batchTestRoot . '/src/Markers.php', $batchSource);
file_put_contents($batchTestRoot . '/src/Application/Service/DeferredService.php', "<?php\ndeclare(strict_types=1);\nfinal class DeferredService {}\n");
$batchGenerator = new CmsTopicGenerator($batchTestRoot);
$firstBatch = $batchGenerator->generate();
assertTrue(
    count($firstBatch) === 10 && count(array_filter($firstBatch, static fn(array $topic): bool => $topic['priority'] !== 'P0')) === 0,
    'AutoTopic limits a discovery batch to ten highest-priority source targets'
);
foreach ($firstBatch as $topic) {
    $batchMarkers[strtolower($topic['target_name'])] = true;
}
$deferredBatch = $batchGenerator->generate($batchMarkers);
assertTrue(
    count($deferredBatch) === 3
        && count(array_filter($deferredBatch, static fn(array $topic): bool => $topic['priority'] === 'P0')) === 2
        && count(array_filter($deferredBatch, static fn(array $topic): bool => $topic['priority'] === 'P1')) === 1,
    'AutoTopic exposes deferred source targets on a later discovery cycle'
);
unlink($batchTestRoot . '/src/Markers.php');
unlink($batchTestRoot . '/src/Application/Service/DeferredService.php');
rmdir($batchTestRoot . '/src/Application/Service');
rmdir($batchTestRoot . '/src/Application');
rmdir($batchTestRoot . '/src');
rmdir($batchTestRoot);

// 0.0.1. AutoTopic should rediscover completed source topics after a regression.
$regressionTestRoot = sys_get_temp_dir() . '/tuzygent-topic-regression-' . bin2hex(random_bytes(6));
mkdir($regressionTestRoot . '/src', 0775, true);
file_put_contents($regressionTestRoot . '/src/Fixture.php', "<?php\nclass Fixture {}\n");
$regressionTarget = 'CmsStrictTypes' . substr(sha1('src/Fixture.php'), 0, 12);
$regressionFakeBin = $regressionTestRoot . '/bin';
mkdir($regressionFakeBin, 0775, true);
file_put_contents($regressionFakeBin . '/claude', "#!/bin/sh\nexit 0\n");
chmod($regressionFakeBin . '/claude', 0775);
$regressionPreviousPath = getenv('PATH');
putenv('PATH=' . $regressionFakeBin);

$completedTopicRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:'));
$completedTopicRepository->save(new \App\Domain\Entity\HarnessTask(
    id: null,
    taskCode: 'TZY-001',
    title: 'Previously completed source topic',
    specification: '',
    actionType: 'research_and_develop',
    targetName: $regressionTarget,
    agentBackend: 'claude',
    status: \App\Domain\Enum\HarnessTaskStatus::COMPLETED,
    attempts: 1,
    maxAttempts: 3,
    snapshotId: ''
));
$completedTopicHarness = new \App\Application\Service\TuzygentHarnessService($completedTopicRepository, $regressionTestRoot);
$rediscoveredTopicId = $completedTopicHarness->discoverNextEvolutionTask();
$rediscoveredTopic = $rediscoveredTopicId === null ? null : $completedTopicHarness->getTask($rediscoveredTopicId);
assertTrue(
    $rediscoveredTopic?->targetName === $regressionTarget,
    'AutoTopic rediscovers a stable source target after its earlier task completed'
);

$activeTopicRoot = $regressionTestRoot . '/active';
mkdir($activeTopicRoot . '/src', 0775, true);
file_put_contents($activeTopicRoot . '/src/Fixture.php', "<?php\nclass Fixture {}\n");
$activeTopicRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:'));
$activeTopicRepository->save(new \App\Domain\Entity\HarnessTask(
    id: null,
    taskCode: 'TZY-001',
    title: 'Active source topic',
    specification: '',
    actionType: 'research_and_develop',
    targetName: $regressionTarget,
    agentBackend: 'claude',
    status: \App\Domain\Enum\HarnessTaskStatus::BACKLOG,
    attempts: 0,
    maxAttempts: 3,
    snapshotId: ''
));
$activeTopicHarness = new \App\Application\Service\TuzygentHarnessService($activeTopicRepository, $activeTopicRoot);
$activeDiscoveryId = $activeTopicHarness->discoverNextEvolutionTask();
$activeDiscovery = $activeDiscoveryId === null ? null : $activeTopicHarness->getTask($activeDiscoveryId);
assertTrue(
    $activeDiscovery?->targetName === 'AutoTopic'
        && count(array_filter($activeTopicHarness->listTasks(), static fn(\App\Domain\Entity\HarnessTask $task): bool => $task->targetName === $regressionTarget)) === 1,
    'AutoTopic keeps an existing backlog source target suppressed'
);

putenv('PATH=' . ($regressionPreviousPath === false ? '' : $regressionPreviousPath));
unlink($regressionFakeBin . '/claude');
rmdir($regressionFakeBin);
unlink($activeTopicRoot . '/src/Fixture.php');
rmdir($activeTopicRoot . '/src');
rmdir($activeTopicRoot . '/storage/snapshots');
rmdir($activeTopicRoot . '/storage');
rmdir($activeTopicRoot . '/.harness');
rmdir($activeTopicRoot);
unlink($regressionTestRoot . '/src/Fixture.php');
rmdir($regressionTestRoot . '/src');
rmdir($regressionTestRoot . '/storage/snapshots');
rmdir($regressionTestRoot . '/storage');
rmdir($regressionTestRoot . '/.harness');
rmdir($regressionTestRoot);

// 0.0.2. AutoTopic should use the highest-priority supported CLI available on PATH.
$backendTestRoot = sys_get_temp_dir() . '/tuzygent-backends-' . bin2hex(random_bytes(6));
$fakeBin = $backendTestRoot . '/bin';
mkdir($fakeBin, 0775, true);
$previousPath = getenv('PATH');
$previousPath = $previousPath === false ? '' : $previousPath;
foreach (['claude', 'opencode'] as $fakeBackend) {
    $fakeBinary = $fakeBin . '/' . $fakeBackend;
    file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
    chmod($fakeBinary, 0775);
}
putenv('PATH=' . $fakeBin);
$backendHarness = new \App\Application\Service\TuzygentHarnessService(
    new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:')),
    $backendTestRoot
);
$autoTopicId = $backendHarness->discoverNextEvolutionTask();
$autoTopic = $autoTopicId === null ? null : $backendHarness->getTask($autoTopicId);
assertTrue($autoTopic !== null && $autoTopic->targetName === 'AutoTopic' && $autoTopic->agentBackend === 'claude', 'AutoTopic selects the first supported CLI present when Codex is unavailable');
putenv('PATH=' . $previousPath);
unlink($fakeBin . '/claude');
unlink($fakeBin . '/opencode');
rmdir($fakeBin);
rmdir($backendTestRoot . '/storage/snapshots');
rmdir($backendTestRoot . '/storage');
rmdir($backendTestRoot . '/.harness');
rmdir($backendTestRoot);

// 0.0.2. Harness task codes follow SQLite's durable, non-reused AUTOINCREMENT ID.
$taskCodeTestRoot = sys_get_temp_dir() . '/tuzygent-task-codes-' . bin2hex(random_bytes(6));
$taskCodeRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteHarnessRepository(new PDO('sqlite::memory:'));
$taskCodeHarness = new \App\Application\Service\TuzygentHarnessService($taskCodeRepository, $taskCodeTestRoot);
$firstCreatedTaskId = $taskCodeHarness->createTask(['title' => 'Task code fixture 1']);
$firstCreatedTask = $taskCodeHarness->getTask($firstCreatedTaskId);
$taskCodeHarness->deleteTask(2);
$secondCreatedTaskId = $taskCodeHarness->createTask(['title' => 'Task code fixture 2']);
$secondCreatedTask = $taskCodeHarness->getTask($secondCreatedTaskId);
assertTrue(
    $firstCreatedTask?->taskCode === 'TZY-003'
        && $secondCreatedTask?->taskCode === 'TZY-004'
        && $firstCreatedTask->taskCode !== $secondCreatedTask->taskCode,
    'Harness task codes follow durable SQLite IDs and do not collide after deleting an older task'
);
rmdir($taskCodeTestRoot . '/storage/snapshots');
rmdir($taskCodeTestRoot . '/storage');
rmdir($taskCodeTestRoot . '/.harness');
rmdir($taskCodeTestRoot);

// 0.1. Verify comment pagination applies stable ordering and matching filters to list/count queries.
$commentDbPath = sys_get_temp_dir() . '/tuzygent-comments-' . bin2hex(random_bytes(6)) . '.sqlite';
$commentPdo = new PDO('sqlite:' . $commentDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$commentPdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY AUTOINCREMENT, commentable_type TEXT NOT NULL, commentable_id INTEGER NOT NULL, author_name TEXT NOT NULL, author_email TEXT NOT NULL, content TEXT NOT NULL, rating INTEGER NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL)');
$commentPdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$commentPdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
$commentInsert = $commentPdo->prepare('INSERT INTO comments (commentable_type, commentable_id, author_name, author_email, content, rating, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
for ($commentIndex = 1; $commentIndex <= 25; $commentIndex++) {
    $commentInsert->execute([
        $commentIndex % 2 === 0 ? 'product' : 'post',
        $commentIndex,
        'Author ' . $commentIndex,
        'author@example.test',
        'Comment ' . $commentIndex,
        5,
        $commentIndex % 3 === 0 ? 'pending' : 'approved',
        '2026-01-01 00:00:00',
    ]);
}
$commentRepository = new SqliteCommentRepository($commentPdo);
$firstCommentPage = $commentRepository->findAll(null, null, 20, 0);
$secondCommentPage = $commentRepository->findAll(null, null, 20, 20);
assertTrue(count($firstCommentPage) === 20 && count($secondCommentPage) === 5, 'Comment repository returns bounded pages using LIMIT and OFFSET');
assertTrue($firstCommentPage[0]->id === 25 && $secondCommentPage[0]->id === 5, 'Comment pagination preserves descending ID order across pages');
assertTrue($commentRepository->countMatching(CommentStatus::PENDING, 'product') === 4 && count($commentRepository->findAll(CommentStatus::PENDING, 'product', 20, 0)) === 4, 'Comment count and page queries apply the same status and type filters');
$commentPdo = null;
unlink($commentDbPath);

// 0.2. Blog search includes assigned tags without duplicating posts or exposing drafts.
$postSearchPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$postSearchPdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$postSearchPdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL)');
$postSearchPdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$postSearchPdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, slug TEXT NOT NULL, excerpt TEXT NOT NULL, content TEXT NOT NULL, featured_image TEXT NOT NULL, status TEXT NOT NULL, category_id INTEGER, author_id INTEGER NOT NULL, views INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$postSearchPdo->exec('CREATE TABLE post_tags (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (post_id, tag_id))');
$postSearchPdo->exec("INSERT INTO categories VALUES (1, 'Engineering')");
$postSearchPdo->exec("INSERT INTO users VALUES (1, 'Editor')");
$postSearchPdo->exec("INSERT INTO tags VALUES (1, 'Accessibility'), (2, 'Web Accessibility'), (3, 'Accessibility Draft')");
$postSearchPdo->exec("INSERT INTO posts VALUES
    (1, 'Keyboard navigation', 'keyboard-navigation', '', 'Practical keyboard tips', '', 'published', 1, 1, 0, '2026-01-01', '2026-01-01'),
    (2, 'Unpublished guide', 'unpublished-guide', '', '', '', 'draft', 1, 1, 0, '2026-01-02', '2026-01-02')");
$postSearchPdo->exec('INSERT INTO post_tags VALUES (1, 1), (1, 2), (2, 3)');
$postSearchRepository = new \App\Infrastructure\Persistence\Sqlite\SqlitePostRepository($postSearchPdo);
$tagSearchResults = $postSearchRepository->findAll(ContentStatus::PUBLISHED, 1, 'accessibility', 1, 0);
assertTrue(
    count($tagSearchResults) === 1
        && $tagSearchResults[0]->id === 1
        && $postSearchRepository->countMatching(ContentStatus::PUBLISHED, 1, 'accessibility') === 1,
    'Post search matches assigned tag names, counts a post once when multiple tags match, and keeps status/category filters aligned'
);
$postSearchPdo = null;

// 0.3. Verify media library pagination uses bounded queries and an accurate total.
$mediaPagePdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mediaPagePdo->exec('CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL, original_name TEXT NOT NULL, file_path TEXT NOT NULL, mime_type TEXT NOT NULL, file_size INTEGER NOT NULL, alt_text TEXT NOT NULL, created_at TEXT NOT NULL)');
$mediaPageRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteMediaRepository($mediaPagePdo);
for ($mediaIndex = 1; $mediaIndex <= 25; $mediaIndex++) {
    $mediaPageRepository->save(new \App\Domain\Entity\MediaFile(
        id: null,
        filename: 'media-' . $mediaIndex . '.png',
        originalName: 'Media ' . $mediaIndex . '.png',
        filePath: '/uploads/media-' . $mediaIndex . '.png',
        mimeType: 'image/png',
        fileSize: 1024,
        altText: '',
        createdAt: '2026-01-01 00:00:00'
    ));
}
$firstMediaPage = $mediaPageRepository->findAll(24, 0);
$secondMediaPage = $mediaPageRepository->findAll(24, 24);
assertTrue(count($firstMediaPage) === 24 && count($secondMediaPage) === 1 && $mediaPageRepository->countAll() === 25, 'Media repository returns bounded pages and an accurate total');
assertTrue($firstMediaPage[0]->id === 25 && $secondMediaPage[0]->id === 1, 'Media pagination preserves descending ID order across pages');
assertTrue(count($mediaPageRepository->findAll(24, -5)) === 24, 'Media pagination clamps negative offsets to the first page');
$productControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Admin/ProductController.php');
$productFormSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/products/form.php');
assertTrue(
    str_contains($productControllerSource, "'mediaFiles' => \$mediaService->listPage(6, 0)")
        && !str_contains($productControllerSource, "'mediaFiles' => \$mediaService->listAll()")
        && str_contains($productFormSource, 'foreach ($mediaFiles as $mf)')
        && !str_contains($productFormSource, 'array_slice($mediaFiles, 0, 6)'),
    'Product form fetches only the six newest media records and renders the bounded result directly'
);
$mediaPagePdo = null;

// 1. Verify Media uploads validate file contents, not client-supplied MIME metadata.
$mediaTestRoot = sys_get_temp_dir() . '/tuzygent-media-' . bin2hex(random_bytes(6));
$mediaUploadsDir = $mediaTestRoot . '/uploads';
mkdir($mediaTestRoot, 0775, true);
$validImagePath = $mediaTestRoot . '/valid.png';
$spoofedImagePath = $mediaTestRoot . '/spoofed.jpg';
$svgImagePath = $mediaTestRoot . '/active.svg';
$oversizedImagePath = $mediaTestRoot . '/oversized.png';
file_put_contents($validImagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true));
file_put_contents($spoofedImagePath, '<?php echo "not an image";');
file_put_contents($svgImagePath, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
file_put_contents($oversizedImagePath, str_repeat('x', 5 * 1024 * 1024 + 1));
$mediaStorage = new \App\Infrastructure\Storage\LocalMediaStorageAdapter($mediaUploadsDir);
$storedImage = $mediaStorage->storeUploadedFile([
    'name' => 'pixel.png',
    'tmp_name' => $validImagePath,
    'type' => 'text/plain',
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($validImagePath),
]);
assertTrue($storedImage->mimeType === 'image/png' && is_file($mediaUploadsDir . '/' . $storedImage->filename), 'Media upload detects image MIME from file contents instead of trusting client metadata');

$spoofedUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'payload.jpg',
        'tmp_name' => $spoofedImagePath,
        'type' => 'image/jpeg',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($spoofedImagePath),
    ]);
} catch (RuntimeException) {
    $spoofedUploadRejected = true;
}
assertTrue($spoofedUploadRejected && count(array_diff(scandir($mediaUploadsDir) ?: [], ['.', '..'])) === 1, 'Media upload rejects non-image content with a forged image extension and MIME');
$svgUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'active.svg',
        'tmp_name' => $svgImagePath,
        'type' => 'image/svg+xml',
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($svgImagePath),
    ]);
} catch (RuntimeException) {
    $svgUploadRejected = true;
}
assertTrue($svgUploadRejected, 'Media upload rejects SVG files that could contain active content');
$oversizedUploadRejected = false;
try {
    $mediaStorage->storeUploadedFile([
        'name' => 'oversized.png',
        'tmp_name' => $oversizedImagePath,
        'type' => 'image/png',
        'error' => UPLOAD_ERR_OK,
        'size' => 1,
    ]);
} catch (RuntimeException $error) {
    $oversizedUploadRejected = str_contains($error->getMessage(), '5 MiB');
}
assertTrue($oversizedUploadRejected && count(array_diff(scandir($mediaUploadsDir) ?: [], ['.', '..'])) === 1, 'Media upload enforces the server-side 5 MiB limit using the temporary file size before storing it');
$mediaStorage->deletePhysicalFile($storedImage->filename);
unlink($validImagePath);
unlink($spoofedImagePath);
unlink($svgImagePath);
unlink($oversizedImagePath);
rmdir($mediaUploadsDir);
rmdir($mediaTestRoot);

// 2. Verify Seeded Posts & Products
$postService = $container->get(PostService::class);
$productService = $container->get(ProductService::class);
$moduleManager = $container->get(ModuleManagerService::class);

$shopTemplateData = [
    'products' => [],
    'categories' => [new Category(73, 'Thiết bị & phụ kiện', 'thiet-bi', 'product', '', 2)],
    'currentCategory' => 12,
    'search' => 'wireless & mouse',
    'minimumPrice' => 20.0,
    'maximumPrice' => 80.0,
    'inStock' => true,
    'sort' => ProductSort::PRICE_ASC,
    'currentPage' => 1,
    'totalPages' => 2,
    'enabledModules' => [],
];
ob_start();
extract($shopTemplateData, EXTR_SKIP);
include dirname(__DIR__) . '/src/Presentation/Views/frontend/shop/index.php';
$shopTemplateHtml = (string) ob_get_clean();
assertTrue(
    preg_match('~<label for="shop-search-query">Từ khóa sản phẩm</label>\s*<input id="shop-search-query" type="text" name="s" value="wireless &amp; mouse" placeholder="Tên sản phẩm, SKU\.\.\."~', $shopTemplateHtml) === 1
        && substr_count($shopTemplateHtml, 'id="shop-search-query"') === 1,
    'Rendered shop search input has a visible label explicitly associated with its unique control ID while preserving the search value'
);
assertTrue(
    str_contains($shopTemplateHtml, 'name="in_stock" value="1" checked')
        && str_contains($shopTemplateHtml, '/shop?category=73&amp;s=wireless+%26+mouse&amp;sort=price_asc&amp;min_price=20&amp;max_price=80&amp;in_stock=1')
        && str_contains($shopTemplateHtml, '/shop?category=12&amp;s=wireless+%26+mouse&amp;sort=price_asc&amp;min_price=20&amp;max_price=80&amp;in_stock=1&amp;page=2')
        && str_contains($shopTemplateHtml, 'aria-label="Phân trang sản phẩm"')
        && str_contains($shopTemplateHtml, 'href="/shop?category=12&amp;s=wireless+%26+mouse&amp;sort=price_asc&amp;min_price=20&amp;max_price=80&amp;in_stock=1&amp;page=2" aria-label="Trang 2 sản phẩm"')
        && substr_count($shopTemplateHtml, 'aria-current="page"') === 1
        && str_contains($shopTemplateHtml, 'Thiết bị &amp; phụ kiện'),
    'Shop category and numbered pagination links preserve and safely encode active search, sort, price, and stock filters and mark one current page'
);

$blogTemplateData = [
    'posts' => [],
    'categories' => [],
    'currentCategory' => 12,
    'search' => 'wireless & mouse',
    'currentPage' => 4,
    'totalPages' => 8,
];
ob_start();
extract($blogTemplateData, EXTR_OVERWRITE);
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/index.php';
$blogTemplateHtml = (string) ob_get_clean();
assertTrue(
    preg_match('~<label for="blog-search-query">Từ khóa</label>\s*<input id="blog-search-query" type="text" name="s"~', $blogTemplateHtml) === 1
        && substr_count($blogTemplateHtml, 'id="blog-search-query"') === 1
        && str_contains($blogTemplateHtml, 'aria-label="Phân trang bài viết"')
        && str_contains($blogTemplateHtml, 'href="/blog?category=12&amp;s=wireless+%26+mouse&amp;page=3" aria-label="Trang 3 bài viết"')
        && str_contains($blogTemplateHtml, '<span class="fe-search-page-current" aria-current="page" aria-label="Trang 4 hiện tại">4</span>')
        && substr_count($blogTemplateHtml, 'aria-current="page"') === 1,
    'Rendered blog search input has a visible, explicitly associated label; pagination preserves filters and marks exactly one current page'
);
$currentPage = 8;
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/index.php';
$lastBlogPageHtml = (string) ob_get_clean();
assertTrue(
    str_contains($lastBlogPageHtml, 'href="/blog?category=12&amp;s=wireless+%26+mouse&amp;page=7"')
        && str_contains($lastBlogPageHtml, 'aria-label="Trang 8 hiện tại">8</span>')
        && !str_contains($lastBlogPageHtml, 'Trang sau →'),
    'Blog pagination window reaches the last page and omits the next-page link at the end'
);

$post = new \App\Domain\Entity\Post(
    id: 901,
    title: 'Bài viết kiểm tra nhãn',
    slug: 'bai-viet-kiem-tra-nhan',
    excerpt: '',
    content: '<p>Nội dung</p>',
    featuredImage: '',
    status: ContentStatus::PUBLISHED,
    categoryId: null,
    categoryName: null,
    authorId: 1,
    authorName: 'Tác giả',
);
$comments = [];
$enabledModules = ['comments' => true];
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/show.php';
$commentLabelTestHtml = (string) ob_get_clean();
$commentFieldLabels = [
    'comment-author-name' => 'Họ và tên *',
    'comment-author-email' => 'Email *',
    'comment-content' => 'Nội dung bình luận *',
];
$commentLabelsAssociated = true;
foreach ($commentFieldLabels as $controlId => $visibleLabel) {
    $escapedControlId = preg_quote($controlId, '~');
    $escapedVisibleLabel = preg_quote($visibleLabel, '~');
    if (preg_match('~<label for="' . $escapedControlId . '">' . $escapedVisibleLabel . '</label>\s*<(?:input|textarea)\b[^>]*\bid="' . $escapedControlId . '"(?=[\s>])~', $commentLabelTestHtml) !== 1
        || substr_count($commentLabelTestHtml, 'id="' . $controlId . '"') !== 1) {
        $commentLabelsAssociated = false;
        break;
    }
}
assertTrue(
    $commentLabelsAssociated
        && str_contains($commentLabelTestHtml, 'name="author_name"')
        && str_contains($commentLabelTestHtml, 'name="author_email"')
        && str_contains($commentLabelTestHtml, 'name="content"')
        && preg_match('~<input\b(?=[^>]*\bid="comment-author-email")(?=[^>]*\btype="email")(?=[^>]*\bautocomplete="email")[^>]*>~', $commentLabelTestHtml) === 1
        && preg_match('~<(?:input|textarea)\b(?=[^>]*\bid="(?:comment-author-name|comment-content)")(?=[^>]*\bautocomplete=)[^>]*>~', $commentLabelTestHtml) !== 1
        && str_contains($commentLabelTestHtml, '<p>Nội dung</p>'),
    'Rendered blog post preserves allowed rich content and displays a uniquely labeled comment form with email autocomplete only on the email field'
);

$post->content = '<p><strong>Nội dung an toàn</strong><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)" onclick="alert(4)">liên kết</a></p>';
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/show.php';
$sanitizedBlogPostHtml = (string) ob_get_clean();
assertTrue(
    str_contains($sanitizedBlogPostHtml, '<p><strong>Nội dung an toàn</strong>')
        && str_contains($sanitizedBlogPostHtml, '<a>liên kết</a>')
        && !preg_match('/<script|<img|onerror|onclick|javascript:/i', $sanitizedBlogPostHtml),
    'Rendered blog post preserves allowed formatting while removing scripts, event handlers, disallowed images, and unsafe links'
);

$post->content = '<h2>Tiêu đề hợp lệ</h2><p><em>Định dạng an toàn</em> <a href="https://example.com">Liên kết an toàn</a> <a href="jav&#x61;script:alert(1)">Liên kết nguy hiểm</a></p><svg onload="alert(2)"><script>alert(3)</script></svg><iframe src="https://evil.example">Khung ngoài</iframe><style>body{background:url(javascript:alert(4))}</style>';
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/show.php';
$contextFilteredBlogPostHtml = (string) ob_get_clean();
assertTrue(
    str_contains($contextFilteredBlogPostHtml, '<h2>Tiêu đề hợp lệ</h2>')
        && str_contains($contextFilteredBlogPostHtml, '<em>Định dạng an toàn</em>')
        && str_contains($contextFilteredBlogPostHtml, '<a href="https://example.com">Liên kết an toàn</a>')
        && str_contains($contextFilteredBlogPostHtml, '<a>Liên kết nguy hiểm</a>')
        && !preg_match('/<script|<svg|<iframe|<style|onload|javascript:/i', $contextFilteredBlogPostHtml),
    'Rendered blog post retains semantic formatting and safe links while removing encoded script schemes and active HTML contexts'
);

$post->content = 'Tài liệu AT&T: 2 < 5';
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/frontend/blog/show.php';
$plainTextBlogPostHtml = (string) ob_get_clean();
assertTrue(
    str_contains($plainTextBlogPostHtml, 'Tài liệu AT&amp;T: 2 &lt; 5')
        && !str_contains($plainTextBlogPostHtml, '< 5'),
    'Rendered blog post safely displays plain text containing ampersands and angle brackets'
);

$posts = $postService->listPosts();
assertTrue(count($posts) >= 2, 'Database seeded with sample blog posts');
$publishedPostCount = $postService->countPosts(ContentStatus::PUBLISHED);
$postPage = $postService->listPosts(ContentStatus::PUBLISHED, null, '', 1, 1);
assertTrue($publishedPostCount >= 2 && count($postPage) === 1 && $postPage[0]->id !== $posts[0]->id, 'Post listing supports filtered counts and a stable limit/offset page');

$products = $productService->listProducts();
assertTrue(count($products) >= 3, 'Database seeded with sample products');
$publishedProductCount = $productService->countProducts(ContentStatus::PUBLISHED);
$productPage = $productService->listProducts(ContentStatus::PUBLISHED, null, '', null, 1, 1);
assertTrue($publishedProductCount >= 2 && count($productPage) === 1 && $productPage[0]->id !== $products[0]->id, 'Product listing supports filtered counts and a stable limit/offset page');

$literalSearchToken = 'TZY' . strtoupper(bin2hex(random_bytes(4)));
$literalPostId = $postService->savePost([
    'title' => 'Literal search ' . $literalSearchToken . '!_%',
    'excerpt' => 'Literal wildcard regression',
    'content' => 'Literal wildcard regression',
    'status' => ContentStatus::PUBLISHED->value,
], 1);
$wildcardPostId = $postService->savePost([
    'title' => 'Literal search ' . $literalSearchToken . 'AXQ',
    'excerpt' => 'Wildcard decoy',
    'content' => 'Wildcard decoy',
    'status' => ContentStatus::PUBLISHED->value,
], 1);
$literalPostResults = $postService->listPosts(ContentStatus::PUBLISHED, null, $literalSearchToken . '!_%');
assertTrue(
    count($literalPostResults) === 1
        && $literalPostResults[0]->id === $literalPostId
        && $postService->countPosts(ContentStatus::PUBLISHED, null, $literalSearchToken . '!_%') === 1,
    'Post search treats LIKE wildcard and escape characters as literal text in both listing and count queries'
);
$postService->deletePermanently($literalPostId);
$postService->deletePermanently($wildcardPostId);

$literalProductId = $productService->saveProduct([
    'name' => 'Literal search ' . $literalSearchToken . '!_%',
    'price' => 1200,
    'sku' => 'LIT-' . $literalSearchToken,
    'stock_quantity' => 5,
    'status' => ContentStatus::PUBLISHED->value,
]);
$wildcardProductId = $productService->saveProduct([
    'name' => 'Literal search ' . $literalSearchToken . 'AXQ',
    'price' => 1300,
    'sku' => 'DEC-' . $literalSearchToken,
    'stock_quantity' => 5,
    'status' => ContentStatus::PUBLISHED->value,
]);
$literalProductResults = $productService->listProducts(ContentStatus::PUBLISHED, null, $literalSearchToken . '!_%');
assertTrue(
    count($literalProductResults) === 1
        && $literalProductResults[0]->id === $literalProductId
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $literalSearchToken . '!_%') === 1,
    'Product search treats LIKE wildcard and escape characters as literal text in both listing and count queries'
);
$productService->deletePermanently($literalProductId);
$productService->deletePermanently($wildcardProductId);

$descriptionSearchToken = 'DESC' . strtoupper(bin2hex(random_bytes(4)));
$descriptionProductId = $productService->saveProduct([
    'name' => 'Description search fixture',
    'description' => 'Details contain ' . $descriptionSearchToken,
    'price' => 1400,
    'sku' => 'DESC-FIXTURE',
    'stock_quantity' => 5,
    'status' => ContentStatus::PUBLISHED->value,
]);
$tagSearchToken = 'TAG' . strtoupper(bin2hex(random_bytes(4)));
$tagProductId = $productService->saveProduct([
    'name' => 'Tagged search fixture',
    'description' => 'Tag matching fixture',
    'tags' => $tagSearchToken . ' first, ' . $tagSearchToken . ' second',
    'price' => 1500,
    'sku' => 'TAG-FIXTURE',
    'stock_quantity' => 5,
    'status' => ContentStatus::PUBLISHED->value,
]);
$descriptionSearchResults = $productService->listProducts(ContentStatus::PUBLISHED, null, $descriptionSearchToken);
$tagSearchResults = $productService->listProducts(ContentStatus::PUBLISHED, null, $tagSearchToken);
assertTrue(
    count($descriptionSearchResults) === 1
        && $descriptionSearchResults[0]->id === $descriptionProductId
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $descriptionSearchToken) === 1
        && count($tagSearchResults) === 1
        && $tagSearchResults[0]->id === $tagProductId
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $tagSearchToken) === 1,
    'Product search matches full descriptions and tags, counts tagged products once, and aligns listing with count queries'
);
$productService->deletePermanently($descriptionProductId);
$productService->deletePermanently($tagProductId);

$sortingIds = [];
$sortingIds[] = $productService->saveProduct([
    'name' => 'Sort fixture Charlie ' . $literalSearchToken,
    'price' => 300,
    'sale_price' => 180,
    'sku' => 'SORT-C-' . $literalSearchToken,
    'stock_quantity' => 2,
    'status' => ContentStatus::PUBLISHED->value,
]);
$sortingIds[] = $productService->saveProduct([
    'name' => 'Sort fixture Alpha ' . $literalSearchToken,
    'price' => 100,
    'sku' => 'SORT-A-' . $literalSearchToken,
    'stock_quantity' => 2,
    'status' => ContentStatus::PUBLISHED->value,
]);
$sortingIds[] = $productService->saveProduct([
    'name' => 'Sort fixture Bravo ' . $literalSearchToken,
    'price' => 250,
    'sku' => 'SORT-B-' . $literalSearchToken,
    'stock_quantity' => 2,
    'status' => ContentStatus::PUBLISHED->value,
]);
$priceSortedProducts = $productService->listProducts(ContentStatus::PUBLISHED, null, $literalSearchToken, null, null, 0, ProductSort::PRICE_ASC);
$nameSortedProducts = $productService->listProducts(ContentStatus::PUBLISHED, null, $literalSearchToken, null, null, 0, ProductSort::NAME_ASC);
$priceRangeProducts = $productService->listProducts(ContentStatus::PUBLISHED, null, $literalSearchToken, null, null, 0, ProductSort::PRICE_ASC, 180, 250);
assertTrue(
    array_map(static fn($product): int => (int) $product->id, $priceSortedProducts) === [$sortingIds[1], $sortingIds[0], $sortingIds[2]]
        && array_map(static fn($product): int => (int) $product->id, $nameSortedProducts) === [$sortingIds[1], $sortingIds[2], $sortingIds[0]],
    'Product catalog sorting uses effective sale prices and case-insensitive names in a stable order'
);
assertTrue(
    array_map(static fn($product): int => (int) $product->id, $priceRangeProducts) === [$sortingIds[0], $sortingIds[2]]
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $literalSearchToken, null, 180, 250) === count($priceRangeProducts),
    'Product catalog price filters include inclusive bounds, use sale prices, and keep count queries consistent with result pages'
);

$invalidSaleSearchToken = 'BADSALE' . strtoupper(bin2hex(random_bytes(4)));
$invalidSaleIds = [];
$invalidSaleIds[] = $productService->saveProduct([
    'name' => 'Invalid zero sale ' . $invalidSaleSearchToken,
    'price' => 500,
    'sku' => 'INV-ZERO-' . $invalidSaleSearchToken,
    'stock_quantity' => 1,
    'status' => ContentStatus::PUBLISHED->value,
]);
$invalidSaleIds[] = $productService->saveProduct([
    'name' => 'Invalid high sale ' . $invalidSaleSearchToken,
    'price' => 600,
    'sku' => 'INV-HIGH-' . $invalidSaleSearchToken,
    'stock_quantity' => 1,
    'status' => ContentStatus::PUBLISHED->value,
]);
$productPersistence = $container->get(PDO::class);
$setInvalidSale = $productPersistence->prepare('UPDATE products SET sale_price = ? WHERE id = ?');
$setInvalidSale->execute([0, $invalidSaleIds[0]]);
$setInvalidSale->execute([700, $invalidSaleIds[1]]);
$invalidSaleAscending = $productService->listProducts(ContentStatus::PUBLISHED, null, $invalidSaleSearchToken, null, null, 0, ProductSort::PRICE_ASC);
$invalidSaleDescending = $productService->listProducts(ContentStatus::PUBLISHED, null, $invalidSaleSearchToken, null, null, 0, ProductSort::PRICE_DESC);
$invalidSalePriceRange = $productService->listProducts(ContentStatus::PUBLISHED, null, $invalidSaleSearchToken, null, null, 0, ProductSort::PRICE_ASC, 500, 600);
assertTrue(
    array_map(static fn($product): int => (int) $product->id, $invalidSaleAscending) === $invalidSaleIds
        && array_map(static fn($product): int => (int) $product->id, $invalidSaleDescending) === array_reverse($invalidSaleIds)
        && array_map(static fn($product): int => (int) $product->id, $invalidSalePriceRange) === $invalidSaleIds
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $invalidSaleSearchToken, null, 500, 600) === 2
        && $productService->getById($invalidSaleIds[0])?->effectivePrice() === 500.0
        && $productService->getById($invalidSaleIds[1])?->effectivePrice() === 600.0,
    'Product catalog filtering and sorting ignore invalid stored sale prices just like Product::effectivePrice()'
);

$stockSearchToken = 'STOCK' . strtoupper(bin2hex(random_bytes(4)));
$inStockId = $productService->saveProduct([
    'name' => 'Stock filter available ' . $stockSearchToken,
    'price' => 80,
    'sku' => 'STOCK-IN-' . $stockSearchToken,
    'stock_quantity' => 3,
    'status' => ContentStatus::PUBLISHED->value,
]);
$outOfStockId = $productService->saveProduct([
    'name' => 'Stock filter unavailable ' . $stockSearchToken,
    'price' => 90,
    'sku' => 'STOCK-OUT-' . $stockSearchToken,
    'stock_quantity' => 0,
    'status' => ContentStatus::PUBLISHED->value,
]);
$inStockProducts = $productService->listProducts(ContentStatus::PUBLISHED, null, $stockSearchToken, null, 1, 0, ProductSort::NEWEST, null, null, true);
$outOfStockProducts = $productService->listProducts(ContentStatus::PUBLISHED, null, $stockSearchToken, null, null, 0, ProductSort::NEWEST, null, null, false);
assertTrue(
    count($inStockProducts) === 1
        && $inStockProducts[0]->id === $inStockId
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $stockSearchToken, null, null, null, true) === 1
        && count($outOfStockProducts) === 1
        && $outOfStockProducts[0]->id === $outOfStockId
        && $productService->countProducts(ContentStatus::PUBLISHED, null, $stockSearchToken, null, null, null, false) === count($outOfStockProducts),
    'Product stock filters select in-stock and out-of-stock rows consistently in paged listings and count queries'
);
foreach ($sortingIds as $sortingId) {
    $productService->deletePermanently($sortingId);
}
foreach ($invalidSaleIds as $invalidSaleId) {
    $productService->deletePermanently($invalidSaleId);
}
$productService->deletePermanently($inStockId);
$productService->deletePermanently($outOfStockId);

// 3. Verify Module Toggle & Dependency Rules (Hexagonal Feature Flags)
assertTrue($moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module is initially enabled');
assertTrue($moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module is initially enabled');

// Disable PRODUCTS -> should automatically disable ORDERS (dependent module)
$moduleManager->setModuleState(ModuleKey::PRODUCTS, false);
assertTrue(!$moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module is disabled');
assertTrue(!$moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module automatically disabled when PRODUCTS is disabled');

// Enable ORDERS -> should automatically re-enable PRODUCTS (parent dependency)
$moduleManager->setModuleState(ModuleKey::ORDERS, true);
assertTrue($moduleManager->isEnabled(ModuleKey::ORDERS), 'ORDERS module is re-enabled');
assertTrue($moduleManager->isEnabled(ModuleKey::PRODUCTS), 'PRODUCTS module automatically re-enabled when ORDERS is enabled');

// 4. Verify Creating a Post with Vietnamese Slug & Tags
$slugTestSuffix = bin2hex(random_bytes(4));
$newPostId = $postService->savePost([
    'title' => 'Kiểm thử tạo Bài viết tự động bằng Tiếng Việt ' . $slugTestSuffix,
    'excerpt' => 'Tóm tắt bài viết kiểm thử',
    'content' => '<p>Nội dung bài viết kiểm thử Hexagonal Architecture.</p>',
    'status' => ContentStatus::PUBLISHED->value,
    'tags' => 'Testing, Hexagonal, PHP',
], 1);
$createdPost = $postService->getById($newPostId);
assertTrue($createdPost !== null && $createdPost->slug === 'kiem-thu-tao-bai-viet-tu-dong-bang-tieng-viet-' . $slugTestSuffix, 'Vietnamese slug generated accurately for Post');
assertTrue(in_array('Hexagonal', $createdPost->tags, true), 'Tags synced for Post');
$postService->deletePermanently($newPostId);

// 5. Verify Creating a Product & Cart Checkout Flow
$newProductId = $productService->saveProduct([
    'name' => 'Chuột Không Dây Logitech MX Master 3S',
    'price' => 2490000,
    'sale_price' => 2190000,
    'sku' => 'LOGI-MX3S',
    'stock_quantity' => 10,
    'status' => ContentStatus::PUBLISHED->value,
    'tags' => 'Mouse, Logitech',
]);
$createdProd = $productService->getById($newProductId);
assertTrue($createdProd !== null && $createdProd->isOnSale() && $createdProd->effectivePrice() === 2190000.0, 'Product created with sale price calculation');

$cart = $container->get(CartPort::class);
$cart->clear();
$cartService = $container->get(CartService::class);
$cartService->addItem((string) $newProductId, '2');
assertTrue($cart->getItems()[$newProductId] === 2, 'Cart service accepts a valid published product quantity');

$addOverStockRejected = false;
try {
    $cartService->addItem($newProductId, '9');
} catch (RuntimeException) {
    $addOverStockRejected = true;
}
assertTrue($addOverStockRejected && $cart->getItems()[$newProductId] === 2, 'Adding a quantity above available stock is rejected without changing the cart');

$updateOverStockRejected = false;
try {
    $cartService->updateItems([$newProductId => '11']);
} catch (RuntimeException) {
    $updateOverStockRejected = true;
}
assertTrue($updateOverStockRejected && $cart->getItems()[$newProductId] === 2, 'Updating a cart quantity above available stock is rejected');

$partialUpdateRejected = false;
try {
    $cartService->updateItems([$newProductId => '4', 999999 => '1']);
} catch (RuntimeException) {
    $partialUpdateRejected = true;
}
assertTrue($partialUpdateRejected && $cart->getItems()[$newProductId] === 2, 'Invalid multi-item cart update leaves every session quantity unchanged');

$malformedQuantityRejected = false;
try {
    $cartService->addItem($newProductId, '2items');
} catch (RuntimeException) {
    $malformedQuantityRejected = true;
}
assertTrue($malformedQuantityRejected && $cart->getItems()[$newProductId] === 2, 'Cart service rejects malformed numeric input instead of coercing it');

$draftProductId = $productService->saveProduct([
    'name' => 'Sản phẩm nháp giỏ hàng ' . bin2hex(random_bytes(3)),
    'price' => 1000,
    'sku' => 'DRAFT-' . strtoupper(bin2hex(random_bytes(3))),
    'stock_quantity' => 5,
    'status' => ContentStatus::DRAFT->value,
]);
$draftProductRejected = false;
try {
    $cartService->addItem($draftProductId, 1);
} catch (RuntimeException) {
    $draftProductRejected = true;
}
assertTrue($draftProductRejected && !isset($cart->getItems()[$draftProductId]), 'Cart service rejects products that are not published');
$productService->deletePermanently($draftProductId);

$orderService = $container->get(OrderService::class);
$stockBeforeInvalidCheckout = $productService->getById($newProductId)?->stockQuantity;
$invalidCheckoutInputs = [
    ['customer_name' => 'Khách', 'customer_email' => 'not-an-email', 'customer_phone' => '0909000111', 'customer_address' => 'Địa chỉ'],
    ['customer_name' => '   ', 'customer_email' => 'buyer@example.com', 'customer_phone' => '0909000111', 'customer_address' => 'Địa chỉ'],
    ['customer_name' => 'Khách', 'customer_email' => 'buyer@example.com', 'customer_phone' => '123', 'customer_address' => 'Địa chỉ'],
    ['customer_name' => 'Khách', 'customer_email' => 'buyer@example.com', 'customer_phone' => '0909000111', 'customer_address' => str_repeat('a', 501)],
];
$invalidCheckoutRejected = true;
foreach ($invalidCheckoutInputs as $invalidCheckoutInput) {
    try {
        $orderService->checkout($invalidCheckoutInput);
        $invalidCheckoutRejected = false;
        break;
    } catch (RuntimeException) {
    }
}
assertTrue(
    $invalidCheckoutRejected
        && $cart->getItems()[$newProductId] === 2
        && $productService->getById($newProductId)?->stockQuantity === $stockBeforeInvalidCheckout,
    'Checkout rejects invalid customer name, email, phone, and oversized address before changing cart or stock'
);
$staleCartProductId = $productService->saveProduct([
    'name' => 'Sản phẩm chuyển nháp khi đang trong giỏ ' . bin2hex(random_bytes(3)),
    'price' => 25000,
    'sku' => 'STALE-' . strtoupper(bin2hex(random_bytes(3))),
    'stock_quantity' => 4,
    'status' => ContentStatus::PUBLISHED->value,
]);
$cartService->addItem($staleCartProductId, 1);
$productService->changeStatus($staleCartProductId, ContentStatus::DRAFT);
$stockBeforeStaleCartCheckout = $productService->getById($newProductId)?->stockQuantity;
$staleProductStockBeforeCheckout = $productService->getById($staleCartProductId)?->stockQuantity;
$ordersBeforeStaleCartCheckout = $orderService->countOrders();
$staleCartCheckoutRejected = false;
try {
    $orderService->checkout([
        'customer_name' => 'Khách Hàng',
        'customer_email' => 'buyer@example.com',
        'customer_phone' => '0909000111',
        'customer_address' => '123 Đường Lê Lợi, Quận 1',
    ]);
} catch (RuntimeException) {
    $staleCartCheckoutRejected = true;
}
assertTrue(
    $staleCartCheckoutRejected
        && $cart->getItems()[$newProductId] === 2
        && $cart->getItems()[$staleCartProductId] === 1
        && $productService->getById($newProductId)?->stockQuantity === $stockBeforeStaleCartCheckout
        && $productService->getById($staleCartProductId)?->stockQuantity === $staleProductStockBeforeCheckout
        && $orderService->countOrders() === $ordersBeforeStaleCartCheckout,
    'Checkout rejects a product unpublished after it entered the cart and rolls back earlier stock decrements'
);
$cart->remove($staleCartProductId);
$productService->deletePermanently($staleCartProductId);
$orderSearchMarker = 'order-search-' . bin2hex(random_bytes(4));
$order = $orderService->checkout([
    'customer_name' => 'Khách Hàng %_ ' . $orderSearchMarker,
    'customer_email' => $orderSearchMarker . '@example.com',
    'customer_phone' => '0909000111',
    'customer_address' => '123 Đường Lê Lợi, Quận 1',
    'note' => 'Giao nhanh',
]);

assertTrue($order->totalAmount === 4380000.0, 'Order total calculated accurately from sale price * quantity');
assertTrue(
    preg_match('/^ORD-[A-F0-9]{24}$/', $order->orderCode) === 1,
    'Checkout generates a 24-character hexadecimal order-code suffix from random bytes'
);
$orderPage = $orderService->listOrders(null, 1, 0);
assertTrue(count($orderPage) === 1 && $orderPage[0]->id === $order->id, 'Order listing supports a bounded first page with newest orders first');
assertTrue($orderService->countOrders() >= 1 && $orderService->countOrders(\App\Domain\Enum\OrderStatus::PENDING) >= 1, 'Order counts respect an optional status filter');
$searchedOrders = $orderService->listOrders(\App\Domain\Enum\OrderStatus::PENDING, 20, 0, '%_ ' . $orderSearchMarker);
$orderQueryService = $container->get(OrderQueryService::class);
assertTrue(
    count($searchedOrders) === 1
        && $searchedOrders[0]->id === $order->id
        && $orderService->countOrders(\App\Domain\Enum\OrderStatus::PENDING, '%_ ' . $orderSearchMarker) === 1,
    'Order search matches a literal substring across customer fields and applies the same status filter to listing and count'
);
assertTrue(
    $orderQueryService->countOrders(\App\Domain\Enum\OrderStatus::PENDING, ' ' . $orderSearchMarker . ' ') === 1
        && $orderQueryService->listOrders(\App\Domain\Enum\OrderStatus::PENDING, 20, 0, $orderSearchMarker)[0]->id === $order->id
        && $orderService->countOrders(\App\Domain\Enum\OrderStatus::PENDING, $orderSearchMarker) === 1,
    'Order query use case preserves search normalization and the legacy OrderService query API'
);
assertTrue(
    $orderService->listOrders(null, 20, 0, $orderSearchMarker . '@example.com')[0]->id === $order->id
        && $orderService->listOrders(null, 20, 0, '  ' . $orderSearchMarker . '  ')[0]->id === $order->id,
    'Order search supports email lookup and trims surrounding whitespace'
);
$updatedProd = $productService->getById($newProductId);
assertTrue($updatedProd !== null && $updatedProd->stockQuantity === 8, 'Product stock decremented after checkout');
$database = $container->get(\PDO::class);
$database->exec('CREATE TRIGGER reject_order_stock_restore BEFORE UPDATE OF stock_quantity ON products WHEN OLD.id = ' . (int) $newProductId . ' BEGIN SELECT RAISE(ABORT, "simulated stock restore failure"); END;');
$stockRestoreRolledBack = false;
try {
    $orderService->updateOrderStatus((int) $order->id, \App\Domain\Enum\OrderStatus::CANCELLED);
} catch (\PDOException) {
    $stockRestoreRolledBack = true;
}
$database->exec('DROP TRIGGER reject_order_stock_restore');
$orderAfterRestoreFailure = $orderService->getOrderById((int) $order->id);
assertTrue(
    $stockRestoreRolledBack
        && $productService->getById($newProductId)?->stockQuantity === 8
        && $orderAfterRestoreFailure?->status === \App\Domain\Enum\OrderStatus::PENDING,
    'Order cancellation rolls back inventory and status together when restoring stock fails'
);
$orderService->updateOrderStatus((int) $order->id, \App\Domain\Enum\OrderStatus::CANCELLED);
$stockAfterCancellation = $productService->getById($newProductId)?->stockQuantity;
$orderService->updateOrderStatus((int) $order->id, \App\Domain\Enum\OrderStatus::CANCELLED);
$reopeningCancelledOrderRejected = false;
try {
    $orderService->updateOrderStatus((int) $order->id, \App\Domain\Enum\OrderStatus::PENDING);
} catch (RuntimeException) {
    $reopeningCancelledOrderRejected = true;
}
assertTrue(
    $stockAfterCancellation === 10
        && $productService->getById($newProductId)?->stockQuantity === 10
        && $orderService->getOrderById((int) $order->id)?->status === \App\Domain\Enum\OrderStatus::CANCELLED
        && $reopeningCancelledOrderRejected,
    'Cancelling an order restores its stock once and prevents reopening from enabling duplicate restoration'
);
$productService->deletePermanently($newProductId);

// AutoTopic reviews stylesheets with motion declarations but no reduced-motion query.
$reducedMotionRoot = sys_get_temp_dir() . '/tuzygent-topic-reduced-motion-' . bin2hex(random_bytes(6));
mkdir($reducedMotionRoot . '/public/assets/css', 0775, true);
file_put_contents($reducedMotionRoot . '/public/assets/css/motion.css', <<<'CSS'
/* transition: opacity 1s; */
.button { transition: transform 200ms; }
CSS);
file_put_contents($reducedMotionRoot . '/public/assets/css/handled.css', <<<'CSS'
.button { transition: transform 200ms; }
@media (prefers-reduced-motion: reduce) { .button { transition: none; } }
CSS);
file_put_contents($reducedMotionRoot . '/public/assets/css/no-motion.css', <<<'CSS'
/* .button { animation: pulse 2s infinite; } */
.button { color: blue; }
CSS);
$reducedMotionTarget = 'CmsReducedMotion' . substr(sha1('public/assets/css/motion.css'), 0, 12);
$reducedMotionTopics = (new CmsTopicGenerator($reducedMotionRoot))->generate();
$reducedMotionTopic = array_values(array_filter(
    $reducedMotionTopics,
    static fn(array $topic): bool => $topic['target_name'] === $reducedMotionTarget
))[0] ?? null;
assertTrue(
    $reducedMotionTopic !== null
        && $reducedMotionTopic['priority'] === 'P2'
        && str_contains($reducedMotionTopic['specification'], 'không chứng minh lỗi WCAG'),
    'AutoTopic reviews active CSS motion declarations without overstating WCAG impact'
);
assertTrue(
    !array_filter($reducedMotionTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsReducedMotion' . substr(sha1('public/assets/css/handled.css'), 0, 12))
        && !array_filter($reducedMotionTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsReducedMotion' . substr(sha1('public/assets/css/no-motion.css'), 0, 12)),
    'AutoTopic accepts reduced-motion stylesheets and ignores motion properties in CSS comments'
);
assertTrue(
    !array_filter(
        (new CmsTopicGenerator($reducedMotionRoot))->generate([strtolower($reducedMotionTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $reducedMotionTarget
    ),
    'AutoTopic suppresses a previously discovered reduced-motion review target'
);
foreach (glob($reducedMotionRoot . '/public/assets/css/*.css') ?: [] as $reducedMotionFile) {
    unlink($reducedMotionFile);
}
rmdir($reducedMotionRoot . '/public/assets/css');
rmdir($reducedMotionRoot . '/public/assets');
rmdir($reducedMotionRoot . '/public');
rmdir($reducedMotionRoot);

$adminStylesheetPath = dirname(__DIR__) . '/public/assets/css/admin.css';
$adminStylesheet = file_get_contents($adminStylesheetPath);
$adminLayout = file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/layouts/admin.php');
$adminLogin = file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/login.php');
assertTrue(
    is_string($adminStylesheet)
        && preg_match('/@media\s*\(prefers-reduced-motion\s*:\s*reduce\s*\)\s*\{\s*\.wp-slider:before\s*\{\s*transition:\s*none;\s*\}\s*\}/i', $adminStylesheet) === 1,
    'Admin stylesheet removes the moving toggle thumb transition when reduced motion is preferred'
);
assertTrue(
    is_string($adminLayout)
        && str_contains($adminLayout, 'href="/assets/css/admin.css"')
        && is_string($adminLogin)
        && str_contains($adminLogin, 'href="/assets/css/admin.css"'),
    'Reduced-motion styles are present in the admin layout and login stylesheet'
);
assertTrue(
    is_string($adminLogin)
        && preg_match('/<input\b(?=[^>]*\bid="password")(?=[^>]*\btype="password")(?=[^>]*\bautocomplete="current-password")[^>]*>/', $adminLogin) === 1,
    'Admin login password input identifies the current-password autocomplete purpose'
);

// AutoTopic reviews focus rules that remove outlines without a visible replacement.
$focusIndicatorRoot = sys_get_temp_dir() . '/tuzygent-topic-focus-indicator-' . bin2hex(random_bytes(6));
mkdir($focusIndicatorRoot . '/public/assets/css', 0775, true);
file_put_contents($focusIndicatorRoot . '/public/assets/css/problem.css', <<<'CSS'
/* button:focus { outline: none; } */
button:focus-visible { outline: 0; }
CSS);
file_put_contents($focusIndicatorRoot . '/public/assets/css/handled.css', <<<'CSS'
input:focus { outline: none; box-shadow: 0 0 0 2px blue; }
button:focus { outline-style: none; border-color: blue; }
CSS);
file_put_contents($focusIndicatorRoot . '/public/assets/css/inert.css', <<<'CSS'
.sample { content: 'button:focus { outline: none; }'; }
a { outline: none; }
CSS);
file_put_contents($focusIndicatorRoot . '/public/assets/css/editor-problem.css', <<<'CSS'
.wp-visual-editor { border: 1px solid gray; outline: none; }
CSS);
file_put_contents($focusIndicatorRoot . '/public/assets/css/editor-handled.css', <<<'CSS'
.wp-visual-editor { outline: none; }
.wp-visual-editor:focus-visible { outline: 2px solid blue; }
CSS);
$focusIndicatorTarget = 'CmsFocusIndicator' . substr(sha1('public/assets/css/problem.css'), 0, 12);
$editorFocusTarget = 'CmsFocusIndicator' . substr(sha1('public/assets/css/editor-problem.css'), 0, 12);
$handledEditorFocusTarget = 'CmsFocusIndicator' . substr(sha1('public/assets/css/editor-handled.css'), 0, 12);
$focusIndicatorGenerator = new CmsTopicGenerator($focusIndicatorRoot);
$focusIndicatorTopics = $focusIndicatorGenerator->generate();
$focusIndicatorTopic = array_values(array_filter(
    $focusIndicatorTopics,
    static fn(array $topic): bool => $topic['target_name'] === $focusIndicatorTarget
))[0] ?? null;
assertTrue(
    $focusIndicatorTopic !== null
        && $focusIndicatorTopic['priority'] === 'P1'
        && str_contains($focusIndicatorTopic['specification'], 'WCAG 2.2 SC 2.4.7')
        && str_contains($focusIndicatorTopic['specification'], 'không phải kết luận WCAG'),
    'AutoTopic flags focus rules that remove outlines without claiming a WCAG failure'
);
assertTrue(
    !array_filter($focusIndicatorTopics, static fn(array $topic): bool => in_array($topic['target_name'], [
        'CmsFocusIndicator' . substr(sha1('public/assets/css/handled.css'), 0, 12),
        'CmsFocusIndicator' . substr(sha1('public/assets/css/inert.css'), 0, 12),
        $handledEditorFocusTarget,
    ], true)),
    'AutoTopic accepts same-rule and editor-specific focus replacements while ignoring comments, strings, and non-focus rules'
);
assertTrue(
    count(array_filter($focusIndicatorTopics, static fn(array $topic): bool => $topic['target_name'] === $editorFocusTarget)) === 1,
    'AutoTopic detects editor selectors that remove the base focus outline without a matching focus indicator'
);
assertTrue(
    !array_filter(
        $focusIndicatorGenerator->generate([strtolower($focusIndicatorTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $focusIndicatorTarget
    ),
    'AutoTopic suppresses a previously discovered focus-indicator target'
);
foreach (glob($focusIndicatorRoot . '/public/assets/css/*.css') ?: [] as $focusIndicatorFile) {
    unlink($focusIndicatorFile);
}
rmdir($focusIndicatorRoot . '/public/assets/css');
rmdir($focusIndicatorRoot . '/public/assets');
rmdir($focusIndicatorRoot . '/public');
rmdir($focusIndicatorRoot);

// AutoTopic reviews skipped heading ranks in presentation templates as a source-level hint.
$pageLanguageRoot = sys_get_temp_dir() . '/tuzygent-topic-page-language-' . bin2hex(random_bytes(6));
mkdir($pageLanguageRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($pageLanguageRoot . '/src/Presentation/Views/problem.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html>
<html>
<html lang="">
<html lang>
<!-- <html> comment example -->
<?php $example = '<html lang="">'; ?>
PHP);
file_put_contents($pageLanguageRoot . '/src/Presentation/Views/valid.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<html lang="vi">
<html LANG='en-US'>
<html lang="<?= htmlspecialchars($locale) ?>">
<main>Fragment template without a document root.</main>
PHP);
$pageLanguageTarget = 'CmsPageLanguage' . substr(sha1('src/Presentation/Views/problem.php'), 0, 12);
$pageLanguageGenerator = new CmsTopicGenerator($pageLanguageRoot);
$pageLanguageTopics = $pageLanguageGenerator->generate();
$pageLanguageTopic = array_values(array_filter(
    $pageLanguageTopics,
    static fn(array $topic): bool => $topic['target_name'] === $pageLanguageTarget
))[0] ?? null;
assertTrue(
    $pageLanguageTopic !== null
        && $pageLanguageTopic['priority'] === 'P1'
        && str_contains($pageLanguageTopic['specification'], 'WCAG 2.2 SC 3.1.1')
        && str_contains($pageLanguageTopic['specification'], 'hay coi heuristic source là kết luận vi phạm WCAG'),
    'AutoTopic flags missing, empty, and valueless document language attributes as a source-level review'
);
assertTrue(
    count(array_filter($pageLanguageTopics, static fn(array $topic): bool => str_starts_with($topic['target_name'], 'CmsPageLanguage'))) === 1,
    'AutoTopic accepts literal and dynamic language values and ignores comments, PHP strings, and fragment templates'
);
assertTrue(
    (new CmsTopicGenerator($pageLanguageRoot))->generate([strtolower($pageLanguageTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered page-language target'
);
foreach (glob($pageLanguageRoot . '/src/Presentation/Views/*.php') ?: [] as $pageLanguageFile) {
    unlink($pageLanguageFile);
}
rmdir($pageLanguageRoot . '/src/Presentation/Views');
rmdir($pageLanguageRoot . '/src/Presentation');
rmdir($pageLanguageRoot . '/src');
rmdir($pageLanguageRoot);

// AutoTopic reviews HTML documents without a non-empty meta description.
$metaDescriptionRoot = sys_get_temp_dir() . '/tuzygent-topic-meta-description-' . bin2hex(random_bytes(6));
mkdir($metaDescriptionRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($metaDescriptionRoot . '/src/Presentation/Views/problem.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="vi"><head>
<!-- <meta name="description" content="Comment example"> -->
<?php $example = '<meta name="description" content="PHP string example">'; ?>
<meta content="   " name="description">
</head><body></body></html>
PHP);
file_put_contents($metaDescriptionRoot . '/src/Presentation/Views/valid.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<html lang="vi"><head><meta content="<?= htmlspecialchars($description) ?>" NAME='DESCRIPTION'></head></html>
PHP);
file_put_contents($metaDescriptionRoot . '/src/Presentation/Views/fragment.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<section><p>Fragment without its own document head.</p></section>
PHP);
$metaDescriptionTarget = 'CmsMetaDescription' . substr(sha1('src/Presentation/Views/problem.php'), 0, 12);
$metaDescriptionTopics = (new CmsTopicGenerator($metaDescriptionRoot))->generate();
$metaDescriptionTopic = array_values(array_filter(
    $metaDescriptionTopics,
    static fn(array $topic): bool => $topic['target_name'] === $metaDescriptionTarget
))[0] ?? null;
assertTrue(
    $metaDescriptionTopic !== null
        && $metaDescriptionTopic['priority'] === 'P2'
        && str_contains($metaDescriptionTopic['specification'], 'Google Search Central')
        && str_contains($metaDescriptionTopic['specification'], 'không bảo đảm Google sẽ dùng mô tả'),
    'AutoTopic flags a document with missing or empty meta description as a bounded SEO review suggestion'
);
assertTrue(
    count(array_filter($metaDescriptionTopics, static fn(array $topic): bool => str_starts_with($topic['target_name'], 'CmsMetaDescription'))) === 1,
    'AutoTopic accepts non-empty dynamic descriptions and ignores comments, PHP strings, and document fragments'
);
assertTrue(
    (new CmsTopicGenerator($metaDescriptionRoot))->generate([strtolower($metaDescriptionTarget) => true]) === [],
    'AutoTopic suppresses a previously discovered meta-description target'
);
foreach (glob($metaDescriptionRoot . '/src/Presentation/Views/*.php') ?: [] as $metaDescriptionFile) {
    unlink($metaDescriptionFile);
}
rmdir($metaDescriptionRoot . '/src/Presentation/Views');
rmdir($metaDescriptionRoot . '/src/Presentation');
rmdir($metaDescriptionRoot . '/src');
rmdir($metaDescriptionRoot);

// AutoTopic reviews skipped heading ranks in presentation templates as a source-level hint.
$ariaHiddenFocusRoot = sys_get_temp_dir() . '/tuzygent-topic-aria-hidden-focus-' . bin2hex(random_bytes(6));
mkdir($ariaHiddenFocusRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($ariaHiddenFocusRoot . '/src/Presentation/Views/problem.php', <<<'PHP'
<button aria-hidden="true">Hidden action</button>
<div aria-hidden='true' tabindex="0">Hidden focus stop</div>
<a href="/hidden" aria-hidden="true">Hidden link</a>
PHP);
file_put_contents($ariaHiddenFocusRoot . '/src/Presentation/Views/valid.php', <<<'PHP'
<span aria-hidden="true">Decorative icon</span>
<button aria-hidden="false">Visible action</button>
<button aria-hidden="true" disabled>Disabled action</button>
<input type="hidden" aria-hidden="true">
<a aria-hidden="true">No href</a>
<!-- <button aria-hidden="true">Comment example</button> -->
<?php $example = '<button aria-hidden="true">String example</button>'; ?>
PHP);
$ariaHiddenFocusTarget = 'CmsAriaHiddenFocus' . substr(sha1('src/Presentation/Views/problem.php'), 0, 12);
$ariaHiddenFocusGenerator = new CmsTopicGenerator($ariaHiddenFocusRoot);
$ariaHiddenFocusTopics = $ariaHiddenFocusGenerator->generate();
$ariaHiddenFocusTopic = array_values(array_filter(
    $ariaHiddenFocusTopics,
    static fn(array $topic): bool => $topic['target_name'] === $ariaHiddenFocusTarget
))[0] ?? null;
assertTrue(
    $ariaHiddenFocusTopic !== null
        && $ariaHiddenFocusTopic['priority'] === 'P1'
        && str_contains($ariaHiddenFocusTopic['specification'], 'không phân tích focusable descendant')
        && str_contains($ariaHiddenFocusTopic['specification'], 'W3C'),
    'AutoTopic reports literal aria-hidden focusable controls as a bounded accessibility review'
);
assertTrue(
    !array_filter($ariaHiddenFocusTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsAriaHiddenFocus' . substr(sha1('src/Presentation/Views/valid.php'), 0, 12)),
    'AutoTopic accepts nonfocusable, disabled and false aria-hidden markup, ignoring comments and strings'
);
assertTrue(
    !array_filter(
        $ariaHiddenFocusGenerator->generate([strtolower($ariaHiddenFocusTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $ariaHiddenFocusTarget
    ),
    'AutoTopic suppresses a previously discovered aria-hidden focus target'
);
foreach (glob($ariaHiddenFocusRoot . '/src/Presentation/Views/*.php') ?: [] as $ariaHiddenFocusFile) {
    unlink($ariaHiddenFocusFile);
}
rmdir($ariaHiddenFocusRoot . '/src/Presentation/Views');
rmdir($ariaHiddenFocusRoot . '/src/Presentation');
rmdir($ariaHiddenFocusRoot . '/src');
rmdir($ariaHiddenFocusRoot);

// AutoTopic reviews skipped heading ranks in presentation templates as a source-level hint.
$headingRankRoot = sys_get_temp_dir() . '/tuzygent-topic-heading-ranks-' . bin2hex(random_bytes(6));
mkdir($headingRankRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($headingRankRoot . '/src/Presentation/Views/skipped.php', <<<'PHP'
<h1>Page title</h1>
<h3>Subsection</h3>
PHP);
file_put_contents($headingRankRoot . '/src/Presentation/Views/nested.php', <<<'PHP'
<h1>Page title</h1>
<h2>Section</h2>
<h3>Subsection</h3>
<h2>Another section</h2>
PHP);
file_put_contents($headingRankRoot . '/src/Presentation/Views/commented.php', <<<'PHP'
<!-- <h1>Old</h1><h4>Example only</h4> -->
<h2>Section</h2>
PHP);
file_put_contents($headingRankRoot . '/src/Presentation/Views/string.php', <<<'PHP'
<?php $example = '<h1>Old</h1><h4>Example only</h4>'; ?>
<h2>Section</h2>
PHP);
$headingRankTarget = 'CmsHeadingRank' . substr(sha1('src/Presentation/Views/skipped.php'), 0, 12);
$headingRankGenerator = new CmsTopicGenerator($headingRankRoot);
$headingRankTopics = $headingRankGenerator->generate();
$headingRankTopic = array_values(array_filter(
    $headingRankTopics,
    static fn(array $topic): bool => $topic['target_name'] === $headingRankTarget
))[0] ?? null;
assertTrue(
    $headingRankTopic !== null
        && $headingRankTopic['priority'] === 'P2'
        && str_contains($headingRankTopic['specification'], 'không coi tín hiệu này là kết luận vi phạm WCAG'),
    'AutoTopic flags deeper skipped heading ranks as a review suggestion with limitations'
);
assertTrue(
    !array_filter($headingRankTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsHeadingRank' . substr(sha1('src/Presentation/Views/nested.php'), 0, 12))
        && !array_filter($headingRankTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsHeadingRank' . substr(sha1('src/Presentation/Views/commented.php'), 0, 12))
        && !array_filter($headingRankTopics, static fn(array $topic): bool => $topic['target_name'] === 'CmsHeadingRank' . substr(sha1('src/Presentation/Views/string.php'), 0, 12)),
    'AutoTopic accepts consecutive heading ranks and ignores comments and PHP string examples'
);
assertTrue(
    !array_filter(
        $headingRankGenerator->generate([strtolower($headingRankTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $headingRankTarget
    ),
    'AutoTopic suppresses a previously discovered heading-rank review target'
);
foreach (glob($headingRankRoot . '/src/Presentation/Views/*.php') ?: [] as $headingRankFile) {
    unlink($headingRankFile);
}
rmdir($headingRankRoot . '/src/Presentation/Views');
rmdir($headingRankRoot . '/src/Presentation');
rmdir($headingRankRoot . '/src');
rmdir($headingRankRoot);

// AutoTopic reviews direct request-superglobal output in PHP templates.
$genericLinkRoot = sys_get_temp_dir() . '/tuzygent-topic-generic-link-' . bin2hex(random_bytes(6));
mkdir($genericLinkRoot . '/src/Presentation/Views', 0775, true);
file_put_contents($genericLinkRoot . '/src/Presentation/Views/links.php', <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- <a href="/fake">click here</a> -->
<?php $example = '<a href="/fake">read more</a>'; ?>
<a href="/article">Xem   thêm</a>
<a href="/article" aria-label="Đọc bài viết về sản phẩm">xem thêm</a>
<a href="/article">Đọc bài viết về sản phẩm</a>
<a href="/article"><?= $dynamicLabel ?></a>
PHP);
$genericLinkGenerator = new CmsTopicGenerator($genericLinkRoot);
$genericLinkTarget = 'CmsLinkPurpose' . substr(sha1('src/Presentation/Views/links.php'), 0, 12);
$genericLinkTopics = array_values(array_filter(
    $genericLinkGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $genericLinkTarget
));
assertTrue(
    count($genericLinkTopics) === 1
        && $genericLinkTopics[0]['priority'] === 'P2'
        && str_contains($genericLinkTopics[0]['specification'], 'dòng: 4')
        && !str_contains($genericLinkTopics[0]['specification'], 'dòng: 2, 3, 4, 5, 6, 7'),
    'AutoTopic reviews literal generic link labels while ignoring comments, PHP strings, explicit names, descriptive and dynamic links'
);
assertTrue(
    !array_filter(
        $genericLinkGenerator->generate([strtolower($genericLinkTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $genericLinkTarget
    ),
    'AutoTopic suppresses a previously discovered generic-link review target'
);
foreach (glob($genericLinkRoot . '/src/Presentation/Views/*.php') ?: [] as $genericLinkFile) {
    unlink($genericLinkFile);
}
rmdir($genericLinkRoot . '/src/Presentation/Views');
rmdir($genericLinkRoot . '/src/Presentation');
rmdir($genericLinkRoot . '/src');
rmdir($genericLinkRoot);

// AutoTopic reviews direct request-superglobal output in PHP templates.
$directRequestRoot = sys_get_temp_dir() . '/tuzygent-topic-direct-request-' . bin2hex(random_bytes(6));
mkdir($directRequestRoot . '/src/Presentation/Views', 0775, true);
$directRequestFixture = $directRequestRoot . '/src/Presentation/Views/request.php';
file_put_contents($directRequestFixture, <<<'PHP'
<?php declare(strict_types=1); ?>
<!-- direct $_GET['comment'] example -->
<?php // echo $_POST['comment']; ?>
<?php $example = 'print $_REQUEST["comment"]'; ?>
<?= $_GET['query'] ?>
<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
<?php echo htmlspecialchars($safe) . $_REQUEST['tail']; ?>
PHP);
$directRequestGenerator = new CmsTopicGenerator($directRequestRoot);
$directRequestTarget = 'CmsDirectRequestOutput' . substr(sha1('src/Presentation/Views/request.php'), 0, 12);
$directRequestTopics = array_values(array_filter(
    $directRequestGenerator->generate(),
    static fn(array $topic): bool => $topic['target_name'] === $directRequestTarget
));
assertTrue(
    count($directRequestTopics) === 1
        && $directRequestTopics[0]['priority'] === 'P0'
        && str_contains($directRequestTopics[0]['specification'], 'dòng 5, 7')
        && !str_contains($directRequestTopics[0]['specification'], 'dòng 2, 3, 4, 6'),
    'AutoTopic finds direct unencoded request output while ignoring encoded references, comments, and PHP strings'
);
assertTrue(
    !array_filter(
        $directRequestGenerator->generate([strtolower($directRequestTarget) => true]),
        static fn(array $topic): bool => $topic['target_name'] === $directRequestTarget
    ),
    'AutoTopic suppresses a previously discovered direct request-output target'
);
foreach (glob($directRequestRoot . '/src/Presentation/Views/*.php') ?: [] as $directRequestFile) {
    unlink($directRequestFile);
}
rmdir($directRequestRoot . '/src/Presentation/Views');
rmdir($directRequestRoot . '/src/Presentation');
rmdir($directRequestRoot . '/src');
rmdir($directRequestRoot);

// 6. Verify Tuzygent Autonomous Code Harness (Snapshot + Scaffold + 3-Stage Gate + Rollback)
$publishedSitemapPost = new \App\Domain\Entity\Post(
    501, 'Sitemap & Search', 'sitemap & search', '', '', '', ContentStatus::PUBLISHED, null, null, 1, '', updatedAt: '2026-11-02 11:30:00'
);
$draftSitemapPost = new \App\Domain\Entity\Post(
    502, 'Private Draft', 'private-draft', '', '', '', ContentStatus::DRAFT, null, null, 1, '', updatedAt: '2026-11-03 11:30:00'
);
$publishedSitemapProduct = new \App\Domain\Entity\Product(
    503,
    'Sitemap Product',
    'sitemap-product',
    'SKU-503',
    '',
    '',
    10.0,
    null,
    1,
    '/uploads/featured.jpg',
    ['/uploads/featured.jpg', 'https://cdn.example/images/gallery.jpg?size=large&format=webp', 'javascript:alert(1)'],
    ContentStatus::PUBLISHED,
    null,
    null,
    updatedAt: 'invalid-date'
);
$draftSitemapProduct = new \App\Domain\Entity\Product(
    504, 'Private Product', 'private-product', 'SKU-504', '', '', 10.0, null, 1,
    '/uploads/private.jpg', [], ContentStatus::DRAFT, null, null
);
$sitemapXml = (new SitemapXmlBuilder('https://shop.example/cms/'))->build(
    [$publishedSitemapPost, $draftSitemapPost],
    [$publishedSitemapProduct, $draftSitemapProduct],
    true,
    true
);
assertTrue(
    str_contains($sitemapXml, '<loc>https://shop.example/cms/</loc>')
        && str_contains($sitemapXml, 'https://shop.example/cms/post/sitemap%20%26%20search')
        && str_contains($sitemapXml, '<lastmod>2026-11-02</lastmod>')
        && str_contains($sitemapXml, 'https://shop.example/cms/product/sitemap-product')
        && str_contains($sitemapXml, 'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"')
        && str_contains($sitemapXml, '<image:loc>https://shop.example/cms/uploads/featured.jpg</image:loc>')
        && str_contains($sitemapXml, '<image:loc>https://cdn.example/images/gallery.jpg?size=large&amp;format=webp</image:loc>')
        && substr_count($sitemapXml, '<image:image>') === 2
        && !str_contains($sitemapXml, 'private-draft')
        && !str_contains($sitemapXml, 'private-product')
        && !str_contains($sitemapXml, 'javascript:')
        && !str_contains($sitemapXml, 'private.jpg')
        && !str_contains($sitemapXml, '<lastmod>2026-11-08</lastmod>'),
    'Sitemap emits published pages and deduplicated HTTP(S) product images with escaped XML and persisted modification dates only'
);
$sitemapBuilder = new SitemapXmlBuilder('https://shop.example/cms/');
$sitemapIndex = $sitemapBuilder->buildIndex([
    $sitemapBuilder->sitemapUrlForPart(0),
    $sitemapBuilder->sitemapUrlForPart(1),
]);
assertTrue(
    str_starts_with($sitemapIndex, '<?xml version="1.0" encoding="UTF-8"?>')
        && str_contains($sitemapIndex, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        && str_contains($sitemapIndex, '<loc>https://shop.example/cms/sitemap.xml?part=0</loc>')
        && str_contains($sitemapIndex, '<loc>https://shop.example/cms/sitemap.xml?part=1</loc>')
        && str_ends_with($sitemapIndex, '</sitemapindex>'),
    'Large sitemap indexes point to absolute same-origin paged sitemap URLs'
);
$manySitemapImages = array_map(static fn(int $index): string => '/uploads/product-' . $index . '.jpg', range(0, 1000));
$imageLimitProduct = new \App\Domain\Entity\Product(
    505, 'Image Limit Product', 'image-limit-product', 'SKU-505', '', '', 10.0, null, 1,
    '', $manySitemapImages, ContentStatus::PUBLISHED, null, null
);
$imageLimitXml = (new SitemapXmlBuilder('https://shop.example'))->build([], [$imageLimitProduct], false, true);
assertTrue(
    substr_count($imageLimitXml, '<image:image>') === 1000
        && !str_contains($imageLimitXml, 'product-1000.jpg'),
    'Sitemap limits each page URL to the documented maximum of 1,000 image entries'
);
$escapedSitemapXml = (new SitemapXmlBuilder('https://shop.example/news&media'))->build([], [], false, false);
assertTrue(
    str_contains($escapedSitemapXml, '<loc>https://shop.example/news&amp;media/</loc>'),
    'Sitemap XML escapes configured URL values'
);

$legacySubscriberPdo = new PDO('sqlite::memory:');
$legacySubscriberPdo->exec('CREATE TABLE subscribers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
)');
$legacySubscriberPdo->exec("INSERT INTO subscribers (name, code, is_active, created_at) VALUES ('Legacy record', 'OLD', 1, '2025-01-01 00:00:00')");
$subscriberRepository = new \App\Infrastructure\Persistence\Sqlite\SqliteSubscriberRepository($legacySubscriberPdo);
$subscriberService = new \App\Application\Service\SubscriberService($subscriberRepository);
$legacySubscriber = $subscriberRepository->findById(1);
$subscriberCreated = $subscriberService->subscribe('  News@Example.com ');
$subscriberDuplicate = $subscriberService->subscribe('news@example.com');
$subscriberValidationRejected = false;
try {
    $subscriberService->subscribe('not-an-email');
} catch (InvalidArgumentException) {
    $subscriberValidationRejected = true;
}
assertTrue(
    $legacySubscriber?->name === 'Legacy record'
        && $legacySubscriber->email === ''
        && $subscriberCreated
        && !$subscriberDuplicate
        && $subscriberRepository->findByEmail('NEWS@example.com')?->email === 'news@example.com'
        && count($subscriberRepository->findAll()) === 2
        && $subscriberValidationRejected,
    'Newsletter signup migrates legacy subscriber storage, normalizes email addresses, prevents duplicates, and rejects invalid input'
);

$robotsTxt = (new RobotsTxtBuilder('https://shop.example/cms/'))->build();
assertTrue(
    $robotsTxt === "User-agent: *\nDisallow: /admin\nSitemap: https://shop.example/cms/sitemap.xml\n",
    'Robots.txt gives crawler guidance and links the configured absolute sitemap URL'
);
$robotsInjectionRejected = false;
try {
    (new RobotsTxtBuilder("https://shop.example\nDisallow: /"))->build();
} catch (InvalidArgumentException) {
    $robotsInjectionRejected = true;
}
assertTrue($robotsInjectionRejected, 'Robots.txt rejects control characters in configured URLs to prevent directive injection');

$harnessService = $container->get(\App\Application\Service\TuzygentHarnessService::class);
$agents = $harnessService->discoverAgents();
assertTrue(isset($agents['builtin-hexagonal']) && $agents['builtin-hexagonal']['available'] === true, 'Tuzygent Built-in Hexagonal Agent available');

$snapBefore = $harnessService->createSnapshot('test_suite');
$generatedFiles = $harnessService->scaffoldHexagonalVerticalSlice('Brand', 'Thương hiệu sản phẩm');
assertTrue(count($generatedFiles) === 4, 'Tuzygent scaffolded 4 Hexagonal vertical slice files (Entity, Port, SQLite Adapter, Service)');
assertTrue(is_file(dirname(__DIR__) . '/src/Domain/Entity/Brand.php'), 'Generated Brand Entity exists');

// Verify restored state via Rollback
$rolledBack = $harnessService->rollbackToSnapshot($snapBefore);
assertTrue($rolledBack && !is_file(dirname(__DIR__) . '/src/Domain/Entity/Brand.php'), 'Snapshot Rollback cleanly restored source tree and removed generated slice');

// 7. Verify Tuzygent Continuous Autonomous Mode State & Lifecycle
$prevContinuousState = $harnessService->getContinuousState();
$startedContinuous = $harnessService->startContinuousMode([
    'interval_seconds' => 5,
    'auto_discover' => true,
    'auto_heal_on_drift' => true,
    'spawn_daemon' => false,
]);
assertTrue($startedContinuous['enabled'] === true && $startedContinuous['status'] === 'running', 'Tuzygent Continuous Mode starts and persists running state');
$stoppedContinuous = $harnessService->stopContinuousMode(false);
assertTrue($stoppedContinuous['enabled'] === false && $stoppedContinuous['status'] === 'stopped', 'Tuzygent Continuous Mode stops cleanly');
$harnessService->saveContinuousState($prevContinuousState);

// Search pagination exposes direct page links while preserving the other result set's page.
$search = 'page & test';
$posts = [];
$products = [];
$postsEnabled = true;
$productsEnabled = true;
$postPage = 3;
$postTotalPages = 8;
$productPage = 2;
$productTotalPages = 5;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/frontend/search.php';
$searchView = (string) ob_get_clean();
assertTrue(
    str_contains($searchView, 'aria-label="Phân trang bài viết trong kết quả tìm kiếm"')
        && str_contains($searchView, 'href="/search?s=page+%26+test&amp;posts_page=2&amp;products_page=2"')
        && str_contains($searchView, 'href="/search?s=page+%26+test&amp;posts_page=3&amp;products_page=1"')
        && substr_count($searchView, 'aria-current="page"') === 2,
    'Search page-number navigation preserves both independent page values and marks one current page per result set'
);

// Login page must not render a password value or advertise a default credential.
$error = null;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/login.php';
$loginMarkup = (string) ob_get_clean();
assertTrue(
    preg_match('/<input\b(?=[^>]*\btype="password")[^>]*\bvalue\s*=/i', $loginMarkup) !== 1
        && preg_match('/<input\b(?=[^>]*\bid="password")(?=[^>]*\bname="password")(?=[^>]*\btype="password")(?=[^>]*\bautocomplete="current-password")(?=[^>]*\brequired\b)[^>]*>/', $loginMarkup) === 1
        && !str_contains($loginMarkup, 'wp-login-hint'),
    'Admin login renders an empty password field with current-password autocomplete and no default credential guidance'
);

// The raw HTML editor must escape stored HTML until the Sanitizer API parses it.
$adminJavascript = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin.js');
$postFormTemplate = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php');
assertTrue(
    str_contains($adminJavascript, 'visual.setHTML(raw.value, { sanitizer: createEditorSanitizer() })')
        && !preg_match('/visual\s*\.\s*innerHTML\s*=\s*raw\s*\.\s*value/', $adminJavascript)
        && str_contains($adminJavascript, "attributes: ['href', 'title']")
        && str_contains($adminJavascript, 'typeof visual.setHTML !== \'function\'')
        && str_contains($adminJavascript, 'initializePostContentEditor()')
        && str_contains($postFormTemplate, 'htmlspecialchars($post->content')
        && !str_contains($postFormTemplate, '><?= $post->content ??')
        && str_contains($postFormTemplate, '></div>')
        && str_contains($postFormTemplate, 'id="visual-editor"')
        && str_contains($postFormTemplate, 'style="display:none;"'),
    'Stored rich HTML is escaped in the source textarea and parsed into the visual editor only with the safe Sanitizer API'
);
$post = new \App\Domain\Entity\Post(
    id: 902,
    title: 'Rich HTML sanitizer regression',
    slug: 'rich-html-sanitizer-regression',
    excerpt: '',
    content: '<p>Định dạng hợp lệ</p><img src="x" onerror="alert(1)"><script>alert(2)</script>',
    featuredImage: '',
    status: ContentStatus::DRAFT,
    categoryId: null,
    categoryName: null,
    authorId: 1,
    authorName: 'Tác giả',
);
$categories = [];
$mediaFiles = [];
$enabledModules = ['categories_tags' => false];
ob_start();
include dirname(__DIR__) . '/src/Presentation/Views/admin/posts/form.php';
$sanitizerRegressionForm = (string) ob_get_clean();
assertTrue(
    str_contains($sanitizerRegressionForm, '&lt;p&gt;Định dạng hợp lệ&lt;/p&gt;&lt;img src=&quot;x&quot; onerror=&quot;alert(1)&quot;&gt;&lt;script&gt;alert(2)&lt;/script&gt;')
        && !str_contains($sanitizerRegressionForm, '<img src="x" onerror="alert(1)">')
        && preg_match('/id="visual-editor"[\s\S]*?><\/div>/', $sanitizerRegressionForm) === 1,
    'Admin post editor safely keeps a malicious rich HTML payload as escaped source text until browser sanitization'
);

// Settings fields must retain visible labels associated with their rendered controls.
$settings = [];
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/settings.php';
$settingsViewMarkup = (string) ob_get_clean();
$settingsLabelPairs = [
    'site-title' => '<label for="site-title">Tên Website / Cửa hàng (Site Title)</label>',
    'site-tagline' => '<label for="site-tagline">Khẩu hiệu (Tagline)</label>',
    'contact-email' => '<label for="contact-email">Email liên hệ</label>',
    'contact-phone' => '<label for="contact-phone">Số điện thoại Hotline</label>',
    'footer-text' => '<label for="footer-text">Nội dung chân trang (Footer Copyright)</label>',
];
$settingsLabelsAssociated = true;
foreach ($settingsLabelPairs as $controlId => $labelMarkup) {
    if (!str_contains($settingsViewMarkup, $labelMarkup)
        || !preg_match('/<input\\b(?=[^>]*\\bid="' . preg_quote($controlId, '/') . '")[^>]*>/', $settingsViewMarkup)
        || substr_count($settingsViewMarkup, 'id="' . $controlId . '"') !== 1) {
        $settingsLabelsAssociated = false;
    }
}
assertTrue(
    $settingsLabelsAssociated
        && preg_match('/<input\b(?=[^>]*\bid="contact-email")(?=[^>]*\btype="email")(?=[^>]*\bautocomplete="email")[^>]*>/', $settingsViewMarkup) === 1
        && str_contains($settingsViewMarkup, '<label class="wp-checkbox-label">')
        && str_contains($settingsViewMarkup, '<input type="checkbox" name="auto_approve_comments"'),
    'Rendered admin settings form associates visible field labels and gives its contact email the matching autocomplete purpose'
);

// The comment type filter must have a visible label associated with its rendered select control.
$comments = [];
$counts = ['all' => 0, 'approved' => 0, 'pending' => 0, 'spam' => 0];
$currentStatus = '';
$currentType = '';
$currentPage = 1;
$totalPages = 1;
$totalItems = 0;
$pageSize = 20;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/comments/index.php';
$commentsViewMarkup = (string) ob_get_clean();
assertTrue(
    str_contains($commentsViewMarkup, '<label for="comment-type-filter">Loại nội dung</label>')
        && str_contains($commentsViewMarkup, '<select id="comment-type-filter" name="type" class="wp-select">'),
    'Rendered admin comment type filter has a visible label explicitly associated with its select control'
);

// Media upload controls must render visible labels associated with the file and alt-text inputs.
$mediaFiles = [];
$currentPage = 1;
$totalPages = 1;
$totalItems = 0;
$pageSize = 20;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/media/index.php';
$mediaViewMarkup = (string) ob_get_clean();
assertTrue(
    str_contains($mediaViewMarkup, '<label for="media-upload-file">Chọn tệp hình ảnh</label>')
        && str_contains($mediaViewMarkup, '<input type="file" id="media-upload-file" name="file"')
        && str_contains($mediaViewMarkup, '<label for="media-upload-alt-text">Văn bản thay thế (không bắt buộc)</label>')
        && str_contains($mediaViewMarkup, '<input type="text" id="media-upload-alt-text" name="alt_text"')
        && substr_count($mediaViewMarkup, 'id="media-upload-file"') === 1
        && substr_count($mediaViewMarkup, 'id="media-upload-alt-text"') === 1,
    'Rendered admin media upload form has visible labels explicitly associated with its file and optional alt-text controls'
);
$mediaHeadingMatches = [];
preg_match_all('/<h([1-6])\b/i', $mediaViewMarkup, $mediaHeadingMatches);
assertTrue(
    array_map('intval', $mediaHeadingMatches[1]) === [1, 2]
        && str_contains($mediaViewMarkup, '<h2>⬆️ Tải tệp hình ảnh mới lên Thư viện</h2>'),
    'Rendered admin media page uses a second-level heading for its upload section after the page title'
);

// The order status control must render a visible label associated with the select.
$order = new \App\Domain\Entity\Order(
    17,
    'ORD-17',
    'Khách hàng',
    'customer@example.test',
    '0900000000',
    'Địa chỉ giao hàng',
    '',
    125000.0,
    \App\Domain\Enum\OrderStatus::PENDING
);
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/orders/detail.php';
$orderDetailViewMarkup = (string) ob_get_clean();
assertTrue(
    str_contains($orderDetailViewMarkup, '<label for="order-status">Trạng thái hiện tại</label>')
        && str_contains($orderDetailViewMarkup, '<select id="order-status" name="status" class="wp-select">')
        && substr_count($orderDetailViewMarkup, 'id="order-status"') === 1,
    'Rendered admin order status selector has a unique ID associated with its visible label'
);

// Quick-draft fields must retain visible labels associated with their rendered controls.
$modules = [];
$postCounts = [];
$productCounts = [];
$orderStats = [];
$commentCounts = [];
$recentPosts = [];
$recentProducts = [];
$recentOrders = [];
$enabledModules = ['posts' => true, 'products' => false, 'orders' => false];
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/dashboard.php';
$dashboardViewMarkup = (string) ob_get_clean();
assertTrue(
    str_contains($dashboardViewMarkup, '<label for="quick-draft-title">Tiêu đề bài viết</label>')
        && str_contains($dashboardViewMarkup, '<input type="text" id="quick-draft-title" name="title"')
        && str_contains($dashboardViewMarkup, '<label for="quick-draft-content">Nội dung ghi chú nhanh</label>')
        && str_contains($dashboardViewMarkup, '<textarea id="quick-draft-content" name="content"'),
    'Rendered admin quick-draft fields have visible labels explicitly associated with the title input and content textarea'
);

// Harness controls must render visible labels explicitly associated with each input.
$tasks = [];
$taskProgress = [];
$agents = [];
$snapshots = [];
$gateReport = ['gates' => []];
$continuousState = [
    'enabled' => false,
    'daemon_alive' => false,
    'interval_seconds' => 5,
    'auto_discover' => true,
    'auto_heal_on_drift' => true,
    'recent_events' => [],
    'last_heartbeat' => null,
];
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/harness/index.php';
$harnessViewMarkup = (string) ob_get_clean();
$continuousBadgeRenderedOnce = substr_count($harnessViewMarkup, 'id="continuous-badge"') === 1
    && str_contains($harnessViewMarkup, '<span id="continuous-badge" class="wp-status-badge badge-secondary">⚪ STOPPED</span>')
    && !str_contains($harnessViewMarkup, '🟢 RUNNING (CONTINUOUS)');
$continuousState['enabled'] = true;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/harness/index.php';
$enabledHarnessViewMarkup = (string) ob_get_clean();
$continuousBadgeRenderedOnce = $continuousBadgeRenderedOnce
    && substr_count($enabledHarnessViewMarkup, 'id="continuous-badge"') === 1
    && str_contains($enabledHarnessViewMarkup, '<span id="continuous-badge" class="wp-status-badge badge-success">🟢 RUNNING (CONTINUOUS)</span>')
    && !str_contains($enabledHarnessViewMarkup, '⚪ STOPPED');
assertTrue(
    $continuousBadgeRenderedOnce,
    'Harness continuous badge ID is unique in rendered markup for both enabled and disabled states'
);

$harnessLabelPairs = [
    'continuous-interval' => '<select name="interval_seconds" id="continuous-interval"',
    'task-action-type' => '<select name="action_type" id="task-action-type"',
    'task-target-name' => '<input type="text" name="target_name" id="task-target-name"',
    'task-title' => '<input type="text" name="title" id="task-title"',
    'task-agent-backend' => '<select name="agent_backend" id="task-agent-backend"',
    'task-max-attempts' => '<input type="number" name="max_attempts" id="task-max-attempts"',
    'task-specification' => '<textarea name="specification" id="task-specification"',
];
$harnessLabelsAssociated = true;
foreach ($harnessLabelPairs as $controlId => $controlMarkup) {
    if (!str_contains($harnessViewMarkup, '<label for="' . $controlId . '">')
        && !str_contains($harnessViewMarkup, '<label for="' . $controlId . '" style=')) {
        $harnessLabelsAssociated = false;
    }
    if (!str_contains($harnessViewMarkup, $controlMarkup)) {
        $harnessLabelsAssociated = false;
    }
}
assertTrue(
    $harnessLabelsAssociated,
    'Rendered Harness form labels explicitly reference the IDs of all seven identified controls'
);

// Render the admin layout and ensure its new-tab homepage link opts out of opener access.
$pageTitle = 'Dashboard';
$content = '<main>Dashboard content</main>';
$enabledModules = [];
$settings = ['site_title' => 'Example CMS'];
$currentUser = ['display_name' => 'Admin'];
$flash = null;
$pendingCommentsCount = 0;
$pendingOrdersCount = 0;
$currentUri = '/admin';
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/layouts/admin.php';
$adminLayoutMarkup = (string) ob_get_clean();
$adminHeadMarkup = preg_match('~<head\b[^>]*>(.*?)</head>~is', $adminLayoutMarkup, $adminHeadMatch) === 1
    ? $adminHeadMatch[1]
    : '';
assertTrue(
    substr_count($adminLayoutMarkup, '<meta name="description"') === 1
        && str_contains(
            $adminHeadMarkup,
            '<meta name="description" content="Bảng điều khiển quản trị WP-Hexagon CMS để quản lý nội dung và vận hành website.">'
        ),
    'Rendered admin layout includes one non-empty description of the CMS management dashboard inside its head'
);
assertTrue(
    str_contains($adminLayoutMarkup, '<a href="/" target="_blank" rel="noopener" class="wp-topbar-link">')
        && str_contains($adminLayoutMarkup, 'Xem trang chủ: <strong>Example CMS</strong>'),
    'Rendered admin homepage link uses target blank with explicit noopener while preserving its label and site title'
);

// Render the frontend layout with page-specific and fallback descriptions.
$pageTitle = 'Bài viết "A&B"';
$metaDescription = '  Tóm tắt <nội dung> & chi tiết  ';
$content = '<main>Page content</main>';
$settings = ['site_title' => 'Example CMS', 'site_tagline' => 'Hệ thống & cửa hàng'];
$currentUser = null;
$flash = null;
$cartCount = 0;
$currentUri = '/post/example';
$adminEditUrl = null;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/layouts/frontend.php';
$frontendLayoutMarkup = (string) ob_get_clean();
$frontendHeadMarkup = preg_match('~<head\b[^>]*>(.*?)</head>~is', $frontendLayoutMarkup, $frontendHeadMatch) === 1
    ? $frontendHeadMatch[1]
    : '';
assertTrue(
    substr_count($frontendLayoutMarkup, '<meta name="description"') === 1
        && str_contains(
            $frontendHeadMarkup,
            '<meta name="description" content="Tóm tắt &lt;nội dung&gt; &amp; chi tiết">'
        ),
    'Rendered frontend layout includes one non-empty page-specific description in head and escapes its content'
);

$pageTitle = 'Trang chủ';
$metaDescription = '';
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/layouts/frontend.php';
$frontendFallbackMarkup = (string) ob_get_clean();
assertTrue(
    substr_count($frontendFallbackMarkup, '<meta name="description"') === 1
        && str_contains(
            $frontendFallbackMarkup,
            '<meta name="description" content="Trang chủ — Hệ thống &amp; cửa hàng">'
        ),
    'Rendered frontend layout supplies a non-empty escaped fallback description when page metadata is absent'
);

$pageTitle = '';
$metaDescription = '';
$settings = ['site_title' => 'Example CMS', 'site_tagline' => ''];
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/layouts/frontend.php';
$frontendMinimumFallbackMarkup = (string) ob_get_clean();
assertTrue(
    str_contains(
        $frontendMinimumFallbackMarkup,
        '<meta name="description" content="Trang chủ">'
    ),
    'Rendered frontend layout keeps the description non-empty when title and tagline are both blank'
);

$blogControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Frontend/BlogController.php');
$shopControllerSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Controller/Frontend/ShopController.php');
assertTrue(
    str_contains($blogControllerSource, "'metaDescription' => \$post->excerpt")
        && str_contains($shopControllerSource, "'metaDescription' => \$product->shortDescription"),
    'Frontend article and product detail pages use their own excerpt and short description as metadata'
);

// The login page description should render once inside its head with page-specific text.
$error = null;
ob_start();
require dirname(__DIR__) . '/src/Presentation/Views/admin/login.php';
$loginPageMarkup = (string) ob_get_clean();
$loginHeadMarkup = preg_match('~<head\b[^>]*>(.*?)</head>~is', $loginPageMarkup, $loginHeadMatch) === 1
    ? $loginHeadMatch[1]
    : '';
assertTrue(
    substr_count($loginPageMarkup, '<meta name="description"') === 1
        && str_contains(
            $loginHeadMarkup,
            '<meta name="description" content="Đăng nhập vào bảng điều khiển quản trị WP-Hexagon CMS để quản lý nội dung website.">'
        ),
    'Rendered admin login page includes one non-empty, page-specific meta description inside its head'
);

$harnessViewSource = (string) file_get_contents(dirname(__DIR__) . '/src/Presentation/Views/admin/harness/index.php');
preg_match_all('/<h([1-6])\b/i', $harnessViewSource, $harnessHeadingMatches);
assertTrue(
    array_map('intval', $harnessHeadingMatches[1]) === [1, 2, 2, 2, 2, 2],
    'Harness page section headings follow the page title without skipping a heading rank'
);

echo "\n🎉 ALL HEXAGONAL ARCHITECTURE, MODULE TOGGLE & TUZYGENT HARNESS (INCLUDING CONTINUOUS MODE) TESTS PASSED!\n";
