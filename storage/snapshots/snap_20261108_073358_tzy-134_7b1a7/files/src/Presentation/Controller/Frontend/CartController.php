<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\OrderService;
use App\Domain\Port\Outbound\CartPort;
use App\Presentation\Controller\BaseController;

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
        $productId = (int) ($_POST['product_id'] ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        if ($productId > 0) {
            $this->container->get(CartPort::class)->add($productId, $quantity);
            $this->flash('success', 'Đã thêm sản phẩm vào giỏ hàng!');
        }
        $redirectTo = (string) ($_POST['redirect_to'] ?? '/cart');
        $this->redirect($redirectTo);
    }

    public function update(): void
    {
        $cart = $this->container->get(CartPort::class);
        $quantities = $_POST['quantities'] ?? [];
        if (is_array($quantities)) {
            foreach ($quantities as $productId => $qty) {
                $cart->update((int) $productId, (int) $qty);
            }
        }
        $this->flash('success', 'Đã cập nhật giỏ hàng.');
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
