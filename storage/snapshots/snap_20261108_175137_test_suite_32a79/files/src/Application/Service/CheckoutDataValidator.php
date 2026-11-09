<?php

declare(strict_types=1);

namespace App\Application\Service;

use RuntimeException;

/** Validates and normalizes customer data submitted to the checkout use case. */
final readonly class CheckoutDataValidator
{
    /**
     * @param array<string, mixed> $customerData
     * @return array{customer_name: string, customer_email: string, customer_phone: string, customer_address: string, note: string}
     */
    public function validate(array $customerData): array
    {
        $name = $this->checkoutText($customerData, 'customer_name', 'Họ và tên', 120);
        $email = $this->checkoutText($customerData, 'customer_email', 'Email', 254);
        $phone = $this->checkoutText($customerData, 'customer_phone', 'Số điện thoại', 40);
        $address = $this->checkoutText($customerData, 'customer_address', 'Địa chỉ giao hàng', 500);
        $note = $this->checkoutText($customerData, 'note', 'Ghi chú', 1000, false);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email không đúng định dạng. Vui lòng kiểm tra lại.');
        }

        if (preg_match('/^[0-9+().\s-]+$/u', $phone) !== 1) {
            throw new RuntimeException('Số điện thoại chỉ được chứa chữ số, dấu +, dấu ngoặc, dấu chấm hoặc dấu gạch ngang.');
        }
        $phoneDigits = preg_replace('/\D/', '', $phone);
        $digitCount = is_string($phoneDigits) ? strlen($phoneDigits) : 0;
        if ($digitCount < 7 || $digitCount > 15) {
            throw new RuntimeException('Số điện thoại phải có từ 7 đến 15 chữ số.');
        }

        return [
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone,
            'customer_address' => $address,
            'note' => $note,
        ];
    }

    /** @param array<string, mixed> $customerData */
    private function checkoutText(array $customerData, string $key, string $label, int $maxLength, bool $required = true): string
    {
        $value = $customerData[$key] ?? '';
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('%s không hợp lệ.', $label));
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new RuntimeException(sprintf('Vui lòng nhập %s.', mb_strtolower($label)));
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new RuntimeException(sprintf('%s không được dài quá %d ký tự.', $label, $maxLength));
        }

        return $value;
    }
}
