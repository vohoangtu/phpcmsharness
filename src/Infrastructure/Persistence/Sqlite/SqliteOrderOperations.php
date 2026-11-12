<?php
declare(strict_types=1);
namespace App\Infrastructure\Persistence\Sqlite;
use App\Domain\Port\Outbound\OrderOperationsPort;
use PDO;
use RuntimeException;
use Throwable;

final readonly class SqliteOrderOperations implements OrderOperationsPort
{
    public function __construct(private PDO $pdo) {}
    public function netPaid(int $orderId): int
    {
        $s = $this->pdo->prepare("SELECT COALESCE(SUM(CASE kind WHEN 'payment' THEN amount_vnd ELSE -amount_vnd END),0) FROM order_payment_transactions WHERE order_id=?");
        $s->execute([$orderId]); return (int) $s->fetchColumn();
    }
    private function rows(string $sql, int $id): array { $s=$this->pdo->prepare($sql); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    public function paymentDocument(int $transactionId): ?array
    {
        $s = $this->pdo->prepare('SELECT p.*, o.order_code, o.customer_name, o.customer_phone, o.customer_email, o.customer_address FROM order_payment_transactions p JOIN orders o ON o.id=p.order_id WHERE p.id=?');
        $s->execute([$transactionId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return $this->decoratePaymentDocument($row);
    }
    private function decoratePaymentDocument(array $row): array
    {
        $date = preg_replace('/[^0-9]/', '', substr((string) $row['created_at'], 0, 10));
        $prefix = $row['kind'] === 'payment' ? 'PT' : 'PC';
        $row['document_code'] = $prefix . '-' . $date . '-' . str_pad((string) $row['id'], 6, '0', STR_PAD_LEFT);
        $row['document_title'] = $row['kind'] === 'payment' ? 'PHIẾU THU' : 'PHIẾU CHI';
        return $row;
    }
    public function paymentLedger(string $kind, string $query, string $from, string $to, int $limit, int $offset): array
    {
        $conditions = [];
        $params = [];
        if ($query !== '') {
            $conditions[] = '(o.order_code LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR p.reference LIKE ? OR p.method LIKE ?)';
            $needle = '%' . $query . '%';
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        if ($from !== '') { $conditions[] = 'p.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
        if ($to !== '') { $conditions[] = 'p.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
        $summaryWhere = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $listConditions = $conditions;
        $listParams = $params;
        if (in_array($kind, ['payment', 'refund'], true)) { $listConditions[] = 'p.kind=?'; $listParams[] = $kind; }
        $listWhere = $listConditions ? ' WHERE ' . implode(' AND ', $listConditions) : '';
        $join = ' FROM order_payment_transactions p JOIN orders o ON o.id=p.order_id';
        $aggregate = $this->pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN p.kind='payment' THEN p.amount_vnd ELSE 0 END),0) AS received, COALESCE(SUM(CASE WHEN p.kind='refund' THEN p.amount_vnd ELSE 0 END),0) AS paid_out" . $join . $summaryWhere);
        $aggregate->execute($params);
        $summary = $aggregate->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'received'=>0,'paid_out'=>0];
        $count = $this->pdo->prepare('SELECT COUNT(*)' . $join . $listWhere);
        $count->execute($listParams);
        $filteredTotal = (int) $count->fetchColumn();
        $s = $this->pdo->prepare('SELECT p.*,o.order_code,o.customer_name,o.customer_phone' . $join . $listWhere . ' ORDER BY p.id DESC LIMIT ? OFFSET ?');
        $s->execute([...$listParams, max(1, $limit), max(0, $offset)]);
        $rows = array_map(fn(array $row): array => $this->decoratePaymentDocument($row), $s->fetchAll(PDO::FETCH_ASSOC));
        $summary = array_map('intval', $summary);
        $summary['net'] = $summary['received'] - $summary['paid_out'];
        $summary['total'] = $filteredTotal;
        return ['rows'=>$rows, 'summary'=>$summary];
    }
    public function details(int $orderId): array
    {
        return ['payments'=>$this->rows('SELECT * FROM order_payment_transactions WHERE order_id=? ORDER BY id DESC',$orderId), 'shipments'=>$this->rows('SELECT * FROM order_shipments WHERE order_id=? ORDER BY id DESC',$orderId), 'adjustments'=>$this->rows('SELECT * FROM order_adjustments WHERE order_id=? ORDER BY id DESC',$orderId), 'invoice'=>$this->rows('SELECT * FROM order_invoice_requests WHERE order_id=?',$orderId)[0] ?? null, 'activity'=>$this->rows('SELECT * FROM order_activity WHERE order_id=? ORDER BY id DESC',$orderId), 'net_paid'=>$this->netPaid($orderId)];
    }
    public function addActivity(int $orderId, string $actor, string $event, string $detail): void
    {
        $s=$this->pdo->prepare('INSERT INTO order_activity(order_id,actor,event,detail,created_at) VALUES(?,?,?,?,?)'); $s->execute([$orderId,$actor,$event,$detail,date('Y-m-d H:i:s')]);
    }
    private function transaction(callable $fn): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $fn(); $this->pdo->commit(); } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    private function order(int $id): array
    {
        $s=$this->pdo->prepare('SELECT status,total_amount FROM orders WHERE id=?'); $s->execute([$id]); $row=$s->fetch(PDO::FETCH_ASSOC); if (!$row) throw new RuntimeException('Không tìm thấy đơn hàng.'); return $row;
    }
    private function text(array $data,string $key,int $max=255): string { return mb_substr(trim((string)($data[$key] ?? '')),0,$max); }
    public function recordPayment(int $orderId, array $data, string $actor): void
    {
        $kind=(string)($data['kind'] ?? ''); $raw=trim((string)($data['amount'] ?? '')); $method=$this->text($data,'method',80);
        if (!in_array($kind,['payment','refund'],true) || !preg_match('/^[1-9][0-9]{0,11}$/D',$raw) || $method==='') throw new RuntimeException('Vui lòng nhập loại, phương thức và số tiền nguyên dương hợp lệ.');
        $amount=(int)$raw; $reference=$this->text($data,'reference'); $note=$this->text($data,'note',1000);
        $this->transaction(function () use ($orderId,$actor,$kind,$method,$amount,$reference,$note): void {
            $order=$this->order($orderId); $net=$this->netPaid($orderId); $total=(int)round((float)$order['total_amount']);
            if ($kind==='payment' && ($order['status']==='cancelled' || $amount > $total-$net)) throw new RuntimeException('Không thể thu vượt số tiền còn phải thu hoặc thu cho đơn đã hủy.');
            if ($kind==='refund' && $amount>$net) throw new RuntimeException('Không thể hoàn vượt số tiền đã thu.');
            $now=date('Y-m-d H:i:s'); $s=$this->pdo->prepare('INSERT INTO order_payment_transactions(order_id,kind,amount_vnd,method,reference,note,occurred_at,actor,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $s->execute([$orderId,$kind,$amount,$method,$reference,$note,$now,$actor,$now]); $this->addActivity($orderId,$actor,$kind==='payment'?'Ghi nhận thu tiền':'Ghi nhận hoàn tiền',number_format($amount,0,',','.') . ' ₫ · ' . $method);
        });
    }
    public function saveShipment(int $orderId, array $data, string $actor): void
    {
        $status=(string)($data['status'] ?? 'pending'); $allowed=['pending','awaiting_pickup','in_transit','delivered','failed','returned'];
        $fee=trim((string)($data['shipping_fee'] ?? '0')); $id=(int)($data['shipment_id'] ?? 0);
        if (!in_array($status,$allowed,true) || !preg_match('/^(0|[1-9][0-9]{0,11})$/D',$fee)) throw new RuntimeException('Trạng thái hoặc phí vận chuyển không hợp lệ.');
        $carrier=$this->text($data,'carrier'); $tracking=$this->text($data,'tracking_code'); $trackingUrl=$this->text($data,'tracking_url',1000); $expected=$this->text($data,'expected_at',10); $note=$this->text($data,'note',1000);
        if ($trackingUrl!=='' && (!filter_var($trackingUrl,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($trackingUrl,PHP_URL_SCHEME)),['http','https'],true))) throw new RuntimeException('Liên kết theo dõi phải là URL HTTP hoặc HTTPS hợp lệ.');
        if ($expected!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$expected) || !checkdate((int)substr($expected,5,2),(int)substr($expected,8,2),(int)substr($expected,0,4)))) throw new RuntimeException('Ngày giao dự kiến không hợp lệ.');
        $this->transaction(function () use ($orderId,$id,$actor,$carrier,$tracking,$trackingUrl,$fee,$status,$expected,$note): void {
            $order=$this->order($orderId); if ($order['status']==='cancelled') throw new RuntimeException('Không thể cập nhật vận đơn của đơn đã hủy.'); $now=date('Y-m-d H:i:s');
            if ($id>0) { $s=$this->pdo->prepare('UPDATE order_shipments SET carrier=?,tracking_code=?,tracking_url=?,shipping_fee_vnd=?,status=?,expected_at=?,note=?,updated_at=? WHERE id=? AND order_id=?'); $s->execute([$carrier,$tracking,$trackingUrl,(int)$fee,$status,$expected,$note,$now,$id,$orderId]); if (!$s->rowCount()) throw new RuntimeException('Không tìm thấy vận đơn.'); }
            else { $s=$this->pdo->prepare('INSERT INTO order_shipments(order_id,carrier,tracking_code,tracking_url,shipping_fee_vnd,status,expected_at,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)'); $s->execute([$orderId,$carrier,$tracking,$trackingUrl,(int)$fee,$status,$expected,$note,$now,$now]); }
            $this->addActivity($orderId,$actor,'Cập nhật vận đơn',trim($carrier.' '.$tracking.' · '.$status));
        });
    }
    public function saveInvoice(int $orderId, array $data, string $actor): void
    {
        $status=(string)($data['status'] ?? 'requested'); if (!in_array($status,['not_requested','requested','processing','issued'],true)) throw new RuntimeException('Trạng thái hóa đơn không hợp lệ.');
        $buyer=$this->text($data,'buyer_name'); $tax=$this->text($data,'tax_code',50); $address=$this->text($data,'address',500); $email=$this->text($data,'email'); $reference=$this->text($data,'reference');
        if ($status==='issued' && $reference==='') throw new RuntimeException('Cần mã tham chiếu khi ghi nhận hóa đơn đã phát hành.');
        if ($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email hóa đơn không hợp lệ.');
        $this->transaction(function () use ($orderId,$actor,$buyer,$tax,$address,$email,$status,$reference): void {
            $this->order($orderId); $s=$this->pdo->prepare('INSERT INTO order_invoice_requests(order_id,buyer_name,tax_code,address,email,status,reference,updated_at) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(order_id) DO UPDATE SET buyer_name=excluded.buyer_name,tax_code=excluded.tax_code,address=excluded.address,email=excluded.email,status=excluded.status,reference=excluded.reference,updated_at=excluded.updated_at'); $s->execute([$orderId,$buyer,$tax,$address,$email,$status,$reference,date('Y-m-d H:i:s')]); $this->addActivity($orderId,$actor,'Cập nhật yêu cầu hóa đơn',$status . ($reference!==''?' · '.$reference:''));
        });
    }
    public function addAdjustment(int $orderId, array $data, string $actor): void
    {
        $raw=trim((string)($data['amount'] ?? '')); $reason=$this->text($data,'reason',500);
        if (!preg_match('/^-?[1-9][0-9]{0,11}$/D',$raw) || $reason==='') throw new RuntimeException('Cần nhập số tiền điều chỉnh khác 0 và lý do.');
        $amount=(int)$raw;
        $this->transaction(function () use ($orderId,$actor,$amount,$reason): void {
            $order=$this->order($orderId); if ($order['status']==='cancelled') throw new RuntimeException('Không thể điều chỉnh đơn đã hủy.');
            $newTotal=(int)round((float)$order['total_amount'])+$amount;
            if ($newTotal < 0 || $newTotal < $this->netPaid($orderId)) throw new RuntimeException('Tổng mới không được âm hoặc nhỏ hơn tiền đã thu ròng.');
            $s=$this->pdo->prepare('UPDATE orders SET total_amount=? WHERE id=?'); $s->execute([$newTotal,$orderId]);
            $s=$this->pdo->prepare('INSERT INTO order_adjustments(order_id,amount_vnd,reason,actor,created_at) VALUES(?,?,?,?,?)'); $s->execute([$orderId,$amount,$reason,$actor,date('Y-m-d H:i:s')]);
            $this->addActivity($orderId,$actor,'Điều chỉnh tổng đơn',number_format($amount,0,',','.') . ' ₫ · ' . $reason);
        });
    }
}
