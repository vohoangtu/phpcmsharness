<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\OrderService;
use App\Application\Service\CartService;
use App\Domain\Port\Outbound\CartPort;
use App\Presentation\Controller\BaseController;
use App\Presentation\Http\LocalRedirect;

final class CartController extends BaseController
{
    public function index(): void
    {
        $orderService = $this->container->get(OrderService::class);
        $this->view->renderFrontend('frontend/shop/cart', [
            'pageTitle' => 'Giỏ hàng & Thanh toán',
            'cartData' => $orderService->getCartDetailed(),
        ]);
    }

    public function add(): void
    {
        try {
            $this->container->get(CartService::class)->addItem(
                $_POST['product_id'] ?? null,
                $_POST['quantity'] ?? 1
            );
            $this->flash('success', 'Đã thêm sản phẩm vào giỏ hàng!');
        } catch (\RuntimeException $error) {
            $this->flash('error', $error->getMessage());
        }
        $redirectTo = LocalRedirect::resolve((string) ($_POST['redirect_to'] ?? '/cart'));
        $this->redirect($redirectTo);
    }

    public function update(): void
    {
        $quantities = $_POST['quantities'] ?? [];
        try {
            if (!is_array($quantities)) {
                throw new \RuntimeException('Dữ liệu cập nhật giỏ hàng không hợp lệ.');
            }
            $this->container->get(CartService::class)->updateItems($quantities);
            $this->flash('success', 'Đã cập nhật giỏ hàng.');
        } catch (\RuntimeException $error) {
            $this->flash('error', $error->getMessage());
        }
        $this->redirect('/cart');
    }

    public function remove(string $productId): void
    {
        $this->container->get(CartPort::class)->remove((int) $productId);
        $this->flash('success', 'Đã xóa sản phẩm khỏi giỏ hàng.');
        $this->redirect('/cart');
    }

    public function checkout(): void
    {
        $orderService = $this->container->get(OrderService::class);
        try {
            $order = $orderService->checkout($_POST);
            $this->view->renderFrontend('frontend/shop/order_success', [
                'pageTitle' => 'Đặt hàng thành công',
                'order' => $order,
            ]);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/cart');
        }
    }
}
