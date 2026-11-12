<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Infrastructure\Container\Container;
use App\Domain\Port\Outbound\OrderOperationsPort;
use App\Application\Service\OrderService;
use App\Domain\Enum\OrderStatus;

$file=tempnam(sys_get_temp_dir(),'order-operations-');
if ($file===false) throw new RuntimeException('Cannot create database');
$assert=static function(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); echo "PASSED: $message\n"; };
$reject=static function(callable $fn,string $message) use ($assert): void { try {$fn();} catch (RuntimeException) {$assert(true,$message); return;} throw new RuntimeException('Not rejected: '.$message); };
try {
    $config=['db'=>['driver'=>'sqlite','sqlite_path'=>$file], 'base_path'=>dirname(__DIR__)];
    $c=new Container($config); $pdo=$c->get(PDO::class); $ops=$c->get(OrderOperationsPort::class); $service=$c->get(OrderService::class);
    $pdo->exec("INSERT INTO orders(order_code,customer_name,customer_email,customer_phone,customer_address,note,total_amount,status,created_at) VALUES('TEST-ORDER','Name','a@example.test','0900000000','Address','',1000,'pending','2026-01-01')"); $id=(int)$pdo->lastInsertId();
    $product=(int)$pdo->query('SELECT id FROM products LIMIT 1')->fetchColumn();
    $stmt=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity,subtotal) VALUES(?,?,'Item',1000,1,1000)"); $stmt->execute([$id,$product]);
    $stock=(int)$pdo->query('SELECT stock_quantity FROM products WHERE id='.$product)->fetchColumn();
    $pdo->exec('CREATE TRIGGER reject_order_activity BEFORE INSERT ON order_activity BEGIN SELECT RAISE(ABORT, "simulated activity failure"); END');
    $reject(fn()=>$ops->recordPayment($id,['kind'=>'payment','amount'=>'100','method'=>'COD'],'admin'),'payment fails if audit recording fails');
    $assert($ops->netPaid($id)===0,'payment insertion rolls back with failed activity');
    $pdo->exec('DROP TRIGGER reject_order_activity');
    $ops->recordPayment($id,['kind'=>'payment','amount'=>'400','method'=>'COD'],'admin');
    $ops->recordPayment($id,['kind'=>'payment','amount'=>'600','method'=>'bank'],'admin');
    $receipt=$ops->paymentDocument((int)$pdo->query('SELECT id FROM order_payment_transactions WHERE order_id='.$id.' AND kind="payment" ORDER BY id LIMIT 1')->fetchColumn());
    $assert($receipt !== null && $receipt['document_code']==='PT-'.date('Ymd',strtotime($receipt['created_at'])).'-'.str_pad((string)$receipt['id'],6,'0',STR_PAD_LEFT) && $receipt['order_code']==='TEST-ORDER','receipt document number and order details are stable');
    $document=$receipt; ob_start(); require dirname(__DIR__) . '/src/Presentation/Views/admin/orders/payment-document.php'; $receiptMarkup=(string)ob_get_clean(); unset($document);
    $assert(str_contains($receiptMarkup,'PHIẾU THU') && str_contains($receiptMarkup,'CHỨNG TỪ NỘI BỘ'),'receipt print view renders as internal voucher');
    $assert($ops->netPaid($id)===1000,'multiple payments persist');
    $ledger=$ops->paymentLedger('', 'TEST-ORDER', '', '', 25, 0);
    $assert($ledger['summary']['total']===2 && $ledger['summary']['received']===1000 && $ledger['summary']['paid_out']===0 && count($ledger['rows'])===2,'cashbook search and receipt totals are filtered by order');
    $reject(fn()=>$ops->recordPayment($id,['kind'=>'payment','amount'=>'1','method'=>'COD'],'admin'),'overpayment rejected');
    $reject(fn()=>$service->updateOrderStatus($id,OrderStatus::CANCELLED,'admin'),'cancellation requires full refund');
    $ops->recordPayment($id,['kind'=>'refund','amount'=>'400','method'=>'bank'],'admin');
    $expense=$ops->paymentDocument((int)$pdo->query('SELECT id FROM order_payment_transactions WHERE order_id='.$id.' AND kind="refund" ORDER BY id DESC LIMIT 1')->fetchColumn());
    $assert($expense !== null && str_starts_with($expense['document_code'],'PC-') && $expense['document_title']==='PHIẾU CHI','refund produces disbursement voucher');
    $ledger=$ops->paymentLedger('refund', '', '', '', 25, 0);
    $assert($ledger['summary']['total']===1 && $ledger['summary']['paid_out']===400 && $ledger['summary']['net']===600,'cashbook separates refunds from receipts');
    $reject(fn()=>$ops->recordPayment($id,['kind'=>'refund','amount'=>'601','method'=>'bank'],'admin'),'refund cannot exceed net payments');
    $reject(fn()=>$ops->addAdjustment($id,['amount'=>'-500','reason'=>'discount'],'admin'),'adjustment cannot lower total below net paid');
    $ops->addAdjustment($id,['amount'=>'200','reason'=>'fee'],'admin');
    $ops->saveShipment($id,['carrier'=>'Carrier','tracking_code'=>'ABC','tracking_url'=>'https://example.test/track/ABC','status'=>'in_transit','shipping_fee'=>'200'],'admin');
    $reject(fn()=>$ops->saveShipment($id,['tracking_url'=>'javascript:alert(1)','status'=>'pending','shipping_fee'=>'0'],'admin'),'unsafe tracking URL rejected');
    $ops->saveShipment($id,['carrier'=>'Other','tracking_code'=>'XYZ','status'=>'pending','shipping_fee'=>'0'],'admin');
    $reject(fn()=>$ops->saveInvoice($id,['status'=>'issued'],'admin'),'issued invoice requires reference');
    $ops->saveInvoice($id,['status'=>'requested','buyer_name'=>'Company'],'admin');
    $detail=$ops->details($id);
    $assert(count($detail['shipments'])===2 && $detail['shipments'][1]['tracking_url']==='https://example.test/track/ABC' && count($detail['adjustments'])===1 && $detail['invoice']['status']==='requested','shipment, adjustment and invoice records persist');
    $order=$service->getOrderById($id); $operations=$detail;
    ob_start(); require dirname(__DIR__) . '/src/Presentation/Views/admin/orders/detail.php'; $markup=(string)ob_get_clean();
    $assert(str_contains($markup,'Thanh toán') && str_contains($markup,'ABC') && str_contains($markup,'Company') && str_contains($markup,'_csrf_token'), 'order detail renders operations and CSRF fields');
    $ledger=$ops->paymentLedger('', '', '', '', 25, 0); $rows=$ledger['rows']; $summary=$ledger['summary']; $kind=''; $query=''; $from=''; $to=''; $page=1; $pageSize=25; $totalPages=1;
    ob_start(); require dirname(__DIR__) . '/src/Presentation/Views/admin/orders/cashbook.php'; $cashbookMarkup=(string)ob_get_clean();
    $assert(str_contains($cashbookMarkup,'Sổ thu chi') && str_contains($cashbookMarkup,'PHIẾU THU') && str_contains($cashbookMarkup,'PHIẾU CHI'),'cashbook page lists both document types and print actions');
    $reject(fn()=>$service->updateOrderStatus($id,OrderStatus::COMPLETED,'admin'),'order status cannot skip processing');
    $ops->recordPayment($id,['kind'=>'refund','amount'=>'600','method'=>'bank'],'admin');
    $service->updateOrderStatus($id,OrderStatus::CANCELLED,'admin');
    $service->updateOrderStatus($id,OrderStatus::CANCELLED,'admin');
    $after=(int)$pdo->query('SELECT stock_quantity FROM products WHERE id='.$product)->fetchColumn();
    $assert($after===$stock+1 && $ops->netPaid($id)===0,'cancellation returns stock once after refund');
    $reject(fn()=>$ops->recordPayment($id,['kind'=>'payment','amount'=>'1','method'=>'COD'],'admin'),'cancelled order cannot receive payment');
    $assert(count($ops->details($id)['activity'])>=7,'activity records persist');
    $pdo->exec("INSERT INTO orders(order_code,customer_name,customer_email,customer_phone,customer_address,note,total_amount,status,created_at) VALUES('TEST-RACE','Name','a@example.test','0900000000','Address','',1000,'pending','2026-01-01')");
    $raceId = (int) $pdo->lastInsertId();
    $code = 'require $argv[1]."/vendor/autoload.php"; $p=new PDO("sqlite:".$argv[2],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $p->exec("PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON"); try {(new App\\Infrastructure\\Persistence\\Sqlite\\SqliteOrderOperations($p))->recordPayment((int)$argv[3],["kind"=>"payment","amount"=>"800","method"=>"bank"],"test"); echo "ok";} catch (Throwable $e) {echo "rejected";}';
    $children=[];
    for ($i=0;$i<2;$i++) { $pipes=[]; $proc=proc_open([PHP_BINARY,'-r',$code,dirname(__DIR__),$file,(string)$raceId],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if (!is_resource($proc)) throw new RuntimeException('Cannot start concurrency test'); fclose($pipes[0]); $children[]=[$proc,$pipes]; }
    $out=[]; foreach ($children as [$proc,$pipes]) { $out[]=trim((string)stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($proc)!==0) throw new RuntimeException((string)$err); }
    sort($out); $assert($out===['ok','rejected'] && $ops->netPaid($raceId)===800,'concurrent payments cannot exceed order total');
    $pdo->exec("INSERT INTO orders(order_code,customer_name,customer_email,customer_phone,customer_address,note,total_amount,status,created_at) VALUES('TEST-CANCEL-RACE','Name','a@example.test','0900000000','Address','',1000,'pending','2026-01-01')");
    $cancelRaceId=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare("INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity,subtotal) VALUES(?,?,'Item',1000,1,1000)"); $stmt->execute([$cancelRaceId,$product]);
    $beforeRace=(int)$pdo->query('SELECT stock_quantity FROM products WHERE id='.$product)->fetchColumn();
    $cancelCode='require $argv[1]."/vendor/autoload.php"; $c=new App\\Infrastructure\\Container\\Container(["db"=>["driver"=>"sqlite","sqlite_path"=>$argv[2]],"base_path"=>$argv[1]]); try {$c->get(App\\Application\\Service\\OrderService::class)->updateOrderStatus((int)$argv[3],App\\Domain\\Enum\\OrderStatus::CANCELLED,"test"); echo "ok";} catch (Throwable $e) {echo "rejected";}';
    $children=[]; for ($i=0;$i<2;$i++) { $pipes=[]; $proc=proc_open([PHP_BINARY,'-r',$cancelCode,dirname(__DIR__),$file,(string)$cancelRaceId],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if (!is_resource($proc)) throw new RuntimeException('Cannot start cancellation test'); fclose($pipes[0]); $children[]=[$proc,$pipes]; }
    $out=[]; foreach ($children as [$proc,$pipes]) { $out[]=trim((string)stream_get_contents($pipes[1])); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($proc)!==0) throw new RuntimeException((string)$err); }
    $afterRace=(int)$pdo->query('SELECT stock_quantity FROM products WHERE id='.$product)->fetchColumn();
    $assert(in_array('ok',$out,true) && $afterRace===$beforeRace+1 && $service->getOrderById($cancelRaceId)?->status===OrderStatus::CANCELLED,'concurrent cancellations restore stock once');
    $again=new Container($config); $assert($again->get(OrderOperationsPort::class)->netPaid($id)===0,'migration can run repeatedly on existing database');
    $pdo->exec('DROP TABLE order_payment_transactions; DROP TABLE order_invoice_requests; DROP TABLE order_activity; DROP TABLE order_adjustments; DROP TABLE order_shipments');
    $pdo->exec("CREATE TABLE order_shipments(id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, carrier TEXT NOT NULL DEFAULT '', tracking_code TEXT NOT NULL DEFAULT '', shipping_fee_vnd INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', expected_at TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("INSERT INTO order_shipments(id,order_id,carrier,created_at,updated_at) VALUES(1,{$id},'Legacy','2026-01-01','2026-01-01')");
    $migrated=new Container($config); $legacy=$migrated->get(OrderOperationsPort::class)->details($id);
    $assert($service->getOrderById($id)?->totalAmount===1200.0 && $legacy['shipments'][0]['carrier']==='Legacy' && $legacy['shipments'][0]['tracking_url']==='', 'legacy order and shipment preserved by migration');
} finally { unset($c,$again,$pdo,$ops,$service); @unlink($file); }
