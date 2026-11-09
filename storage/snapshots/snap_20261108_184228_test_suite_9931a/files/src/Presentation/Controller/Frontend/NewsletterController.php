<?php

declare(strict_types=1);

namespace App\Presentation\Controller\Frontend;

use App\Application\Service\SubscriberService;
use App\Presentation\Controller\BaseController;
use InvalidArgumentException;

final class NewsletterController extends BaseController
{
    public function subscribe(): void
    {
        $rawEmail = $_POST['email'] ?? '';
        if (!is_string($rawEmail)) {
            $this->flash('error', 'Vui lòng nhập địa chỉ email hợp lệ.');
            $this->redirect('/');
            return;
        }

        try {
            $this->container->get(SubscriberService::class)->subscribe($rawEmail);
            $this->flash('success', 'Cảm ơn bạn đã đăng ký nhận tin.');
        } catch (InvalidArgumentException $exception) {
            $this->flash('error', $exception->getMessage());
        }

        $this->redirect('/');
    }
}
